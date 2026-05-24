<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use Letts\Exceptions\NoMatchingDugdaleException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RunOnAllTest extends TestCase
{
    public function testFanOutOnMatchedHosts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'roa');
        chmod($tmp, 0600);
        file_put_contents($tmp, <<<YAML
            dugdales:
              - id: s1
                host: h
                token: t
                labels: [prod]
                lanes: {normal: {concurrency: 1}}
              - id: s2
                host: h
                token: t
                labels: [prod]
                lanes: {normal: {concurrency: 1}}
              - id: s3
                host: h
                token: t
                labels: [dev]
                lanes: {normal: {concurrency: 1}}
            YAML);
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                return new MockResponse(
                    ["{\"seq\":1,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0}\n"],
                    ['http_code' => 200],
                );
            }
            throw new \RuntimeException("unexpected request: $method $url");
        });
        $client = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        $results = $client->runOnAll(match: ['prod'], lane: 'normal', mission: 'X');
        $this->assertCount(2, $results);
        $hosts = array_map(fn($r) => $r->host, $results);
        sort($hosts);
        $this->assertSame(['s1', 's2'], $hosts);
    }

    public function testThrowsOnEmptyMatch(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'roa');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n    labels: [prod]\n    lanes: {normal: {concurrency: 1}}\n");
        $client = Client::fromConfig($tmp, http: new MockHttpClient());
        unlink($tmp);
        $this->expectException(NoMatchingDugdaleException::class);
        $client->runOnAll(match: ['ghost'], lane: 'normal', mission: 'X');
    }
}
