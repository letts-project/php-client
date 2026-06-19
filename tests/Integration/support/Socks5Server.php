<?php
declare(strict_types=1);

namespace Letts\Tests\Integration\support;

/**
 * Manages a socks5_server.php child process for the proxy integration test.
 * Picks a free loopback port, spawns the server, and waits until it accepts
 * connections. The CONNECT log lets the test assert traffic went through it.
 */
final class Socks5Server
{
    /** @var resource|null */
    private $proc = null;

    private function __construct(
        public readonly int $port,
        public readonly string $logPath,
    ) {}

    public static function start(): self
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new \RuntimeException("cannot pick a port: $errstr");
        }
        $name = stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $logPath = sys_get_temp_dir() . '/letts-socks5-' . uniqid() . '.log';
        $p = new self($port, $logPath);
        $p->proc = proc_open(
            [PHP_BINARY, __DIR__ . '/socks5_server.php', (string) $port, $logPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($p->proc)) {
            throw new \RuntimeException('failed to spawn socks5 server');
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $c = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.2);
            if ($c !== false) {
                fclose($c);
                return $p;
            }
            usleep(20_000);
        }
        $p->stop();
        throw new \RuntimeException("socks5 server did not start on port $port");
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
        @unlink($this->logPath);
    }
}
