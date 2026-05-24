<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Result;

use Letts\Result\HostError;
use Letts\Result\HostResult;
use Letts\Result\Logs;
use Letts\Result\RunResult;
use PHPUnit\Framework\TestCase;

final class HostResultTest extends TestCase
{
    public function testReachableWhenErrorNull(): void
    {
        $rr = new RunResult(
            host: 's1', missionId: 'm', outcome: 'success',
            failReason: null, failMessage: null, failDetails: null,
            return: null, exitCode: 0, signal: null, durationMs: 1,
            logs: new Logs(), outputFiles: [],
        );
        $hr = new HostResult('s1', $rr, null);
        $this->assertTrue($hr->isReachable());
        $this->assertTrue($hr->isSuccess());
    }

    public function testUnreachableOnError(): void
    {
        $hr = new HostResult('s1', null, new HostError('network', 'down'));
        $this->assertFalse($hr->isReachable());
        $this->assertFalse($hr->isSuccess());
    }

    public function testReachableButFailedMission(): void
    {
        $rr = new RunResult(
            host: 's1', missionId: 'm', outcome: 'failed',
            failReason: 'explicit', failMessage: 'boom', failDetails: null,
            return: null, exitCode: 1, signal: null, durationMs: 1,
            logs: new Logs(), outputFiles: [],
        );
        $hr = new HostResult('s1', $rr, null);
        $this->assertTrue($hr->isReachable());
        $this->assertFalse($hr->isSuccess());
    }
}
