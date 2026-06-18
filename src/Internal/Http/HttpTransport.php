<?php
declare(strict_types=1);

namespace Letts\Internal\Http;

use Letts\Exceptions\AuthException;
use Letts\Exceptions\BackpressureException;
use Letts\Exceptions\BadRequestException;
use Letts\Exceptions\ConflictException;
use Letts\Exceptions\DispatchException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper around symfony/http-client that injects Bearer auth header,
 * marshals JSON request/response, and maps HTTP error statuses to the public
 * Letts\Exceptions\* hierarchy. No retry logic here — wrap in RetryClient.
 */
final class HttpTransport
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $host = '',
        private readonly bool $streamFreshConnection = false,
    ) {}

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     * @return array<string, mixed>
     */
    public function jsonRequest(
        string $method,
        string $path,
        ?array $body = null,
        array $extraHeaders = [],
    ): array {
        $headers = ['Authorization: Bearer ' . $this->token];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        $opts = ['headers' => $headers];
        if ($body !== null) {
            $opts['body'] = json_encode($body, JSON_THROW_ON_ERROR);
            $opts['headers'][] = 'Content-Type: application/json';
        }

        try {
            $response = $this->client->request($method, $this->baseUrl . $path, $opts);
            $status = $response->getStatusCode();
            $content = $response->getContent(throw: false);
        } catch (TransportException $e) {
            throw new \Letts\Exceptions\NetworkException($this->host, $e->getMessage(), $e);
        }

        if ($status >= 400) {
            throw self::mapError($status, $content);
        }
        if ($content === '') {
            return [];
        }
        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * HEAD a staging object and return its upload state from response headers
     * (HEAD has no body). Used by StagingUploader for resume-aware uploads.
     * 404 → not found (status only). 4xx/5xx (other) → mapped
     * exception so RetryClient can retry transient 5xx. Network failure →
     * NetworkException.
     *
     * @return array{status: int, uploadStatus: ?string, bytesReceived: ?int, totalSize: ?int}
     */
    public function head(string $path): array
    {
        try {
            $response = $this->client->request('HEAD', $this->baseUrl . $path, [
                'headers' => ['Authorization: Bearer ' . $this->token],
            ]);
            $status = $response->getStatusCode();
        } catch (TransportException $e) {
            throw new \Letts\Exceptions\NetworkException($this->host, $e->getMessage(), $e);
        }
        if ($status === 404) {
            return ['status' => 404, 'uploadStatus' => null, 'bytesReceived' => null, 'totalSize' => null];
        }
        if ($status >= 400) {
            throw self::mapError($status, '');
        }
        $headers = $response->getHeaders(throw: false);
        $h = static fn(string $k): ?string => isset($headers[$k][0]) ? (string) $headers[$k][0] : null;
        $br = $h('x-letts-bytes-received');
        $ts = $h('x-letts-total-size');
        return [
            'status'        => $status,
            'uploadStatus'  => $h('x-letts-upload-status'),
            'bytesReceived' => $br !== null ? (int) $br : null,
            'totalSize'     => $ts !== null ? (int) $ts : null,
        ];
    }

    /** Returns raw streaming response for NDJSON consumers. */
    public function streamRequest(string $method, string $path): ResponseInterface
    {
        $opts = [
            'headers' => ['Authorization: Bearer ' . $this->token],
            'buffer'  => false,
        ];
        if ($this->streamFreshConnection) {
            // Force a dedicated, non-pooled TCP connection for the long-lived
            // events stream instead of reusing the dispatch POST's keep-alive
            // socket. Sometimes helps with Russian TSPU, that lets the first
            // request on a connection through but silently drops later segments
            // — the terminal `done` event — on a *reused* cross-border
            // connection, while a fresh connection passes. CurlHttpClient only;
            // ignored by other transports.
            $opts['extra']['curl'] = [
                \CURLOPT_FRESH_CONNECT => true,
                \CURLOPT_FORBID_REUSE  => true,
            ];
        }
        return $this->client->request($method, $this->baseUrl . $path, $opts);
    }

    /**
     * Chunk iterator over a streaming response. $timeout is the per-poll
     * inactivity window: an idle connection yields timeout chunks at that
     * cadence (callers skip them and use the wakeup for deadline checks)
     * instead of tripping the client-wide request timeout.
     */
    public function streamChunks(ResponseInterface $response, ?float $timeout = null): \Symfony\Contracts\HttpClient\ResponseStreamInterface
    {
        return $this->client->stream($response, $timeout);
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * Raw request for non-JSON bodies (e.g. staging file PUT). Returns the
     * symfony response so caller can inspect status/headers. Throws via
     * mapError on 4xx/5xx.
     *
     * @param resource|string|null $body
     * @param array<string, string> $extraHeaders
     */
    public function rawRequest(
        string $method, string $path,
        mixed $body = null,
        array $extraHeaders = [],
        string $contentType = 'application/octet-stream',
    ): \Symfony\Contracts\HttpClient\ResponseInterface {
        $headers = ['Authorization: Bearer ' . $this->token];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        $opts = ['headers' => $headers];
        if ($body !== null) {
            $opts['body'] = $body;
            $opts['headers'][] = "Content-Type: $contentType";
        }
        try {
            $response = $this->client->request($method, $this->baseUrl . $path, $opts);
            $status = $response->getStatusCode();
        } catch (TransportException $e) {
            throw new \Letts\Exceptions\NetworkException($this->host, $e->getMessage(), $e);
        }
        if ($status >= 400) {
            throw self::mapError($status, $response->getContent(throw: false));
        }
        return $response;
    }

    public static function mapError(int $status, string $content): \Throwable
    {
        $body = $content !== '' ? @json_decode($content, true) : null;
        $code = is_array($body) ? (string) ($body['error'] ?? '') : '';
        $msg  = is_array($body) ? (string) ($body['message'] ?? '') : $content;

        // The HTTP status is carried as the exception code so callers
        // (getMission's 404 check) and RetryClient (no-retry on 4xx) can act on
        // it without parsing the message.
        return match (true) {
            $status === 400                                            => new BadRequestException($msg !== '' ? $msg : 'bad request', $status),
            $status === 401 || $status === 403                         => new AuthException($msg !== '' ? $msg : 'unauthorized', $status),
            $status === 409                                            => new ConflictException($msg !== '' ? $msg : 'conflict', $status),
            $status === 503 && in_array($code, ['queue_full', 'disk_quota_exceeded', 'disk_quota'], true)
                                                                       => new BackpressureException(($code !== '' ? "$code: " : '') . $msg, $status),
            default                                                    => new DispatchException("HTTP $status: $msg", $status),
        };
    }
}
