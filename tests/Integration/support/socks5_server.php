<?php
// Minimal SOCKS5 CONNECT proxy used by the integration tests to prove that
// curl (and therefore symfony/http-client) honors a socks5h:// proxy URL
// end to end. Supports the no-authentication method and the CONNECT command
// with IPv4, domain, and IPv6 target addresses, then relays bytes both ways.
// Each accepted connection is handled in a forked child so concurrent
// connections never block each other. Every CONNECT target is appended to the
// log file so the test can assert the request actually went through the proxy.
//
// Usage: php socks5_server.php <listen_port> <log_path>
declare(strict_types=1);

$listenPort = (int) ($argv[1] ?? 0);
$logPath    = (string) ($argv[2] ?? '');

$server = @stream_socket_server("tcp://127.0.0.1:$listenPort", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "socks5: listen failed: $errstr\n");
    exit(1);
}

pcntl_async_signals(true);
pcntl_signal(SIGCHLD, function (): void {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        // reap finished children
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

function readExactly($sock, int $n): string
{
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($sock, $n - strlen($buf));
        if ($chunk === '' || $chunk === false) {
            return $buf; // short read: peer closed
        }
        $buf .= $chunk;
    }
    return $buf;
}

function handleClient($client, string $logPath): void
{
    stream_set_blocking($client, true);

    // Greeting: VER, NMETHODS, METHODS...
    $hdr = readExactly($client, 2);
    if (strlen($hdr) < 2 || ord($hdr[0]) !== 0x05) {
        fclose($client);
        return;
    }
    readExactly($client, ord($hdr[1])); // discard the offered methods
    fwrite($client, "\x05\x00");        // select no-authentication

    // Request: VER CMD RSV ATYP ...
    $req = readExactly($client, 4);
    if (strlen($req) < 4 || ord($req[1]) !== 0x01) { // only CONNECT
        fwrite($client, "\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00");
        fclose($client);
        return;
    }
    $atyp = ord($req[3]);
    if ($atyp === 0x01) {
        $host = inet_ntop(readExactly($client, 4));
    } elseif ($atyp === 0x03) {
        $len = ord(readExactly($client, 1));
        $host = readExactly($client, $len);
    } elseif ($atyp === 0x04) {
        $host = inet_ntop(readExactly($client, 16));
    } else {
        fclose($client);
        return;
    }
    $portRaw = readExactly($client, 2);
    $port = (ord($portRaw[0]) << 8) | ord($portRaw[1]);

    if ($logPath !== '') {
        file_put_contents($logPath, "CONNECT $host:$port\n", FILE_APPEND);
    }

    $upstream = @stream_socket_client("tcp://$host:$port", $e1, $e2, 5);
    if ($upstream === false) {
        fwrite($client, "\x05\x01\x00\x01\x00\x00\x00\x00\x00\x00"); // general failure
        fclose($client);
        return;
    }
    fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00"); // success
    relay($client, $upstream);
}

function relay($a, $b): void
{
    stream_set_blocking($a, false);
    stream_set_blocking($b, false);
    while (true) {
        $read = [$a, $b];
        $write = $except = [];
        if (@stream_select($read, $write, $except, 1) === false) {
            break;
        }
        foreach ($read as $r) {
            $data = fread($r, 8192);
            if ($data === '' || $data === false) {
                fclose($a);
                fclose($b);
                return;
            }
            fwrite($r === $a ? $b : $a, $data);
        }
    }
}
