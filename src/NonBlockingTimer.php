<?php
declare(strict_types=1);

namespace BabakBay\NonBlockingTimer;

/**
 * Timer - Non-blocking JavaScript-like setTimeout/setInterval for PHP.
 * 
 * This timer uses a combination of a forked process (or a thread if Swoole v6+
 * is detected) together with POSIX signals to invoke a callback at a specified
 * interval with millisecond-level accuracy — even when the PHP interpreter is
 * suspended (e.g. during database or network queries, or system calls such as
 * sleep(), curl_exec(), fread(), etc).
 * 
 * Unlike timers implemented using event loops or coroutines, this approach does
 * not depend on the PHP event loop being "active". The timer continues to run
 * independently in its own process or thread, ensuring precise timing under
 * blocking conditions.
 * 
 * @author Babak Bayani
 * @license MIT
 */
class Timer
{
    // Configuration
    private const MIN_DURATION_MS = 0.1;
    private const DEFAULT_SIGNAL = SIGUSR2;
    private const SLEEP_INTERVAL_DIVISOR = 10;
    private const MAX_SLEEP_MICRO = 10_000;
    private const MIN_SLEEP_MICRO = 100;
    private const NANOSECONDS_PER_MS = 1_000_000;
    private const MICROSECONDS_PER_MS = 1_000;
    private const POSIX_ERROR_NO_PROCESS = 3; // ESRCH

    // State
    private int $mainProcessPid;
    private int $signalNumber;
    private float $durationMs;
    private bool $callbackQueued = false;
    private bool $isInterval = false;
    private bool $pendCallbacks = false;
    public bool $isActive = false;
    private bool $signalsMasked = false;
    private bool $callbacksEnabled = true;
    
    private $callback;
    private $swooleThread;

    // Static state for managing multiple timers
    private static ?array $timerProcessPids = null;
    public static array $stopFlags = [];

    // Backend detection
    private bool $useSwooleThreads = false;
    private bool $useProcess = false;

    /**
     * Constructor
     * 
     * @param int $signalNumber Signal to use for timer notifications
     * @throws \RuntimeException if running on Windows
     */
    public function __construct(int $signalNumber = self::DEFAULT_SIGNAL)
    {
        $this->signalNumber = $signalNumber;
        
        $this->validateEnvironment();
        $this->detectTimerBackend();
        $this->setupSignalHandlers();
    }

    /**
     * Sets a one-time timer (like JavaScript's setTimeout)
     * 
     * @param callable $callback Function to call when timer expires
     * @param float $durationMs Duration in milliseconds
     * @return self For method chaining
     * @throws \InvalidArgumentException if duration is invalid
     * @throws \RuntimeException if a timer is already active
     */
    public function setTimeout(callable $callback, float $durationMs): self
    {
        $this->validateDuration($durationMs);
        $this->ensureNoActiveTimer();

        $this->durationMs = $durationMs;
        $this->isInterval = false;
        $this->callback = $callback;

        $this->startTimer();
        
        return $this;
    }

    /**
     * Sets a repeating interval timer (like JavaScript's setInterval)
     * 
     * @param callable $callback Function to call on each interval
     * @param float $durationMs Duration in milliseconds between calls
     * @return self For method chaining
     * @throws \InvalidArgumentException if duration is invalid
     * @throws \RuntimeException if a timer is already active
     */
    public function setInterval(callable $callback, float $durationMs): self
    {
        $this->validateDuration($durationMs);
        $this->ensureNoActiveTimer();

        $this->durationMs = $durationMs;
        $this->isInterval = true;
        $this->callback = $callback;

        $this->startTimer();
        
        return $this;
    }

    /**
     * Temporarily disable callbacks
     * 
     * Useful for critical sections where a block of code should finish before
     * the callback is invoked. You can also avoid invoking the callback
     * alltogether by setting pendCallbacks to false.
     * 
     * @param bool $active False to disable callbacks, true to enable
     * @param bool $pendCallbacks True to invoke suspended callback when
     * callbacks are re-enabled, false to ignore suspended callbacks
     * @return self For method chaining
     */
    public function callbacks(bool $enabled = true, bool $pendCallbacks = true): self
    {
        $wasEnabled = $this->callbacksEnabled;
        $this->callbacksEnabled = $enabled;
        
        if (!$enabled) {
            $this->callbacksEnabled = false;
            $this->pendCallbacks = $pendCallbacks;
        } else {
            $this->callbacksEnabled = true;
            $this->pendCallbacks = true;

            // If re-enabling and there's a pending callback, invoke it
            if ((!$wasEnabled) && $this->callbackQueued) {
                $this->handleSignal($this->signalNumber);
            }
        }
        
        return $this;
    }

    /**
     * Temporarily mask signals
     * 
     * Useful for critical sections where system calls should not be
     * interrupted.
     * 
     * @param bool $allow False to mask signals, true to unmask
     * @return $return self for method chaining
     */
    public function signals(bool $allow): self
    {
        if ((!$allow) && (!$this->signalsMasked)) {
            pcntl_sigprocmask(SIG_BLOCK, [$this->signalNumber]);
            $this->signalsMasked = true;
        }
        elseif ($this->signalsMasked) {
            pcntl_sigprocmask(SIG_UNBLOCK, [$this->signalNumber]);
            $this->signalsMasked = false;
        }

        return $this;
    }

    /**
     * Clears the active timer (like JavaScript's clearTimeout/clearInterval)
     * 
     * @return self For method chaining
     */
    public function clear(): self
    {
        if (!$this->isActive) {
            return $this;
        }

        if ($this->useSwooleThreads) {
            $this->stopSwooleThread();
        } else {
            $this->stopTimerProcess();
        }

        $this->isActive = false;
        $this->callbackQueued = false;
        $this->signals(true);
        $this->callbacks(true);
        
        return $this;
    }

    /**
     * Checks if a Swoole thread is currently running
     * 
     * @return bool True if thread is running, false otherwise
     */
    public function isSwooleThreadRunning(): bool
    {
        if (!$this->swooleThread) {
            return false;
        }
        
        // join(0) returns true if thread finished, false if still running
        return !$this->swooleThread->join(0);
    }

    /**
     * Shutdown handler for graceful cleanup
     * 
     * @param int|null $signalNumber Signal that triggered shutdown
     */
    public function shutdown(?int $signalNumber = null): void
    {
        echo "MAIN THREAD: Shutdown...\n";
        $this->clear();
        exit(0);
    }

    /**
     * Signal handler callback
     * 
     * @param int $signalNumber The signal number received
     */
    public function handleSignal(int $signalNumber): void
    {
        if (!$this->callbacksEnabled) {
            if ($this->pendCallbacks) {
                $this->callbackQueued = true;
                return;
            }
            else {
                return;
            }
        }

        // Execute the callback
        $this->callbackQueued = false;
        
        if (isset($this->callback)) {
            ($this->callback)();
        }
    }

    // ***********************
    // **  Private Methods  **
    // ***********************

    /**
     * Validates the runtime environment
     * 
     * @throws \RuntimeException if running on Windows
     */
    private function validateEnvironment(): void
    {
        if (stripos(PHP_OS, 'win') !== false) {
            throw new \RuntimeException("Timer does not support Windows OS");
        }
        
        if (!function_exists('pcntl_fork')) {
            throw new \RuntimeException("pcntl extension is required but not available");
        }
        
        if (!function_exists('posix_kill')) {
            throw new \RuntimeException("posix extension is required but not available");
        }
    }

    /**
     * Detects available timer backend (Swoole threads or pcntl processes)
     */
    private function detectTimerBackend(): void
    {
        if (!extension_loaded('swoole')) {
            $this->useProcess = true;
            return;
        }

        $swooleVersion = (int) swoole_version()[0];
        
        if ($swooleVersion >= 6) {
            $this->useSwooleThreads = true;
        } else {
            $this->useProcess = true;
        }
    }

    /**
     * Sets up signal handlers
     */
    private function setupSignalHandlers(): void
    {
        pcntl_async_signals(true);

        // Invoke shutdown method on script exit.
        register_shutdown_function([$this, 'shutdown']);

        // Shutdown signals
        $shutdownSignals = [SIGINT, SIGTERM, SIGHUP, SIGQUIT];
        foreach ($shutdownSignals as $signal) {
            pcntl_signal($signal, [$this, 'shutdown']);
        }

        // Timer signal
        pcntl_signal($this->signalNumber, [$this, 'handleSignal']);
    }

    /**
     * Validates timer duration
     * 
     * @param float $durationMs Duration in milliseconds
     * @throws \InvalidArgumentException if duration is invalid
     */
    private function validateDuration(float $durationMs): void
    {
        if ($durationMs < self::MIN_DURATION_MS) {
            throw new \InvalidArgumentException(
                sprintf('Duration must be at least %.1f milliseconds', self::MIN_DURATION_MS)
            );
        }
    }

    /**
     * Ensures no timer is currently active before starting a new one
     * 
     * @throws \RuntimeException if a timer is already active
     */
    private function ensureNoActiveTimer(): void
    {
        if (!$this->isActive) {
            return;
        }

        // Double-check if Swoole thread is actually running
        if ($this->useSwooleThreads && !$this->isSwooleThreadRunning()) {
            $this->isActive = false;
            return;
        }

        throw new \RuntimeException(
            "Timer is already active. Call clear() before setting a new timer."
        );
    }

    /**
     * Starts the timer using the appropriate backend
     */
    private function startTimer(): void
    {
        $this->mainProcessPid = getmypid();

        if ($this->useSwooleThreads) {
            $this->startSwooleThread();
        } else {
            self::$timerProcessPids[] = $this->startTimerProcess();
        }

        $this->isActive = true;
    }

    /**
     * Starts a Swoole thread for the timer
     * 
     * @throws \RuntimeException if thread script is not found
     */
    private function startSwooleThread(): void
    {
        $stopFlag = new \Swoole\Thread\Atomic(0);
        self::$stopFlags[] = $stopFlag;
        
        $threadScript = __DIR__ . "/TimerSwooleThread.php";
        
        if (!file_exists($threadScript)) {
            throw new \RuntimeException("Timer thread script not found: {$threadScript}");
        }

        $this->swooleThread = new \Swoole\Thread(
            $threadScript,
            $this->durationMs,
            $this->signalNumber,
            $this->isInterval,
            $this->mainProcessPid,
            $stopFlag
        );
    }

    /**
     * Starts a pcntl forked process for the timer
     * 
     * @return int PID of the timer process
     * @throws \RuntimeException if fork fails
     */
    private function startTimerProcess(): int
    {
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork timer process');
        }
        
        if ($pid === 0) {
            // Child process
            $this->runTimerProcess();
            exit(0);
        }
        
        // Parent process
        return $pid;
    }

    /**
     * Timer process main loop (runs in child process)
     */
    private function runTimerProcess(): void
    {
        $durationNano = $this->durationMs * self::NANOSECONDS_PER_MS;
        $sleepMicro = $this->calculateSleepInterval($this->durationMs);

        $lastTime = hrtime(true);
        
        while (true) {
            $currentTime = hrtime(true);
            $elapsedNano = $currentTime - $lastTime;

            if ($elapsedNano >= $durationNano) {
                if (!$this->sendSignalToParent()) {
                    break;
                }
                
                if (!$this->isInterval) {
                    break;
                }

                // Compensate for drift
                $lastTime = $currentTime - ($elapsedNano - $durationNano);
            }
            
            usleep($sleepMicro);
        }
    }

    /**
     * Sends signal to parent process
     * 
     * @return bool True if successful or should continue, false if should exit
     */
    private function sendSignalToParent(): bool
    {
        $result = posix_kill($this->mainProcessPid, $this->signalNumber);
        
        if ($result) {
            return true;
        }
        
        $error = posix_get_last_error();
        
        // If parent process doesn't exist, exit
        if ($error === self::POSIX_ERROR_NO_PROCESS) {
            return false;
        }
        
        return true;
    }

    /**
     * Calculates optimal sleep interval based on timer duration
     * 
     * @param float $durationMs Timer duration in milliseconds
     * @return int Sleep interval in microseconds
     */
    private function calculateSleepInterval(float $durationMs): int
    {
        $sleepMicro = (int) round(
            ($durationMs * self::MICROSECONDS_PER_MS) / self::SLEEP_INTERVAL_DIVISOR
        );
        
        if ($sleepMicro > self::MAX_SLEEP_MICRO) {
            return self::MAX_SLEEP_MICRO;
        }
        
        if ($sleepMicro < self::MIN_SLEEP_MICRO) {
            return self::MIN_SLEEP_MICRO;
        }
        
        return $sleepMicro;
    }

    /**
     * Stops the Swoole thread
     */
    private function stopSwooleThread(): void
    {
        if (!$this->swooleThread) {
            return;
        }

        // Signal all threads to stop
        foreach (self::$stopFlags as $flag) {
            $flag->set(1);
        }
        
        // Give threads time to see the flag
        usleep(250_000);
        
        // Wait for thread to exit
        $this->swooleThread->join();
        $this->swooleThread = null;
    }

    /**
     * Stops all timer processes
     */
    private function stopTimerProcess(): void
    {
        if (empty(self::$timerProcessPids)) {
            return;
        }
        
        foreach (self::$timerProcessPids as $pid) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }

        self::$timerProcessPids = [];
    }
}