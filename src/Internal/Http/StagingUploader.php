<?php
declare(strict_types=1);

namespace Letts\Internal\Http;

use Letts\Exceptions\StagingException;
use Letts\Internal\IdsUuidV7;

/**
 * Resumable staging upload using the dispatch-safe workflow:
 *
 *   1. Compute the sha256 and size of the local file.
 *   2. Generate a client-side UUIDv7 staging_id.
 *   3. HEAD /v1/staging/<id>:
 *        - 404            → initial PUT (full body, no Content-Range).
 *        - 200 complete   → already uploaded, reuse the id (no PUT).
 *        - 200 incomplete → resume PUT from X-Letts-Bytes-Received via Content-Range.
 *   4. The whole probe-then-PUT sequence is wrapped in RetryClient::run, so a mid-PUT 5xx /
 *      network failure re-probes (HEAD) and resumes from the offset the server
 *      actually accepted.
 *
 * NOTE: this intentionally does NOT use GET /v1/staging/by-content — that
 * endpoint is exec/admin-only; a dispatch token gets 403. Content
 * dedup across dispatches is therefore unavailable to dispatch clients by
 * design; idempotency of a single upload is provided by the resumable PUT.
 */
final class StagingUploader
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly RetryClient $retry,
    ) {}

    /** @return array{staging_id: string, sha256: string, size: int} */
    public function upload(string $localPath): array
    {
        if (!is_file($localPath)) {
            throw new StagingException("file not found: $localPath");
        }
        $sha = hash_file('sha256', $localPath);
        $size = filesize($localPath);
        if ($sha === false || $size === false) {
            throw new StagingException("hash/size failed: $localPath");
        }

        $stagingId = IdsUuidV7::generate();

        return $this->retry->run(function () use ($stagingId, $localPath, $size, $sha): array {
            $probe = $this->transport->head("/v1/staging/$stagingId");
            if ($probe['uploadStatus'] === 'complete') {
                return ['staging_id' => $stagingId, 'sha256' => $sha, 'size' => $size];
            }
            $offset = $probe['uploadStatus'] === 'incomplete'
                ? (int) ($probe['bytesReceived'] ?? 0)
                : 0;
            $this->putRange($stagingId, $localPath, $size, $offset, $sha);
            return ['staging_id' => $stagingId, 'sha256' => $sha, 'size' => $size];
        });
    }

    private function putRange(string $id, string $path, int $size, int $offset, string $sha): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new StagingException("cannot open $path");
        }
        if ($offset > 0) {
            fseek($fh, $offset);
        }
        try {
            // Symfony HttpClient streams chunked when the body is a resource and
            // no Content-Length is given; dugdale requires Content-Length on the
            // initial PUT, so we set it explicitly. Content-Range is sent only
            // when resuming (initial PUT must omit it).
            $headers = [
                'X-Letts-Sha256' => $sha,
                'Content-Length' => (string) ($size - $offset),
            ];
            if ($offset > 0) {
                $headers['Content-Range'] = "bytes $offset-" . ($size - 1) . "/$size";
            }
            $this->transport->rawRequest('PUT', "/v1/staging/$id", $fh, $headers);
        } finally {
            fclose($fh);
        }
    }
}
