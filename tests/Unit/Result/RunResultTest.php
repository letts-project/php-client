<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Result;

use Letts\Result\Logs;
use Letts\Result\RunResult;
use PHPUnit\Framework\TestCase;

final class RunResultTest extends TestCase
{
    public function testIsSuccessTrue(): void
    {
        $r = new RunResult(
            host: 's1', missionId: 'id', outcome: 'success',
            failReason: null, failMessage: null, failDetails: null,
            return: ['x' => 1], exitCode: 0, signal: null, durationMs: 100,
            logs: new Logs(), outputFiles: [],
        );
        $this->assertTrue($r->isSuccess());
    }

    public function testIsSuccessFalseOnFailed(): void
    {
        $r = new RunResult(
            host: 's1', missionId: 'id', outcome: 'failed',
            failReason: 'explicit', failMessage: 'boom', failDetails: null,
            return: null, exitCode: 1, signal: null, durationMs: 100,
            logs: new Logs(), outputFiles: [],
        );
        $this->assertFalse($r->isSuccess());
    }

    public function testFromApiResponseBuildsFullObject(): void
    {
        $r = RunResult::fromApiResponse('s1', [
            'mission_id' => 'mid', 'outcome' => 'success', 'exit_code' => 0,
            'return' => ['k' => 1], 'duration_ms' => 100,
            'logs' => ['stdout' => 'hi', 'stderr' => ''],
            'outputs' => [],
        ]);
        $this->assertSame('s1', $r->host);
        $this->assertSame('mid', $r->missionId);
        $this->assertSame(['k' => 1], $r->return);
        $this->assertSame('hi', $r->logs->stdout);
    }
}
