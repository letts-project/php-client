<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientRunHappyPathTest extends DugdaleFixture
{
    public function testEchoInputRoundTrips(): void
    {
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'echo_input',
            input: ['item_id' => 42, 'name' => 'test'],
        );
        $this->assertTrue($r->isSuccess());
        $this->assertSame(['item_id' => 42, 'name' => 'test'], $r->return);
        $this->assertSame(0, $r->exitCode);
    }

    public function testEmptyInputDoesNotBreakMission(): void
    {
        // Regression: $m->all() on empty input is [], and $m->success([]) must
        // emit a JSON object `{}` — not `[]`, which dugdale rejects as
        // event_protocol_error. Mission must also not fatal on `null`/`{}` stdin.
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'echo_input',
            input: [],
        );
        $this->assertTrue($r->isSuccess(), 'empty-input mission must succeed; got ' . $r->outcome);
        $this->assertSame(0, $r->exitCode);
    }

    public function testFetchLogsPopulatesStdoutAndStderr(): void
    {
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'logs_to_streams',
            fetchLogs: true,
        );
        $this->assertTrue($r->isSuccess());
        $this->assertStringContainsString('stdout-marker', $r->logs->stdout);
        $this->assertStringContainsString('stderr-marker', $r->logs->stderr);
    }

    public function testLogsEmptyUnlessFetchRequested(): void
    {
        $r = $this->client()->run(host: 'local', lane: 'normal', mission: 'logs_to_streams');
        $this->assertSame('', $r->logs->stdout);
        $this->assertSame('', $r->logs->stderr);
    }

    public function testProgressCallbacks(): void
    {
        $progress = [];
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'progress_steps',
            onProgress: function (?float $v, ?string $m) use (&$progress) { $progress[] = [$v, $m]; },
        );
        $this->assertTrue($r->isSuccess());
        $this->assertGreaterThanOrEqual(1, count($progress));
        $this->assertSame(5, $r->return['steps']);
    }
}
