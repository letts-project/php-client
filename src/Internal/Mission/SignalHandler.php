<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Installs SIGTERM/SIGINT handlers that set an "interrupt requested" flag.
 * Mission code checks the flag via Mission::checkSignal() which throws
 * InterruptedException so the runtime can clean up. Uses pcntl_async_signals
 * for delivery without manual pcntl_signal_dispatch() calls.
 */
final class SignalHandler
{
    private bool $requested = false;

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
    }

    public function interruptRequested(): bool
    {
        return $this->requested;
    }
}
