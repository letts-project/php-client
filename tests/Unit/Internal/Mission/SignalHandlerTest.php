<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Mission;

use Letts\Internal\Mission\SignalHandler;
use PHPUnit\Framework\TestCase;

final class SignalHandlerTest extends TestCase
{
    public function testInitiallyNotRequested(): void
    {
        $h = new SignalHandler();
        $this->assertFalse($h->interruptRequested());
    }

    public function testFlagSetByDirectInvoke(): void
    {
        $h = new SignalHandler();
        $h->handle(SIGTERM);
        $this->assertTrue($h->interruptRequested());
    }

    public function testInstallReturnsInstanceAndDoesNotThrow(): void
    {
        $h = SignalHandler::install();
        $this->assertInstanceOf(SignalHandler::class, $h);
    }

    public function testOnSignalCallbacksRunOnHandleAndFlagStillSet(): void
    {
        $h = new SignalHandler();
        $seen = [];
        $h->onSignal(function (int $sig) use (&$seen) { $seen[] = $sig; });
        $h->onSignal(function () use (&$seen) { $seen[] = 'second'; });

        $h->handle(SIGTERM);

        $this->assertTrue($h->interruptRequested());
        $this->assertSame([SIGTERM, 'second'], $seen);
    }
}
