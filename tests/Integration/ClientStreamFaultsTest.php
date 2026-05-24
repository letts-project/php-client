<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Config\Scope;
use Letts\Exceptions\DispatchException;
use Letts\Exceptions\NetworkException;
use Letts\Internal\Http\EventStream;
use Letts\Tests\Integration\support\DugdaleFixture;
use Letts\Tests\Integration\support\FlakyProxy;

/**
 * Fault injection on the event-stream path via a TCP proxy: connections cut
 * mid-stream, responses dropped after the server processed the request, and
 * HTTP errors served instead of a stream.
 */
final class ClientStreamFaultsTest extends DugdaleFixture
{
    public function testRunSurvivesMidStreamConnectionCut(): void
    {
        // Cut the first events stream after ~350 bytes (headers plus the first
        // event or two), while the mission still has ~2s to run. The client
        // must reconnect with a `from=` cursor and finish cleanly — silently.
        $proxy = FlakyProxy::start($this->port, 'cut', '#GET /v1/missions/[0-9a-f-]+/events#', 350);
        $warnings = [];
        set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $r = $this->clientVia($proxy->listenPort)->run(
                host: 'local', lane: 'normal', mission: 'quiet_sleep',
                input: ['seconds' => 2],
            );
        } finally {
            restore_error_handler();
        }
        try {
            $this->assertTrue($r->isSuccess());
            $this->assertSame([], $warnings, 'a dropped stream must be handled internally, not via PHP warnings');
            $this->assertStringContainsString('cut connection', $proxy->log(), 'the fault must actually have fired');
            $this->assertStringContainsString('from=', $proxy->log(), 'reconnect must resume with a seq cursor');
        } finally {
            $proxy->stop();
        }
    }

    public function testEventsOfDeletedMissionFailFastWithMappedException(): void
    {
        $c = $this->client();
        $r = $c->run(host: 'local', lane: 'normal', mission: 'echo_input', input: ['x' => 1]);
        $this->waitForDone($c, $r->missionId);
        $c->delete($r->missionId, host: 'local');

        $es = new EventStream($c->rawTransportFor('local', Scope::Dispatch));
        $t0 = microtime(true);
        try {
            $es->follow("/v1/missions/$r->missionId/events?follow=true", fn() => true);
            $this->fail('expected an HTTP-mapped exception for a deleted mission');
        } catch (DispatchException $e) {
            // 410 while the row is in deletion, 404 once the sweeper removed it.
            $this->assertContains($e->getCode(), [410, 404]);
            $elapsed = microtime(true) - $t0;
            $this->assertLessThan(2.0, $elapsed, 'an HTTP error must fail fast, not exhaust reconnects');
        }
    }

    public function testRestartIsNotRetriedAfterDroppedResponse(): void
    {
        $c = $this->client();
        $orig = $c->run(host: 'local', lane: 'normal', mission: 'echo_input', input: ['x' => 1])->missionId;
        $this->waitForDone($c, $orig); // restart requires the source to be done

        // Every restart response is swallowed AFTER the daemon processed it:
        // the client sees a dead connection and cannot know whether the
        // restart happened. Blindly re-sending would enqueue duplicates.
        $proxy = FlakyProxy::start($this->port, 'drop', '#POST /v1/missions/[0-9a-f-]+/restart#');
        try {
            try {
                $this->clientVia($proxy->listenPort)->restart($orig, host: 'local');
                $this->fail('expected NetworkException after a dropped response');
            } catch (NetworkException) {
            }
        } finally {
            $proxy->stop();
        }

        $restarted = array_filter(
            $c->listMissions(host: 'local'),
            fn($m) => $m->restartedFrom === $orig,
        );
        $this->assertCount(1, $restarted, 'an ambiguous restart failure must not multiply missions');
    }
}
