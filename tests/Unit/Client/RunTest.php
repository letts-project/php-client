<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use Letts\Exceptions\MissionFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RunTest extends TestCase
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

    public function testRunHappyPath(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST' && str_contains($url, '/v1/dispatch')) {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                $body = "{\"seq\":1,\"event\":\"queued\"}\n"
                      . "{\"seq\":2,\"event\":\"running\",\"pid\":42}\n"
                      . "{\"seq\":3,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0,\"return\":{\"k\":1},\"time_finished\":1000}\n";
                return new MockResponse([$body], ['http_code' => 200]);
            }
            throw new \RuntimeException("unexpected request: $method $url");
        });
        $client = $this->client($http);
        $r = $client->run(host: 's1', lane: 'normal', mission: 'X');
        $this->assertTrue($r->isSuccess());
        $this->assertSame(['k' => 1], $r->return);
        $this->assertSame('mid', $r->missionId);
    }

    public function testRunFailedThrows(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                $body = "{\"seq\":1,\"event\":\"done\",\"outcome\":\"failed\",\"exit_code\":1,\"fail_reason\":\"explicit\",\"fail_message\":\"boom\"}\n";
                return new MockResponse([$body], ['http_code' => 200]);
            }
            throw new \RuntimeException("unexpected request: $method $url");
        });
        $client = $this->client($http);
        try {
            $client->run(host: 's1', lane: 'normal', mission: 'X');
            $this->fail('expected MissionFailedException');
        } catch (MissionFailedException $e) {
            $this->assertSame('failed', $e->getOutcome());
            $this->assertSame('boom', $e->getFailMessage());
            $this->assertNotNull($e->getResult());
        }
    }

    public function testRunFailedWithThrowDisabledReturnsResult(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                $body = "{\"seq\":1,\"event\":\"done\",\"outcome\":\"failed\",\"exit_code\":1,\"fail_message\":\"x\"}\n";
                return new MockResponse([$body], ['http_code' => 200]);
            }
            throw new \RuntimeException("unexpected request: $method $url");
        });
        $client = $this->client($http);
        $r = $client->run(host: 's1', lane: 'normal', mission: 'X', throwOnFailure: false);
        $this->assertFalse($r->isSuccess());
        $this->assertSame('failed', $r->outcome);
    }

    public function testRunPopulatesDurationAndStagingIdFromDoneEvent(): void
    {
        // The daemon done event carries duration_ms
        // and the outputs map (with staging_id, sha256, size) directly.
        // RunExecutor reads them straight from the done event — no follow-up
        // GET /v1/missions/{id} required.
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST' && str_contains($url, '/v1/dispatch')) {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                $body = "{\"seq\":1,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0,"
                      . "\"outputs\":{\"result\":{\"staging_id\":\"sid-1\",\"sha256\":\"h\",\"size\":42}},"
                      . "\"duration_ms\":1234,\"time_finished\":1714600045123}\n";
                return new MockResponse([$body], ['http_code' => 200]);
            }
            // No other endpoints expected — fail loudly if buildResult still
            // calls GET /v1/missions/{id}.
            throw new \RuntimeException("unexpected request: $method $url");
        });
        $client = $this->client($http);
        $r = $client->run(host: 's1', lane: 'normal', mission: 'X');
        $this->assertSame(1234, $r->durationMs);
        $this->assertSame('sid-1', $r->outputFiles['result']['staging_id']);
        $this->assertSame(42, $r->outputFiles['result']['size']);
        $this->assertSame('h', $r->outputFiles['result']['sha256']);
    }

    public function testProgressCallback(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                $body = "{\"seq\":1,\"event\":\"progress\",\"value\":0.3,\"message\":\"step1\"}\n"
                      . "{\"seq\":2,\"event\":\"progress\",\"value\":0.7,\"message\":\"step2\"}\n"
                      . "{\"seq\":3,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0}\n";
                return new MockResponse([$body], ['http_code' => 200]);
            }
            throw new \RuntimeException("unexpected request: $method $url");
        });
        $client = $this->client($http);
        $progress = [];
        $client->run(
            host: 's1', lane: 'normal', mission: 'X',
            onProgress: function (?float $v, ?string $m) use (&$progress) { $progress[] = [$v, $m]; },
        );
        $this->assertSame([[0.3, 'step1'], [0.7, 'step2']], $progress);
    }
}
