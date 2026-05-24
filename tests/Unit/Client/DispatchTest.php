<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DispatchTest extends TestCase
{
    private function clientWith(MockHttpClient $http): Client
    {
        $tmp = tempnam(sys_get_temp_dir(), 'd');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    port: 7180\n    token: t\n    lanes: {normal: {concurrency: 1}}\n");
        $c = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        return $c;
    }

    public function testDispatchPostsAndReturnsId(): void
    {
        $seen = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$seen) {
            $seen[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];
            return new MockResponse(
                json_encode(['mission_id' => 'returned-id', 'status' => 'queued']),
                ['http_code' => 202],
            );
        });
        $client = $this->clientWith($http);
        $id = $client->dispatch(host: 's1', lane: 'normal', mission: 'X', input: ['item_id' => 1]);
        $this->assertSame('returned-id', $id);
        $this->assertSame('POST', $seen[0]['method']);
        $this->assertStringContainsString('/v1/dispatch', $seen[0]['url']);
        $decoded = json_decode($seen[0]['body'], true);
        $this->assertSame('X', $decoded['mission']);
        $this->assertSame('normal', $decoded['lane']);
    }

    public function testDispatchUsesProvidedMissionId(): void
    {
        $http = new MockHttpClient(fn() => new MockResponse(
            json_encode(['mission_id' => 'caller-id']),
            ['http_code' => 202],
        ));
        $client = $this->clientWith($http);
        $id = $client->dispatch(
            host: 's1', lane: 'normal', mission: 'X',
            missionId: 'caller-id',
        );
        $this->assertSame('caller-id', $id);
    }

    public function testDispatchByRoute(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'd');
        chmod($tmp, 0600);
        file_put_contents($tmp, <<<YAML
            routes:
              normal: {host: s1, lane: normal}
            dugdales:
              - id: s1
                host: h
                token: t
                lanes: {normal: {concurrency: 1}}
            YAML);
        $http = new MockHttpClient(fn() => new MockResponse(
            json_encode(['mission_id' => 'rid']),
            ['http_code' => 202],
        ));
        $c = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        $id = $c->dispatch(route: 'normal', mission: 'X');
        $this->assertSame('rid', $id);
    }
}
