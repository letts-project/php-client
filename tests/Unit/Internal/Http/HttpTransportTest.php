<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Http;

use Letts\Exceptions\AuthException;
use Letts\Exceptions\BackpressureException;
use Letts\Exceptions\BadRequestException;
use Letts\Exceptions\ConflictException;
use Letts\Exceptions\DispatchException;
use Letts\Internal\Http\HttpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpTransportTest extends TestCase
{
    public function testInjectsBearerHeader(): void
    {
        $seen = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options['headers'] ?? [];
            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h:7180', 'tok-abc');
        $t->jsonRequest('GET', '/v1/dugdale');
        $this->assertContains('Authorization: Bearer tok-abc', $seen);
    }

    public function testDecodesJsonBody(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse('{"version":"v1"}', ['http_code' => 200]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $out = $t->jsonRequest('GET', '/v1/dugdale');
        $this->assertSame(['version' => 'v1'], $out);
    }

    public function testMaps400ToBadRequest(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse('{"error":"bad","message":"x"}', ['http_code' => 400]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $this->expectException(BadRequestException::class);
        $t->jsonRequest('POST', '/v1/dispatch');
    }

    public function testMaps401ToAuth(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse('', ['http_code' => 401]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $this->expectException(AuthException::class);
        $t->jsonRequest('GET', '/v1/dugdale');
    }

    public function testMaps409ToConflict(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse('', ['http_code' => 409]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $this->expectException(ConflictException::class);
        $t->jsonRequest('POST', '/v1/dispatch');
    }

    public function testMaps503QueueFullToBackpressure(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse(
            '{"error":"queue_full","message":"full"}',
            ['http_code' => 503],
        ));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $this->expectException(BackpressureException::class);
        $t->jsonRequest('POST', '/v1/dispatch');
    }

    public function testMaps500ToDispatch(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse('', ['http_code' => 500]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $this->expectException(DispatchException::class);
        $t->jsonRequest('GET', '/v1/dugdale');
    }

    public function testMaps503DiskQuotaExceededToBackpressure(): void
    {
        // The real daemon sends `disk_quota_exceeded` (not `disk_quota`).
        $mock = new MockHttpClient(fn() => new MockResponse(
            '{"error":"disk_quota_exceeded","message":"full"}',
            ['http_code' => 503],
        ));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $this->expectException(BackpressureException::class);
        $t->jsonRequest('POST', '/v1/dispatch');
    }

    public function testExceptionCarriesHttpStatusAsCode(): void
    {
        $mock = new MockHttpClient(fn() => new MockResponse('{"error":"not_found"}', ['http_code' => 404]));
        $t = new HttpTransport($mock, 'http://h', 'tok');
        try {
            $t->jsonRequest('GET', '/v1/missions/x');
            $this->fail('expected DispatchException');
        } catch (DispatchException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testNetworkErrorCarriesHost(): void
    {
        $mock = new MockHttpClient(function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });
        $t = new HttpTransport($mock, 'http://h', 'tok', 's7');
        try {
            $t->jsonRequest('GET', '/v1/dugdale');
            $this->fail('expected NetworkException');
        } catch (\Letts\Exceptions\NetworkException $e) {
            $this->assertSame('s7', $e->getHost());
        }
    }

    public function testStreamRequestDefaultReusesConnection(): void
    {
        $seen = null;
        $mock = new MockHttpClient(function (string $m, string $u, array $options) use (&$seen) {
            $seen = $options;
            return new MockResponse('', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h:7180', 'tok');
        $t->streamRequest('GET', '/v1/missions/x/events?follow=true')->getStatusCode();
        $this->assertFalse($seen['buffer']);
        $this->assertArrayNotHasKey('extra', $seen, 'default must not force a fresh connection');
    }

    public function testStreamRequestForcesFreshConnectionWhenEnabled(): void
    {
        $seen = null;
        $mock = new MockHttpClient(function (string $m, string $u, array $options) use (&$seen) {
            $seen = $options;
            return new MockResponse('', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h:7180', 'tok', 's1', streamFreshConnection: true);
        $t->streamRequest('GET', '/v1/missions/x/events?follow=true')->getStatusCode();
        $this->assertFalse($seen['buffer']);
        $this->assertTrue($seen['extra']['curl'][\CURLOPT_FRESH_CONNECT] ?? null);
        $this->assertTrue($seen['extra']['curl'][\CURLOPT_FORBID_REUSE] ?? null);
    }

    public function testProxyInjectedIntoJsonRequest(): void
    {
        $seen = null;
        $mock = new MockHttpClient(function (string $m, string $u, array $options) use (&$seen) {
            $seen = $options;
            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h:7180', 'tok', 's1', proxy: 'socks5h://127.0.0.1:1080');
        $t->jsonRequest('GET', '/v1/dugdale');
        $this->assertSame('socks5h://127.0.0.1:1080', $seen['proxy'] ?? null);
        // no_proxy must be forced empty so an ambient NO_PROXY can't bypass us.
        $this->assertSame('', $seen['no_proxy'] ?? null);
    }

    public function testProxyInjectedIntoStreamRequest(): void
    {
        $seen = null;
        $mock = new MockHttpClient(function (string $m, string $u, array $options) use (&$seen) {
            $seen = $options;
            return new MockResponse('', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h:7180', 'tok', 's1', proxy: 'socks5h://127.0.0.1:1080');
        $t->streamRequest('GET', '/v1/missions/x/events?follow=true')->getStatusCode();
        $this->assertSame('socks5h://127.0.0.1:1080', $seen['proxy'] ?? null);
    }

    public function testNoProxyOptionWhenUnset(): void
    {
        $seen = null;
        $mock = new MockHttpClient(function (string $m, string $u, array $options) use (&$seen) {
            $seen = $options;
            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $t = new HttpTransport($mock, 'http://h:7180', 'tok', 's1');
        $t->jsonRequest('GET', '/v1/dugdale');
        $this->assertArrayNotHasKey('proxy', $seen);
        $this->assertArrayNotHasKey('no_proxy', $seen);
    }
}
