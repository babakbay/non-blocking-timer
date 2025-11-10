# A PHP Non-blocking Timer Library

This timer uses a combination of a forked process (or a thread if Swoole v6+ is detected) together with POSIX signals to invoke a callback at a specified interval with millisecond-level accuracy — even when the PHP interpreter is suspended (e.g. during database or network queries, or system calls such as sleep(), curl_exec(), fread(), etc).

Unlike timers implemented using event loops or coroutines, this approach does not depend on the PHP event loop being "active". The timer continues to run independently in its own process or thread, ensuring precise timing under blocking conditions.

This brings `setTimeout()` and `setInterval()` style functionality to PHP!

---

## Requirements

| Requirement | Minimum Version |
|--------------|----------------|
| PHP | 8.1+ |
| POSIX extension | Enabled |
| `pcntl` extension | Enabled |
| (Optional) `swoole` | v6.0+ for thread backend |
| OS | Linux / macOS (not supported on Windows) |

---

## Usage

### One-shot timeout (setTimeout)

```php
use BabakBay\NonBlockingTimer\Timer;

$timer = new Timer();
$timer->setTimeout(function() {
    echo "Executed after 1 second\n";
}, 1000);

// Timer continues even during blocking operations
while (true) {
    sleep(2);
}
```

### Repeating interval (setInterval)

```php
$timer = new Timer();
$counter = 0;

$timer->setInterval(function() use (&$counter) {
    echo "Tick " . ++$counter . "\n";
}, 500);

while (true) {
    sleep(2);
}
```

### Clear timer

To stop and clear an active timer, use the clear() method.

```php
$timer->clear();
```

### Critical sections

You can disable callbacks during critical sections of your code using the
callback() method.

```php
$timer = new Timer();

$timer->setInterval(function() {
    echo "Timer callback\n";
}, 100);

// Disable callbacks during critical section. By default once callbacks 
// are re-enabled, the callback will be invoked if there were any callback
// invokations blocked.

$timer->callbacks(false);
performCriticalOperation();
$timer->callbacks(true); // Re-enable ca;;nacls and invoke any pending callback

// If you do not want to invoke any pending callbacks, pass true as the
// second argument.

$timer->callbacks(false, true); // Do not pend callbacks
performCriticalOperation();
$timer->callbacks(true); // Re-enable callbacks

```

### Signal Masking

If you want to block timer signals from being received during a critical section
of your code, use the signals() method. Any pending signals will be triggered
once signals are re-enabled.

```php
$timer = new Timer();
$timer->setInterval(function() {
    echo "Timer callback\n";
}, 100);

// Block signals during system call
$timer->signals(false);
$result = curl_exec($ch);
$timer->signals(true);
```





