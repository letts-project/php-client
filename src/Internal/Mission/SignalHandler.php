<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Installs SIGTERM/SIGINT handlers that set an "interrupt requested" flag and
 * run any callbacks registered via onSignal(). Mission code reacts to a signal
 * either by polling the flag (Mission::checkSignal(), which throws
 * InterruptedException so the runtime can clean up) or by registering a callback
 * (Mission::onInterrupt()) for a blocking operation that can't poll the flag.
 * Uses pcntl_async_signals for delivery without manual pcntl_signal_dispatch()
 * calls.
 */
final class SignalHandler
{
    private bool $requested = false;

    /** @var list<\Closure(int):void> */
    private array $callbacks = [];

    public static function install(): self
    {
        $h = new self();
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, $h->handle(...));
            pcntl_signal(SIGINT,  $h->handle(...));
        }
        return $h;
    }

    public function handle(int $signal): void
    {
        $this->requested = true;
        foreach ($this->callbacks as $cb) {
            $cb($signal);
        }
    }

    public function interruptRequested(): bool
    {
        return $this->requested;
    }

    /**
     * Register a callback to run on SIGTERM/SIGINT, in addition to setting the
     * interrupt flag. The callback receives the signal number. Runs in the async
     * signal handler, so keep it short (e.g. set a stop flag).
     */
    public function onSignal(\Closure $cb): void
    {
        $this->callbacks[] = $cb;
    }
}
