<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Http;

use Letts\Exceptions\BadRequestException;
use Letts\Exceptions\DispatchException;
use Letts\Internal\Http\HttpTransport;
use Letts\Internal\Http\RetryClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RetryClientTest extends TestCase
{
    public function testThreeAttemptsThenSuccess(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;
            return $calls < 3
                ? new MockResponse('', ['http_code' => 503])
                : new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 3, backoffMs: [1, 1, 1]);
        $out = $r->jsonRequest('GET', '/v1/dugdale');
        $this->assertSame(['ok' => true], $out);
        $this->assertSame(3, $calls);
    }

    public function testNoRetryOn4xx(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;
            return new MockResponse('{"error":"bad"}', ['http_code' => 400]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 3, backoffMs: [1, 1, 1]);
        try {
            $r->jsonRequest('POST', '/v1/dispatch');
            $this->fail('expected BadRequestException');
        } catch (BadRequestException) {
            $this->assertSame(1, $calls);
        }
    }

    public function testExhaustsAndThrows(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;
            return new MockResponse('', ['http_code' => 503]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 3, backoffMs: [1, 1, 1]);
        try {
            $r->jsonRequest('GET', '/v1/dugdale');
            $this->fail('expected DispatchException');
        } catch (DispatchException) {
            $this->assertSame(3, $calls);
        }
    }

    public function testDoesNotRetryClientError4xx(): void
    {
        // A 404/410/429 etc. surfaces as DispatchException but is a client-side
        // condition — retrying is wasteful/harmful, so it must run exactly once.
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;
            return new MockResponse('{"error":"not_found"}', ['http_code' => 404]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 3, backoffMs: [1, 1, 1]);
        try {
            $r->jsonRequest('GET', '/v1/missions/x');
            $this->fail('expected DispatchException');
        } catch (DispatchException $e) {
            $this->assertSame(1, $calls);
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testBodyReSentOnEachAttempt(): void
    {
        $seenBodies = [];
        $calls = 0;
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$seenBodies, &$calls) {
            $seenBodies[] = $options['body'] ?? '';
            $calls++;
            return $calls < 3
                ? new MockResponse('', ['http_code' => 503])
                : new MockResponse('{"ok":1}', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 3, backoffMs: [1, 1, 1]);
        $r->jsonRequest('POST', '/v1/dispatch', body: ['hello' => 'world']);
        $this->assertCount(3, $seenBodies);
        foreach ($seenBodies as $b) {
            $this->assertSame('{"hello":"world"}', $b);
        }
    }
}
