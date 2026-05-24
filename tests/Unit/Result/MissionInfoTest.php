<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Result;

use Letts\Result\MissionInfo;
use PHPUnit\Framework\TestCase;

final class MissionInfoTest extends TestCase
{
    /**
     * Wire format mirrors daemon's handlers.buildMissionResponse exactly
     * (internal/server/handlers/missions_get.go).
     */
    public function testFromApiResponseFull(): void
    {
        $d = [
            'mission_id' => 'mid',
            'kind' => 'mission',
            'lane' => 'normal',
            'mission_name' => 'X',
            'display_name' => 'X (dn)',
            'group_id' => 'g1',
            'status' => 'done',
            'outcome' => 'success',
            'exit_code' => 0,
            'signal' => null,
            'fail_reason' => null,
            'fail_message' => null,
            'fail_details' => null,
            'return' => ['ok' => true],
            'input' => ['x' => 1],
            'input_fingerprint' => 'fp',
            'pid' => 1234,
            'time_created' => 100,
            'time_started' => 101,
            'time_finished' => 200,
            'duration_ms' => 99,
            'timeout_ms' => 30000,
            'truncated_stdout' => true,
            'truncated_stderr' => false,
            'restarted_from' => 'prev-mid',
            'inputs' => [
                ['role' => 'in1', 'staging_id' => 'sid-in', 'sha256' => 'h1', 'size' => 10],
            ],
            'outputs' => [
                'result' => ['staging_id' => 'sid-out', 'sha256' => 'hash', 'size' => 42],
            ],
        ];
        $m = MissionInfo::fromApiResponse($d);
        $this->assertSame('mid', $m->missionId);
        $this->assertSame('mission', $m->kind);
        $this->assertSame('normal', $m->lane);
        $this->assertSame('X', $m->missionName);
        $this->assertSame('X (dn)', $m->displayName);
        $this->assertSame('g1', $m->groupId);
        $this->assertSame('done', $m->status);
        $this->assertSame('success', $m->outcome);
        $this->assertSame(0, $m->exitCode);
        $this->assertNull($m->signal);
        $this->assertNull($m->failReason);
        $this->assertNull($m->failMessage);
        $this->assertNull($m->failDetails);
        $this->assertSame(['ok' => true], $m->return);
        $this->assertSame(['x' => 1], $m->input);
        $this->assertSame('fp', $m->inputFingerprint);
        $this->assertSame(1234, $m->pid);
        $this->assertSame(100, $m->timeCreatedMs);
        $this->assertSame(101, $m->timeStartedMs);
        $this->assertSame(200, $m->timeFinishedMs);
        $this->assertSame(99, $m->durationMs);
        $this->assertSame(30000, $m->timeoutMs);
        $this->assertTrue($m->truncatedStdout);
        $this->assertFalse($m->truncatedStderr);
        $this->assertSame('prev-mid', $m->restartedFrom);
        $this->assertCount(1, $m->inputs);
        $this->assertSame('sid-in', $m->inputs[0]['staging_id']);
        $this->assertSame(42, $m->outputs['result']['size']);
        $this->assertSame('sid-out', $m->outputs['result']['staging_id']);
    }

    public function testFromApiResponseMinimal(): void
    {
        $m = MissionInfo::fromApiResponse(['mission_id' => 'mid', 'status' => 'queued']);
        $this->assertSame('mid', $m->missionId);
        $this->assertSame('queued', $m->status);
        $this->assertSame('', $m->lane);
        $this->assertSame('', $m->outcome);
        $this->assertNull($m->exitCode);
        $this->assertNull($m->signal);
        $this->assertNull($m->restartedFrom);
        $this->assertSame(0, $m->durationMs);
        $this->assertSame([], $m->inputs);
        $this->assertSame([], $m->outputs);
    }

    public function testFromApiResponseDailyDefaults(): void
    {
        $m = MissionInfo::fromApiResponse([
            'mission_id' => 'mid', 'status' => 'queued',
            'truncated_stdout' => false, 'truncated_stderr' => false,
        ]);
        $this->assertFalse($m->truncatedStdout);
        $this->assertFalse($m->truncatedStderr);
    }
}
