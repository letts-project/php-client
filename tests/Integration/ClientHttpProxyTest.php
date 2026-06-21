<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Client;
use Letts\Config\Scope;
use Letts\Exceptions\NetworkException;
use PHPUnit\Framework\TestCase;

/**
 * Proves a dugdale `proxy:` with an http:// scheme routes through a real HTTP
 * forward proxy (the common case — e.g. a Squid on :3128). A local forward
 * proxy fronts a `php -S` backend; the request reaches the backend only through
 * the proxy. Needs ext-curl, ext-pcntl, ext-posix, and the `php` binary; skips
 * cleanly otherwise.
 */
final class ClientHttpProxyTest extends TestCase
{
    /** @var resource|null */
    private $backend = null;
    private int $backendPort = 0;
    /** @var resource|null */
    private $proxy = null;
    private int $proxyPort = 0;
    private string $proxyLog = '';
    private string $configPath = '';

    protected function setUp(): void
    {
        foreach (['curl', 'pcntl', 'posix'] as $ext) {
            if (!\extension_loaded($ext)) {
                $this->markTestSkipped("ext-$ext not loaded");
            }
        }
        $this->backendPort = $this->freePort();
        $this->backend = $this->spawn([PHP_BINARY, '-S', "127.0.0.1:$this->backendPort", __DIR__ . '/support/proxy_backend.php']);
        $this->waitForPort($this->backendPort);

        $this->proxyPort = $this->freePort();
        $this->proxyLog = sys_get_temp_dir() . '/letts-http-proxy-' . uniqid() . '.log';
        $this->proxy = $this->spawn([PHP_BINARY, __DIR__ . '/support/http_proxy_server.php', (string) $this->proxyPort, $this->proxyLog]);
        $this->waitForPort($this->proxyPort);
    }

    protected function tearDown(): void
    {
        foreach ([$this->proxy, $this->backend] as $p) {
            if (is_resource($p)) {
                $st = proc_get_status($p);
                if ($st['running']) {
                    posix_kill($st['pid'], SIGKILL);
                }
                proc_close($p);
            }
        }
        if ($this->configPath !== '') {
            @unlink($this->configPath);
        }
        if ($this->proxyLog !== '') {
            @unlink($this->proxyLog);
        }
    }

    public function testRequestRoutesThroughHttpProxy(): void
    {
        $client = $this->clientWithProxy("http://127.0.0.1:{$this->proxyPort}");
        $out = $client->rawTransportFor('d1', Scope::Dispatch)->jsonRequest('GET', '/v1/ping');

        $this->assertTrue($out['ok'] ?? false);
        $this->assertStringContainsString(
            "PROXY GET 127.0.0.1:{$this->backendPort}/v1/ping",
            (string) @file_get_contents($this->proxyLog),
            'the request must have gone through the HTTP proxy',
        );
    }

    public function testUnreachableHttpProxyFailsInsteadOfBypassing(): void
    {
        $client = $this->clientWithProxy('http://127.0.0.1:1');
        $this->expectException(NetworkException::class);
        $client->rawTransportFor('d1', Scope::Dispatch)->jsonRequest('GET', '/v1/ping');
    }

    private function clientWithProxy(string $proxy): Client
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'letts-http-proxy-cfg');
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

    /** @param list<string> $cmd @return resource */
    private function spawn(array $cmd)
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $this->markTestSkipped('cannot spawn ' . $cmd[0]);
        }
        return $proc;
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
        $this->markTestSkipped("service did not start on port $port");
    }
}
