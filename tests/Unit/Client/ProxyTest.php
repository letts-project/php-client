<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A per-dugdale proxy must reach the transport as the symfony `proxy` request
 * option (with no_proxy neutralized), and the `ignore_proxy` client option must
 * suppress it.
 */
final class ProxyTest extends TestCase
{
    /** @param array<string,mixed> $opts */
    private function clientWith(MockHttpClient $http, string $proxyLine, array $opts = []): Client
    {
        $tmp = tempnam(sys_get_temp_dir(), 'p');
        chmod($tmp, 0600);
        file_put_contents(
            $tmp,
            "dugdales:\n  - id: s1\n    host: h\n    port: 7180\n    token: t\n"
                . ($proxyLine !== '' ? "    proxy: \"$proxyLine\"\n" : '')
                . "    lanes: {normal: {concurrency: 1}}\n",
        );
        $c = Client::fromConfig($tmp, $opts, http: $http);
        unlink($tmp);
        return $c;
    }

    public function testProxyReachesTransport(): void
    {
        $seen = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$seen) {
            $seen = $options;
            return new MockResponse(json_encode(['mission_id' => 'id']), ['http_code' => 202]);
        });
        $client = $this->clientWith($http, 'socks5h://127.0.0.1:1080');
        $client->dispatch(host: 's1', lane: 'normal', mission: 'X');
        $this->assertSame('socks5h://127.0.0.1:1080', $seen['proxy'] ?? null);
        $this->assertSame('', $seen['no_proxy'] ?? null);
    }

    public function testSocks5NormalizedToSocks5hAtTransport(): void
    {
        $seen = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$seen) {
            $seen = $options;
            return new MockResponse(json_encode(['mission_id' => 'id']), ['http_code' => 202]);
        });
        $client = $this->clientWith($http, 'socks5://127.0.0.1:1080');
        $client->dispatch(host: 's1', lane: 'normal', mission: 'X');
        $this->assertSame('socks5h://127.0.0.1:1080', $seen['proxy'] ?? null);
    }

    public function testIgnoreProxySuppressesIt(): void
    {
        $seen = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$seen) {
            $seen = $options;
            return new MockResponse(json_encode(['mission_id' => 'id']), ['http_code' => 202]);
        });
        $client = $this->clientWith($http, 'socks5h://127.0.0.1:1080', ['ignore_proxy' => true]);
        $client->dispatch(host: 's1', lane: 'normal', mission: 'X');
        $this->assertArrayNotHasKey('proxy', $seen);
        $this->assertArrayNotHasKey('no_proxy', $seen);
    }

    public function testNoProxyConfiguredMeansNoProxyOption(): void
    {
        $seen = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$seen) {
            $seen = $options;
            return new MockResponse(json_encode(['mission_id' => 'id']), ['http_code' => 202]);
        });
        $client = $this->clientWith($http, '');
        $client->dispatch(host: 's1', lane: 'normal', mission: 'X');
        $this->assertArrayNotHasKey('proxy', $seen);
    }
}
