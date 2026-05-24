<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RunParallelTest extends TestCase
{
    public function testTwoHostsBothSuccess(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rp');
        chmod($tmp, 0600);
        file_put_contents($tmp, <<<YAML
            dugdales:
              - id: s1
                host: h1
                token: t
                lanes: {normal: {concurrency: 1}}
              - id: s2
                host: h2
                token: t
                lanes: {normal: {concurrency: 1}}
            YAML);
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                return new MockResponse(
                    ["{\"seq\":1,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0,\"return\":{\"k\":1}}\n"],
                    ['http_code' => 200],
                );
            }
            throw new \RuntimeException("unexpected request: $method $url");
        });
        $client = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        $results = $client->runParallel([
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'X', 'input' => []],
            ['host' => 's2', 'lane' => 'normal', 'mission' => 'X', 'input' => []],
        ]);
        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isReachable());
        $this->assertTrue($results[0]->isSuccess());
        $this->assertTrue($results[1]->isSuccess());
    }

    public function testFailureWrappedInHostError(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rp');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n    lanes: {normal: {concurrency: 1}}\n");
        $http = new MockHttpClient(fn() => new MockResponse('{"error":"x"}', ['http_code' => 401]));
        $client = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        $results = $client->runParallel([
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'X', 'input' => []],
        ]);
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isReachable());
        $this->assertSame('auth', $results[0]->error->kind);
    }
}
