<?php
declare(strict_types=1);
// Fault-injecting TCP proxy for integration tests. Sits between the client
// and a dugdale instance and misbehaves on request-line match:
//
//   php flaky_proxy.php <listenPort> <upstreamPort> <mode> <pattern> [param]
//
// Modes:
//   relay      — pass everything through unchanged.
//   drop       — for EVERY request whose request line matches <pattern>:
//                forward it upstream, read (and discard) the whole response,
//                then close the client socket without sending a byte back.
//                The server has processed the request; the client only sees
//                a dead connection. Non-matching requests relay normally.
//   cut        — for the FIRST matching request: relay the response but slam
//                the connection shut after <param> total response bytes
//                (headers included). Later matching requests relay fully.
//
// One request per client connection; `Connection: close` is forced on the
// upstream leg so each exchange has a clear end. Clients reconnect per
// request, which is exactly what curl does after a peer close.

[, $listenPort, $upstreamPort, $mode] = $argv;
$pattern = $argv[4] ?? '//';
$param = (int) ($argv[5] ?? 0);
$cutUsed = false;

$srv = stream_socket_server("tcp://127.0.0.1:$listenPort", $errno, $err);
if (!$srv) {
    fwrite(STDERR, "proxy: listen failed: $err\n");
    exit(1);
}
fwrite(STDERR, "proxy: listening on $listenPort -> $upstreamPort mode=$mode\n");

while (($client = @stream_socket_accept($srv, 300)) !== false) {
    try {
        handleOne($client, (int) $upstreamPort, $mode, $pattern, $param, $cutUsed);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'proxy: ' . $e->getMessage() . "\n");
    } finally {
        if (is_resource($client)) {
            fclose($client);
        }
    }
}

function handleOne($client, int $upstreamPort, string $mode, string $pattern, int $param, bool &$cutUsed): void
{
    // --- read one full request (head and Content-Length body) ---
    $raw = '';
    while (($pos = strpos($raw, "\r\n\r\n")) === false) {
        $b = fread($client, 8192);
        if ($b === false || $b === '') {
            return; // client went away before finishing headers
        }
        $raw .= $b;
    }
    $head = substr($raw, 0, $pos + 4);
    $body = substr($raw, $pos + 4);
    if (preg_match('/^Content-Length:\s*(\d+)/mi', $head, $m)) {
        $need = (int) $m[1] - strlen($body);
        while ($need > 0) {
            $b = fread($client, min(65536, $need));
            if ($b === false || $b === '') {
                return;
            }
            $body .= $b;
            $need -= strlen($b);
        }
    }
    $requestLine = strtok($head, "\r\n");
    $matched = preg_match($pattern, $requestLine) === 1;
    fwrite(STDERR, "proxy: $requestLine" . ($matched ? " [match:$mode]" : '') . "\n");

    // --- forward upstream with Connection: close so the response has an end ---
    $up = stream_socket_client("tcp://127.0.0.1:$upstreamPort", $errno, $err, 5);
    if (!$up) {
        return;
    }
    $headOut = preg_replace('/^Connection:[^\r\n]*\r\n/mi', '', $head);
    $headOut = substr($headOut, 0, -2) . "Connection: close\r\n\r\n";
    fwrite($up, $headOut . $body);

    // --- relay (or swallow / cut) the response as bytes arrive ---
    $drop = $mode === 'drop' && $matched;
    $cutAt = ($mode === 'cut' && $matched && !$cutUsed) ? $param : null;
    if ($cutAt !== null) {
        $cutUsed = true;
    }
    $sent = 0;
    while (!feof($up)) {
        $b = fread($up, 65536);
        if ($b === false || $b === '') {
            continue;
        }
        if ($drop) {
            continue; // discard; the client never hears back
        }
        if ($cutAt !== null && $sent + strlen($b) >= $cutAt) {
            fwrite($client, substr($b, 0, max(0, $cutAt - $sent)));
            fwrite(STDERR, "proxy: cut connection after $cutAt bytes\n");
            fclose($up);
            return; // caller fcloses $client → mid-stream RST/EOF for the client
        }
        fwrite($client, $b);
        $sent += strlen($b);
    }
    fclose($up);
}
