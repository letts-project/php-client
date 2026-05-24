<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Mission;

use Letts\Internal\Mission\ShutdownHandler;
use PHPUnit\Framework\TestCase;

final class ShutdownHandlerTest extends TestCase
{
    public function testInstallReturnsHandlerWithReserve(): void
    {
        $h = ShutdownHandler::install();
        $this->assertInstanceOf(ShutdownHandler::class, $h);
        $this->assertGreaterThanOrEqual(64 * 1024, $h->reserveBytes());
    }

    public function testReportFatalReturnsNullWhenNoError(): void
    {
        $h = new ShutdownHandler();
        $this->assertNull($h->report(null));
    }

    public function testReportFatalRecognizesEError(): void
    {
        $r = (new ShutdownHandler())->report([
            'type' => E_ERROR,
            'message' => 'Allowed memory size exhausted',
            'file' => '/path.php',
            'line' => 42,
        ]);
        $this->assertNotNull($r);
        $this->assertSame('oom', $r['outcome']);
        $this->assertSame('/path.php', $r['fail_details']['file']);
    }

    public function testReportFatalRecognizesGenericFatal(): void
    {
        $r = (new ShutdownHandler())->report([
            'type' => E_PARSE,
            'message' => 'syntax error',
            'file' => '/p.php',
            'line' => 1,
        ]);
        $this->assertNotNull($r);
        $this->assertSame('crashed', $r['outcome']);
    }

    public function testReportIgnoresNonFatal(): void
    {
        $r = (new ShutdownHandler())->report([
            'type' => E_WARNING,
            'message' => 'warn',
            'file' => '/p.php',
            'line' => 1,
        ]);
        $this->assertNull($r);
    }
}
