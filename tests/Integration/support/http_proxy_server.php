<?php
// Minimal forward HTTP proxy for the integration test, proving curl (and thus
// symfony/http-client) honors an http:// proxy URL. It handles absolute-form
// requests (GET http://host:port/path) by connecting to the target, replaying
// the request in origin-form, and piping the response back. Each connection is
// handled in a forked child. Every proxied request is appended to the log so
// the test can assert traffic actually went through the proxy.
//
// Usage: php http_proxy_server.php <listen_port> <log_path>
declare(strict_types=1);

$listenPort = (int) ($argv[1] ?? 0);
$logPath    = (string) ($argv[2] ?? '');

$server = @stream_socket_server("tcp://127.0.0.1:$listenPort", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "http-proxy: listen failed: $errstr\n");
    exit(1);
}

pcntl_async_signals(true);
pcntl_signal(SIGCHLD, function (): void {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
    }
});

while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($server);
        handleClient($client, $logPath);
        exit(0);
    }
    fclose($client);
}

function handleClient($client, string $logPath): void
{
    stream_set_blocking($client, true);

    // Read request head (up to the blank line). Bodies are not needed: the test
    // proxies a GET.
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($client, 4096);
        if ($chunk === '' || $chunk === false) {
            fclose($client);
            return;
        }
        $head .= $chunk;
    }
    $lines = explode("\r\n", $head);
    if (!preg_match('#^(\S+)\s+(\S+)\s+(HTTP/\d\.\d)$#', $lines[0], $m)) {
        fclose($client);
        return;
    }
    [, $method, $uri, $ver] = $m;
    $u = parse_url($uri);
    if (!isset($u['host'])) {
        fwrite($client, "HTTP/1.1 400 Bad Request\r\n\r\n");
        fclose($client);
        return;
    }
    $host = $u['host'];
    $port = $u['port'] ?? 80;
    $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');

    if ($logPath !== '') {
        file_put_contents($logPath, "PROXY $method $host:$port$path\n", FILE_APPEND);
    }

    $upstream = @stream_socket_client("tcp://$host:$port", $e1, $e2, 5);
    if ($upstream === false) {
        fwrite($client, "HTTP/1.1 502 Bad Gateway\r\n\r\n");
        fclose($client);
        return;
    }
    // Replay in origin-form, dropping hop-by-hop proxy headers and forcing a
    // single response per connection.
    $out = "$method $path $ver\r\n";
    foreach (array_slice($lines, 1) as $h) {
        if ($h === '') {
            break;
        }
        if (stripos($h, 'Proxy-Connection:') === 0 || stripos($h, 'Connection:') === 0) {
            continue;
        }
        $out .= $h . "\r\n";
    }
    $out .= "Connection: close\r\n\r\n";
    fwrite($upstream, $out);
    stream_copy_to_stream($upstream, $client);
    fclose($upstream);
    fclose($client);
}
