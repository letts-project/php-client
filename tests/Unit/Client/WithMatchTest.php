<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WithMatchTest extends TestCase
{
    public function testWithMatchScopesRunOnAll(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wm');
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
                labels: [dev]
                lanes: {normal: {concurrency: 1}}
            YAML);
        $http = new MockHttpClient(function ($method) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            return new MockResponse(
                ["{\"seq\":1,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0}\n"],
                ['http_code' => 200],
            );
        });
        $client = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        $scoped = $client->withMatch(['prod']);
        $results = $scoped->runOnAll(mission: 'X', lane: 'normal');
        $this->assertCount(1, $results);
        $this->assertSame('s1', $results[0]->host);
    }

    public function testWithMatchReturnsNewInstance(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wm');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n");
        $a = Client::fromConfig($tmp, http: new MockHttpClient());
        unlink($tmp);
        $b = $a->withMatch(['prod']);
        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->getMatchOverride());
        $this->assertSame(['prod'], $b->getMatchOverride());
    }
}
