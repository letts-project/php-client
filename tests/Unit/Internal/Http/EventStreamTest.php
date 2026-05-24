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
}
