<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use Letts\Exceptions\BadRequestException;
use Letts\Exceptions\StagingException;
use Letts\Result\Logs;
use Letts\Result\RunResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for Client::downloadOutput()'s in-memory staging fetch and
 * every guard it adds — exercised through MockHttpClient so no live dugdale
 * is needed (the integration happy-path lives in ClientRunOutputFileTest).
 */
final class DownloadOutputTest extends TestCase
{
    private function client(MockHttpClient $http): Client
    {
        $tmp = tempnam(sys_get_temp_dir(), 'r');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n    lanes: {normal: {concurrency: 1}}\n");
        $c = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        return $c;
    }

    /** @param array<string, array{staging_id?: string, size?: int, sha256?: string}> $outputFiles */
    private function makeResult(array $outputFiles): RunResult
    {
        return new RunResult(
            host: 's1', missionId: 'mid', outcome: 'success',
            failReason: null, failMessage: null, failDetails: null,
            return: null, exitCode: 0, signal: null, durationMs: 0,
            logs: new Logs(), outputFiles: $outputFiles,
        );
    }

    /** A client whose only expected call is GET /v1/staging/<sid>. */
    private function stagingClient(string $sid, MockResponse $response): Client
    {
        return $this->client(new MockHttpClient(function ($method, $url) use ($sid, $response) {
            if ($method === 'GET' && str_contains($url, "/v1/staging/$sid")) {
                return $response;
            }
            throw new \RuntimeException("unexpected request: $method $url");
        }));
    }

    public function testReturnsVerifiedBytes(): void
    {
        $body = 'hello-from-mission';
        $r = $this->makeResult(['result' => [
            'staging_id' => 'sid-1', 'size' => strlen($body), 'sha256' => hash('sha256', $body),
        ]]);
        $client = $this->stagingClient('sid-1', new MockResponse([$body], ['http_code' => 200]));
        $this->assertSame($body, $client->downloadOutput($r, 'result'));
    }

    public function testUnknownKeyThrowsBadRequestAndListsKeys(): void
    {
        // No HTTP request must be made for a key that doesn't exist, and the
        // error stays inside the LettsException hierarchy (see §11.5).
        $client = $this->client(new MockHttpClient(function ($method, $url) {
            throw new \RuntimeException("unexpected request: $method $url");
        }));
        $r = $this->makeResult(['result' => ['staging_id' => 'sid-1', 'size' => 1, 'sha256' => 'x']]);
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('no output file with key "missing" (have: result)');
        $client->downloadOutput($r, 'missing');
    }

    public function testEmptyStagingIdThrowsStaging(): void
    {
        $client = $this->client(new MockHttpClient(function ($method, $url) {
            throw new \RuntimeException("unexpected request: $method $url");
        }));
        $r = $this->makeResult(['result' => ['staging_id' => '', 'size' => 1, 'sha256' => 'x']]);
        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('output "result" has no staging id');
        $client->downloadOutput($r, 'result');
    }

    public function testHttpErrorThrowsStaging(): void
    {
        $r = $this->makeResult(['result' => ['staging_id' => 'sid-1', 'size' => 1, 'sha256' => 'x']]);
        $client = $this->stagingClient('sid-1', new MockResponse('', ['http_code' => 404]));
        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('staging sid-1 not downloadable: HTTP 404');
        $client->downloadOutput($r, 'result');
    }

    public function testTruncatedBodyThrowsSizeMismatch(): void
    {
        $body = 'hi';
        $r = $this->makeResult(['result' => ['staging_id' => 'sid-1', 'size' => 100, 'sha256' => hash('sha256', $body)]]);
        $client = $this->stagingClient('sid-1', new MockResponse([$body], ['http_code' => 200]));
        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('downloaded 2 bytes, expected 100');
        $client->downloadOutput($r, 'result');
    }

    public function testOversizeBodyAbortsBeforeBuffering(): void
    {
        // Declared size 5 but the server streams 18 bytes — the in-loop cap
        // must reject it instead of growing the buffer to the full body.
        $body = 'hello-from-mission';
        $r = $this->makeResult(['result' => ['staging_id' => 'sid-1', 'size' => 5, 'sha256' => hash('sha256', $body)]]);
        $client = $this->stagingClient('sid-1', new MockResponse([$body], ['http_code' => 200]));
        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('staging sid-1: body exceeds announced 5 bytes');
        $client->downloadOutput($r, 'result');
    }

    public function testSha256MismatchThrowsStaging(): void
    {
        $body = 'hello-from-mission';
        $r = $this->makeResult(['result' => [
            'staging_id' => 'sid-1', 'size' => strlen($body), 'sha256' => str_repeat('0', 64),
        ]]);
        $client = $this->stagingClient('sid-1', new MockResponse([$body], ['http_code' => 200]));
        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('sha256 mismatch');
        $client->downloadOutput($r, 'result');
    }
}
