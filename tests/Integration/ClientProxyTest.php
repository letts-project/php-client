<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Client;
use Letts\Config\Scope;
use Letts\Exceptions\NetworkException;
use Letts\Tests\Integration\support\Socks5Server;
use PHPUnit\Framework\TestCase;

/**
 * Proves end to end that a per-dugdale socks5h:// proxy is actually honored by
 * the real curl transport — the one thing a MockHttpClient cannot show. A local
 * SOCKS5 server fronts a `php -S` backend; the client reaches the backend only
 * if curl tunnels through the proxy. Needs ext-curl, ext-pcntl, ext-posix, and
 * the `php` binary; skips cleanly otherwise.
 */
final class ClientProxyTest extends TestCase
{
    /** @var resource|null */
    private $backend = null;
    private int $backendPort = 0;
    private ?Socks5Server $socks = null;
    private string $configPath = '';

    protected function setUp(): void
    {
        foreach (['curl', 'pcntl', 'posix', 'sockets'] as $ext) {
            if (!\extension_loaded($ext)) {
                $this->markTestSkipped("ext-$ext not loaded");
            }
        }
        $this->backendPort = $this->freePort();
        $this->backend = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$this->backendPort", __DIR__ . '/support/proxy_backend.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($this->backend)) {
            $this->markTestSkipped('cannot start php -S backend');
        }
        $this->waitForPort($this->backendPort);
        $this->socks = Socks5Server::start();
    }

    protected function tearDown(): void
    {
        if ($this->socks !== null) {
            $this->socks->stop();
        }
        if (is_resource($this->backend)) {
            $status = proc_get_status($this->backend);
            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
            }
            proc_close($this->backend);
        }
        if ($this->configPath !== '') {
            @unlink($this->configPath);
        }
    }

    public function testRequestRoutesThroughSocks5Proxy(): void
    {
        $client = $this->clientWithProxy("socks5h://127.0.0.1:{$this->socks->port}");
        $out = $client->rawTransportFor('d1', Scope::Dispatch)->jsonRequest('GET', '/v1/ping');

        $this->assertTrue($out['ok'] ?? false);
        $this->assertStringContainsString(
            "CONNECT 127.0.0.1:{$this->backendPort}",
            $this->socks->log(),
            'the request must have tunneled through the SOCKS5 proxy',
        );
    }

    public function testSocks5SchemeIsAlsoHonored(): void
    {
        // socks5:// is normalized to socks5h:// by ProxyResolver, so it must
        // still route through the proxy.
        $client = $this->clientWithProxy("socks5://127.0.0.1:{$this->socks->port}");
        $out = $client->rawTransportFor('d1', Scope::Dispatch)->jsonRequest('GET', '/v1/ping');
        $this->assertTrue($out['ok'] ?? false);
        $this->assertStringContainsString("CONNECT 127.0.0.1:{$this->backendPort}", $this->socks->log());
    }

    public function testUnreachableProxyFailsInsteadOfBypassing(): void
    {
        // Point at a dead proxy port: if the proxy were silently bypassed the
        // request would still reach the backend. It must fail instead.
        $client = $this->clientWithProxy('socks5h://127.0.0.1:1');
        $this->expectException(NetworkException::class);
        $client->rawTransportFor('d1', Scope::Dispatch)->jsonRequest('GET', '/v1/ping');
    }

    private function clientWithProxy(string $proxy): Client
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'letts-proxy-cfg');
        chmod($this->configPath, 0600);
        file_put_contents($this->configPath, <<<YAML
            dugdales:
              - id: d1
                host: 127.0.0.1
                port: {$this->backendPort}
                token: t
                proxy: "$proxy"
            YAML);
        return Client::fromConfig($this->configPath);
    }

    private function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($s === false) {
            $this->markTestSkipped("cannot pick a port: $errstr");
        }
        $name = stream_socket_get_name($s, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($s);
        return $port;
    }

    private function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $c = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.2);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(20_000);
        }
        $this->markTestSkipped("backend did not start on port $port");
    }
}
