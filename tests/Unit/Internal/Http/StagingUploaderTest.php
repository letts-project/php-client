<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Http;

use Letts\Exceptions\ConflictException;
use Letts\Internal\Http\HttpTransport;
use Letts\Internal\Http\RetryClient;
use Letts\Internal\Http\StagingUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * StagingUploader uses the dispatch-safe workflow:
 * HEAD /v1/staging/<id> → 404 PUT initial / incomplete resume / complete skip.
 * It MUST NOT touch GET /v1/staging/by-content (exec/admin-only).
 */
final class StagingUploaderTest extends TestCase
{
    private function tmpFile(string $content): string
    {
        $p = tempnam(sys_get_temp_dir(), 'letts-su');
        file_put_contents($p, $content);
        return $p;
    }

    /** @param list<string> $headers @return string joined for substring search */
    private static function headerStr(array $headers): string
    {
        return implode("\n", $headers);
    }

    public function testInitialUploadHeadsThenPuts(): void
    {
        $file = $this->tmpFile('new bytes');
        $sha = hash_file('sha256', $file);
        $size = filesize($file);

        $calls = [];
        $putHeaders = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$putHeaders) {
            $calls[] = "$method $url";
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 404]);
            }
            $putHeaders = $options['headers'] ?? [];
            return new MockResponse('{"staging_id":"x"}', ['http_code' => 201]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 1, backoffMs: [1]);
        $up = new StagingUploader($t, $r);

        $result = $up->upload($file);

        $this->assertSame($sha, $result['sha256']);
        $this->assertSame($size, $result['size']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['staging_id'],
        );
        $this->assertCount(2, $calls);
        $this->assertStringStartsWith('HEAD ', $calls[0]);
        $this->assertStringStartsWith('PUT ', $calls[1]);
        // initial PUT: X-Letts-Sha256 and Content-Length, NO Content-Range
        $hs = self::headerStr($putHeaders);
        $this->assertStringContainsString("X-Letts-Sha256: $sha", $hs);
        $this->assertStringContainsString('Content-Length: ' . $size, $hs);
        $this->assertStringNotContainsStringIgnoringCase('Content-Range', $hs);
        unlink($file);
    }

    public function testNeverCallsByContent(): void
    {
        $file = $this->tmpFile('payload');
        $sawByContent = false;
        $mock = new MockHttpClient(function (string $method, string $url) use (&$sawByContent) {
            if (str_contains($url, '/by-content/')) {
                $sawByContent = true;
                return new MockResponse('', ['http_code' => 403]); // exec-only on real daemon
            }
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 404]);
            }
            return new MockResponse('', ['http_code' => 201]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 1, backoffMs: [1]);
        (new StagingUploader($t, $r))->upload($file);
        $this->assertFalse($sawByContent, 'dispatch-scope uploader must not hit /by-content/');
        unlink($file);
    }

    public function testSkipWhenAlreadyComplete(): void
    {
        $file = $this->tmpFile('done');
        $putCount = 0;
        $mock = new MockHttpClient(function (string $method, string $url) use (&$putCount) {
            if ($method === 'HEAD') {
                return new MockResponse('', [
                    'http_code' => 200,
                    'response_headers' => ['x-letts-upload-status' => 'complete'],
                ]);
            }
            $putCount++;
            return new MockResponse('', ['http_code' => 201]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 1, backoffMs: [1]);
        $result = (new StagingUploader($t, $r))->upload($file);
        $this->assertSame(0, $putCount, 'complete upload must not re-PUT');
        $this->assertNotEmpty($result['staging_id']);
        unlink($file);
    }

    public function testResumeFromIncomplete(): void
    {
        $file = $this->tmpFile(str_repeat('x', 100));
        $putHeaders = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$putHeaders) {
            if ($method === 'HEAD') {
                return new MockResponse('', [
                    'http_code' => 200,
                    'response_headers' => [
                        'x-letts-upload-status'  => 'incomplete',
                        'x-letts-bytes-received' => '60',
                        'x-letts-total-size'     => '100',
                    ],
                ]);
            }
            $putHeaders = $options['headers'] ?? [];
            return new MockResponse('', ['http_code' => 201]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 1, backoffMs: [1]);
        $result = (new StagingUploader($t, $r))->upload($file);
        $this->assertSame(100, $result['size']);
        $hs = self::headerStr($putHeaders);
        $this->assertStringContainsString('Content-Range: bytes 60-99/100', $hs);
        $this->assertStringContainsString('Content-Length: 40', $hs);
        unlink($file);
    }

    public function testRetriesPutOn5xxThenSucceeds(): void
    {
        $file = $this->tmpFile('retry me');
        $puts = 0;
        $mock = new MockHttpClient(function (string $method) use (&$puts) {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 404]);
            }
            $puts++;
            return $puts < 2
                ? new MockResponse('', ['http_code' => 503])
                : new MockResponse('', ['http_code' => 201]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 3, backoffMs: [1, 1, 1]);
        $result = (new StagingUploader($t, $r))->upload($file);
        $this->assertSame(2, $puts, 'PUT must be retried on 5xx via RetryClient');
        $this->assertNotEmpty($result['staging_id']);
        unlink($file);
    }

    public function testContentMismatchNotRetried(): void
    {
        $file = $this->tmpFile('boom');
        $puts = 0;
        $mock = new MockHttpClient(function (string $method) use (&$puts) {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 404]);
            }
            $puts++;
            return new MockResponse('{"error":"content_mismatch"}', ['http_code' => 409]);
        });
        $t = new HttpTransport($mock, 'http://h', 'tok');
        $r = new RetryClient($t, maxAttempts: 3, backoffMs: [1, 1, 1]);
        $this->expectException(ConflictException::class);
        try {
            (new StagingUploader($t, $r))->upload($file);
        } finally {
            $this->assertSame(1, $puts, '409 is fatal, must not retry');
            unlink($file);
        }
    }
}
