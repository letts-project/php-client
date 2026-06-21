<?php
// Backend for the parallel-dispatch timing test, served by `php -S`. Each
// POST /v1/dispatch sleeps ~1s before replying, so a fan-out that issues the
// POSTs concurrently finishes in ~1s while a serial one takes ~N*1s. The
// events route returns a single terminal `done` line immediately.
$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH) ?? '';

if ($path === '/v1/dispatch') {
    usleep(1_000_000); // 1s — the artificial per-host dispatch latency
    $body = json_decode((string) file_get_contents('php://input'), true);
    $mid = is_array($body) ? (string) ($body['mission_id'] ?? 'm1') : 'm1';
    header('Content-Type: application/json');
    echo json_encode(['mission_id' => $mid]);
    return;
}

if (preg_match('#^/v1/missions/[^/]+/events$#', $path)) {
    header('Content-Type: application/x-ndjson');
    echo json_encode(['seq' => 1, 'event' => 'done', 'outcome' => 'success', 'duration_ms' => 1]) . "\n";
    return;
}

http_response_code(404);
echo '{}';
