<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Result;

use Letts\Result\Logs;
use PHPUnit\Framework\TestCase;

final class LogsTest extends TestCase
{
    public function testHoldsAllFields(): void
    {
        $l = new Logs(stdout: 'hi', stderr: 'oh', stdoutTruncated: true, stderrTruncated: false);
        $this->assertSame('hi', $l->stdout);
        $this->assertSame('oh', $l->stderr);
        $this->assertTrue($l->stdoutTruncated);
        $this->assertFalse($l->stderrTruncated);
    }

    public function testFromApiResponse(): void
    {
        $l = Logs::fromApiResponse([
            'stdout' => 'a', 'stderr' => 'b',
            'stdout_truncated' => true, 'stderr_truncated' => false,
        ]);
        $this->assertSame('a', $l->stdout);
        $this->assertTrue($l->stdoutTruncated);
    }

    public function testFromApiResponseEmpty(): void
    {
        $l = Logs::fromApiResponse([]);
        $this->assertSame('', $l->stdout);
        $this->assertSame('', $l->stderr);
        $this->assertFalse($l->stdoutTruncated);
    }
}
