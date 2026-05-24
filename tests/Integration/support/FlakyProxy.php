<?php
declare(strict_types=1);

namespace Letts\Tests\Integration\support;

/**
 * Manages a flaky_proxy.php child process for fault-injection tests.
 * See flaky_proxy.php for the supported modes (relay / drop / cut).
 */
final class FlakyProxy
{
    /** @var resource|null */
    private $proc = null;
    private string $logPath = '';

    public function __construct(public readonly int $listenPort) {}

    public static function start(int $upstreamPort, string $mode, string $pattern, int $param = 0): self
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $listenPort);
        socket_close($sock);

        $p = new self($listenPort);
        $p->logPath = sys_get_temp_dir() . '/letts-proxy-' . uniqid() . '.log';
        $cmd = [
            PHP_BINARY, __DIR__ . '/flaky_proxy.php',
            (string) $listenPort, (string) $upstreamPort, $mode, $pattern, (string) $param,
        ];
        $p->proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', $p->logPath, 'w'], 2 => ['file', $p->logPath, 'a']],
            $pipes,
        );
        if (!is_resource($p->proc)) {
            throw new \RuntimeException('failed to spawn flaky proxy');
        }
        // Wait until the proxy accepts connections.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client("tcp://127.0.0.1:$listenPort", $errno, $err, 0.2);
            if ($probe !== false) {
                fclose($probe);
                return $p;
            }
            usleep(20_000);
        }
        $p->stop();
        throw new \RuntimeException("flaky proxy did not start on port $listenPort");
    }

    public function log(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
            }
            proc_close($this->proc);
            $this->proc = null;
        }
    }
}
