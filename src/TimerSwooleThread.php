<?php
declare(strict_types=1);

namespace BabakBay\NonBlockingTimer;

/**
 * Timer Swoole Thread Worker
 *
 * This script runs in a Swoole thread and sends periodic signals to the main
 * process.
 *
 * @author Babak Bayani
 * @license MIT
 */

// Constants
const NANOSECONDS_PER_MS = 1_000_000;
const MICROSECONDS_PER_MS = 1_000;
const SLEEP_INTERVAL_DIVISOR = 10;
const MAX_SLEEP_MICRO = 10_000;
const MIN_SLEEP_MICRO = 100;
const POSIX_ERROR_NO_PROCESS = 3; // ESRCH

/**
 * Thread configuration and state
 */
class ThreadConfig
{
    public float $durationMs;
    public int $signalNumber;
    public bool $isInterval;
    public int $parentPid;
    public $stopFlag;

    public function __construct(
        float $durationMs,
        int $signalNumber,
        bool $isInterval,
        int $parentPid,
        $stopFlag
    ) {
        $this->durationMs = $durationMs;
        $this->signalNumber = $signalNumber;
        $this->isInterval = $isInterval;
        $this->parentPid = $parentPid;
        $this->stopFlag = $stopFlag;
    }

    /**
     * Validates the configuration
     * 
     * @throws \InvalidArgumentException if any parameter is invalid
     */
    public function validate(): void
    {
        if ($this->durationMs <= 0) {
            throw new \InvalidArgumentException("Duration must be positive");
        }

        if ($this->signalNumber <= 0) {
            throw new \InvalidArgumentException("Signal number must be positive");
        }

        if ($this->parentPid <= 0) {
            throw new \InvalidArgumentException("Parent PID must be positive");
        }
    }
}

/**
 * Signal sender for communicating with parent process
 */
class SignalSender
{
    private int $parentPid;
    private int $signalNumber;

    public function __construct(int $parentPid, int $signalNumber)
    {
        $this->parentPid = $parentPid;
        $this->signalNumber = $signalNumber;
    }

    /**
     * Sends a signal to the parent process
     * 
     * @return bool True if successful or should continue, false if should exit
     */
    public function send(): bool
    {
        $result = posix_kill($this->parentPid, $this->signalNumber);
        
        if ($result) {
            return true;
        }
        
        // If parent process doesn't exist, we should exit
        $error = posix_get_last_error();
        if ($error === POSIX_ERROR_NO_PROCESS) {
            return false;
        }
        
        // For other errors, continue trying
        return true;
    }
}

/**
 * Main timer thread executor
 */
class TimerThread
{
    private ThreadConfig $config;
    private SignalSender $signalSender;
    private int $durationNano;
    private int $sleepMicro;
    private int $lastTime;

    public function __construct(ThreadConfig $config)
    {
        $this->config = $config;
        $this->signalSender = new SignalSender($config->parentPid, $config->signalNumber);
        $this->durationNano = (int) ($config->durationMs * NANOSECONDS_PER_MS);
        $this->sleepMicro = $this->calculateSleepInterval($config->durationMs);
        $this->lastTime = hrtime(true);
    }

    /**
     * Sets up signal handlers
     */
    public function setupSignalHandlers(): void
    {
        pcntl_async_signals(true);

        // Handle graceful shutdown
        pcntl_signal(SIGTERM, function (): void {
            echo "Swoole thread: Got SIGTERM\n";
            $this->shutdown();
        });

        // Block the timer signal in this thread to ensure it's delivered to main process
        pcntl_sigprocmask(SIG_BLOCK, [$this->config->signalNumber]);
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
            ($durationMs * MICROSECONDS_PER_MS) / SLEEP_INTERVAL_DIVISOR
        );
        
        if ($sleepMicro > MAX_SLEEP_MICRO) {
            return MAX_SLEEP_MICRO;
        }
        
        if ($sleepMicro < MIN_SLEEP_MICRO) {
            return MIN_SLEEP_MICRO;
        }
        
        return $sleepMicro;
    }

    /**
     * Executes the main timer loop
     * 
     * @return int Exit code
     */
    public function run(): int
    {
        while (true) {
            $currentTime = hrtime(true);

            // Check if duration has elapsed
            $elapsedNano = $currentTime - $this->lastTime;

            // Send signal if duration has lapsed
            if ($elapsedNano >= $this->durationNano) {
                // Send signal to parent
                if (!$this->signalSender->send()) {
                    // Parent process is gone, exit
                    break;
                }
                
                // Exit if this is a one-time timeout
                if (!$this->config->isInterval) {
                    break;
                }

                // Compensate for drift by adjusting last time
                $this->lastTime = $currentTime - ($elapsedNano - $this->durationNano);
            }
            
            // Check stop flag
            if ($this->config->stopFlag->get() > 0) {
                echo "Timer thread: stopFlag set to 1\n";
                $this->shutdown();
            }

            // Sleep to reduce CPU usage
            usleep($this->sleepMicro);
        }

        return 0;
    }

    private function shutdown()
    {
        echo "Timer thread shutdown...\n";
        exit(0);
    }
}

// **********************
// **  Main Execution  **
// **********************

try {
    // Retrieve thread arguments
    $args = \Swoole\Thread::getArguments();
    
    if (count($args) !== 5) {
        exit(1);
    }

    [$durationMs, $signalNumber, $isInterval, $parentPid, $stopFlag] = $args;

    // Create and validate configuration
    $config = new ThreadConfig(
        (float) $durationMs,
        (int) $signalNumber,
        (bool) $isInterval,
        (int) $parentPid,
        $stopFlag
    );
    
    $config->validate();

    // Create and run timer thread
    $thread = new TimerThread($config);
    $thread->setupSignalHandlers();
    
    exit($thread->run());
    
} catch (\Throwable $e) {
    exit(1);
}