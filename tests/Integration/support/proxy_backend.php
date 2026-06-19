<?php
// Tiny HTTP backend for the proxy integration test, served by `php -S`. It
// replies 200 with a JSON body for any request so the client can confirm the
// request arrived (through the SOCKS5 proxy).
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'path' => $_SERVER['REQUEST_URI'] ?? '']);
