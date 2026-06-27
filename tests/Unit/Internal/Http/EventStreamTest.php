<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Http;

use Letts\Internal\Http\Event;
use Letts\Internal\Http\EventStream;
use Letts\Internal\Http\HttpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EventStreamTest extends TestCase
{
    public function testReadsAllNdjsonLines(): void
    {
        $body = "{\"seq\":1,\"event\":\"queued\"}\n"
              . "{\"seq\":2,\"event\":\"running\",\"pid\":42}\n"
              . "{\"seq\":3,\"event\":\"done\",\"outcome\":\"success\"}\n";
        $mock = new MockHttpClient(fn() => new MockResponse([$body], ['http_code' => 200]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $es = new EventStream($t);

        $seen = [];
        $es->follow('/v1/missions/m/events?follow=true', function (Event $e) use (&$seen) {
            $seen[] = $e->event;
            return $e->event !== 'done';
        });
        $this->assertSame(['queued', 'running', 'done'], $seen);
    }

    public function testEventDecodesAllFields(): void
    {
        $body = "{\"seq\":1,\"event\":\"progress\",\"value\":0.5,\"message\":\"halfway\"}\n";
        $mock = new MockHttpClient(fn() => new MockResponse([$body], ['http_code' => 200]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $es = new EventStream($t);
        $captured = null;
        $es->follow('/v1/missions/m/events', function (Event $e) use (&$captured) {
            $captured = $e;
            return false;
        });
        $this->assertSame(1, $captured->seq);
        $this->assertSame('progress', $captured->event);
        $this->assertSame(0.5, $captured->value);
        $this->assertSame('halfway', $captured->message);
    }

    public function testReconnectFromLastSeqAfterDisconnect(): void
    {
        $bodies = [
            "{\"seq\":1,\"event\":\"queued\"}\n",
            "{\"seq\":2,\"event\":\"done\",\"outcome\":\"success\"}\n",
        ];
        $seenUrls = [];
        $calls = 0;
        $mock = new MockHttpClient(function ($method, $url) use (&$bodies, &$seenUrls, &$calls) {
            $seenUrls[] = $url;
            $body = $bodies[$calls++] ?? '';
            return new MockResponse([$body], ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $es = new EventStream($t);

        $seqs = [];
        $es->follow('/v1/missions/m/events?follow=true', function (Event $e) use (&$seqs) {
            $seqs[] = $e->seq;
            return $e->event !== 'done';
        });
        $this->assertSame([1, 2], $seqs);
        $this->assertCount(2, $seenUrls);
        $this->assertStringContainsString('from=1', $seenUrls[1]);
    }

    public function testReconnectBudgetResetsWhileProgressing(): void
    {
        // A long mission whose stream drops repeatedly but keeps delivering new
        // events must NOT exhaust a small reconnect budget (the
        // budget is per-disconnect, not cumulative over the whole stream).
        // 5 single-event connections with maxReconnects=3 → cumulative logic
        // would throw before reaching `done`.
        $bodies = [
            "{\"seq\":1,\"event\":\"progress\",\"value\":0.2}\n",
            "{\"seq\":2,\"event\":\"progress\",\"value\":0.4}\n",
            "{\"seq\":3,\"event\":\"progress\",\"value\":0.6}\n",
            "{\"seq\":4,\"event\":\"progress\",\"value\":0.8}\n",
            "{\"seq\":5,\"event\":\"done\",\"outcome\":\"success\"}\n",
        ];
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$bodies, &$calls) {
            return new MockResponse([$bodies[$calls++] ?? ''], ['http_code' => 200]);
        });
        $es = new EventStream(new HttpTransport($mock, 'http://h', 'tok'));

        $seqs = [];
        $es->follow('/v1/missions/m/events?follow=true', function (Event $e) use (&$seqs) {
            $seqs[] = $e->seq;
            return $e->event !== 'done';
        }, maxReconnects: 3);
        $this->assertSame([1, 2, 3, 4, 5], $seqs);
    }

    public function testFourxxStatusMapsToLettsExceptionWithBody(): void
    {
        // A definitive 4xx aborts the whole follow() and maps (with its body)
        // to the Letts exception hierarchy — read off the first chunk now that
        // streamOnce() no longer calls getStatusCode() before the poll loop.
        $mock = new MockHttpClient(fn() => new MockResponse(
            ['{"error":"not_found","message":"mission not found"}'],
            ['http_code' => 404],
        ));
        $es = new EventStream(new HttpTransport($mock, 'http://h', 'tok'));

        try {
            $es->follow('/v1/missions/m/events', fn() => true);
            $this->fail('expected a Letts exception for 404');
        } catch (\Letts\Exceptions\DispatchException $e) {
            $this->assertStringContainsString('404', $e->getMessage());
            $this->assertStringContainsString('mission not found', $e->getMessage());
        }
    }

    public function testFourHundredMapsToBadRequest(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse(
            ['{"error":"invalid_id","message":"bad id"}'],
            ['http_code' => 400],
        ));
        $es = new EventStream(new HttpTransport($mock, 'http://h', 'tok'));

        $this->expectException(\Letts\Exceptions\BadRequestException::class);
        $es->follow('/v1/missions/m/events', fn() => true);
    }

    public function testQuietStreamStaysOnOneConnectionAcrossIdlePolls(): void
    {
        // A mission that falls silent between events (the '' chunks become idle
        // timeout polls) must NOT trigger a reconnect: streamOnce re-enters
        // stream() on the SAME response. maxReconnects:0 makes any reconnect a
        // fatal budget error, and only ONE response is supplied — so the old
        // single-foreach code (which ended after the first idle poll and let
        // follow() reconnect) would both exhaust the budget and run out of
        // responses. Passing proves the connection is held across idle polls.
        $body = (static function () {
            yield json_encode(['seq' => 1, 'event' => 'queued']) . "\n";
            yield '';                                                          // idle poll
            yield '';                                                          // idle poll
            yield json_encode(['seq' => 2, 'event' => 'running']) . "\n";
            yield '';                                                          // idle poll
            yield json_encode(['seq' => 3, 'event' => 'done', 'status' => 'success']) . "\n";
        })();
        $mock = new MockHttpClient(new MockResponse($body, [
            'response_headers' => ['content-type: application/x-ndjson'],
        ]));
        $es = new EventStream(new HttpTransport($mock, 'http://h', 'tok'));

        $seen = [];
        $es->follow('/v1/missions/m/events?follow=true', function ($ev) use (&$seen): bool {
            $seen[] = $ev->event;
            return $ev->event !== 'done';
        }, maxReconnects: 0);

        $this->assertSame(['queued', 'running', 'done'], $seen);
        $this->assertSame(1, $mock->getRequestsCount(), 'quiet stream must stay on ONE connection (no reconnects)');
    }

    public function testRepeated5xxConsumesBudgetAndFailsFast(): void
    {
        // A server that keeps returning 5xx delivers no events, so every
        // reconnect is event-less and must consume the budget — failing fast at
        // the cap. Regression guard: the budget reset keys on NEW EVENTS, never
        // on the connection merely having been reachable/alive.
        $mock = new MockHttpClient(fn() => new MockResponse('boom', ['http_code' => 503]));
        $es = new EventStream(new HttpTransport($mock, 'http://h', 'tok'));

        $this->expectException(\Letts\Exceptions\NetworkException::class);
        $es->follow('/v1/missions/m/events', fn() => true, maxReconnects: 2);
    }

    public function testBudgetExhaustionMessageNamesPathAndReasons(): void
    {
        // Every connection establishes but ends immediately with no events, so
        // the consecutive-failure budget trips. The error must name the mission
        // path and the per-reconnect reasons so the cause is diagnosable.
        $mock = new MockHttpClient(fn() => new MockResponse([''], ['http_code' => 200]));
        $es = new EventStream(new HttpTransport($mock, 'http://h', 'tok'));

        try {
            $es->follow('/v1/missions/abc123/events?follow=true', fn() => true, maxReconnects: 2);
            $this->fail('expected NetworkException for an event-less, fast-EOFing stream');
        } catch (\Letts\Exceptions\NetworkException $e) {
            $this->assertStringContainsString('/v1/missions/abc123/events', $e->getMessage());
            $this->assertStringContainsString('eof', $e->getMessage());
            $this->assertStringContainsString('recent reconnects', $e->getMessage());
        }
    }
}
