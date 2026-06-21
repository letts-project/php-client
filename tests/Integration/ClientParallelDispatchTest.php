<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Client;
use PHPUnit\Framework\TestCase;

/**
 * Proves runParallel/runOnAll issue their dispatch POSTs concurrently, not one
 * host at a time. Three backends each delay /v1/dispatch by ~1s; a parallel
 * fan-out finishes in ~1s, a serial one in ~3s. Needs the `php` binary and
 * ext-curl; skips cleanly otherwise.
 */
final class ClientParallelDispatchTest extends TestCase
{
    /** @var array<int, array{proc: resource, port: int}> */
    private array $backends = [];
    private string $configPath = '';

    protected function setUp(): void
    {
        if (!\extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl not loaded');
        }
        for ($n = 0; $n < 3; $n++) {
            $port = $this->freePort();
            $proc = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/support/slow_dispatch_backend.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($proc)) {
                $this->markTestSkipped('cannot start php -S backend');
            }
            $this->waitForPort($port);
            $this->backends[] = ['proc' => $proc, 'port' => $port];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backends as $b) {
            if (is_resource($b['proc'])) {
                $status = proc_get_status($b['proc']);
                if ($status['running'] && \function_exists('posix_kill')) {
                    posix_kill($status['pid'], SIGKILL);
                }
                proc_close($b['proc']);
            }
        }
        if ($this->configPath !== '') {
            @unlink($this->configPath);
        }
    }

    public function testRunParallelDispatchesConcurrently(): void
    {
        $yaml = "dugdales:\n";
        $jobs = [];
        foreach ($this->backends as $k => $b) {
            $id = 's' . ($k + 1);
            $yaml .= "  - id: $id\n    host: 127.0.0.1\n    port: {$b['port']}\n    token: t\n"
                . "    lanes: {normal: {concurrency: 1}}\n";
            $jobs[] = ['host' => $id, 'lane' => 'normal', 'mission' => 'X'];
        }
        $this->configPath = tempnam(sys_get_temp_dir(), 'letts-par-cfg');
        chmod($this->configPath, 0600);
        file_put_contents($this->configPath, $yaml);
        $client = Client::fromConfig($this->configPath);

        $t0 = microtime(true);
        $results = $client->runParallel($jobs, waitTimeout: '20s');
        $elapsed = microtime(true) - $t0;

        $this->assertCount(3, $results);
        foreach ($results as $r) {
            $this->assertTrue($r->isSuccess(), "job failed: " . ($r->error->message ?? ''));
        }
        // Serial dispatch would be ~3s (3 x 1s). Concurrent is ~1s. Allow ample
        // slack for slow CI while staying well under the serial figure.
        $this->assertLessThan(
            2.5,
            $elapsed,
            "fan-out took {$elapsed}s — dispatch POSTs are not running concurrently",
        );
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
