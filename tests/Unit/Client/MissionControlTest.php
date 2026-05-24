<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MissionControlTest extends TestCase
{
    private function clientFor(\Closure $handler): Client
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mc');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n    admin_token: at\n");
        $c = Client::fromConfig($tmp, http: new MockHttpClient($handler));
        unlink($tmp);
        return $c;
    }

    public function testGetMissionReturnsInfo(): void
    {
        $client = $this->clientFor(fn() => new MockResponse(
            json_encode(['mission_id' => 'mid', 'lane' => 'normal', 'outcome' => 'success']),
            ['http_code' => 200],
        ));
        $info = $client->getMission('mid', host: 's1');
        $this->assertSame('mid', $info->missionId);
        $this->assertSame('normal', $info->lane);
    }

    public function testListMissionsReturnsArray(): void
    {
        $client = $this->clientFor(fn() => new MockResponse(
            json_encode(['missions' => [['mission_id' => 'm1'], ['mission_id' => 'm2']]]),
            ['http_code' => 200],
        ));
        $list = $client->listMissions(host: 's1', filters: ['lane' => 'normal']);
        $this->assertCount(2, $list);
        $this->assertSame('m1', $list[0]->missionId);
    }

    public function testListMissionsUsesAdminToken(): void
    {
        // GET /v1/missions is admin-only: must send the admin token.
        $seen = [];
        $client = $this->clientFor(function ($method, $url, $options) use (&$seen) {
            $seen = $options['headers'] ?? [];
            return new MockResponse(json_encode(['missions' => []]), ['http_code' => 200]);
        });
        $client->listMissions(host: 's1');
        $this->assertContains('Authorization: Bearer at', $seen);
    }

    public function testGetMissionFansOutWhenHostOmitted(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mc');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - {id: s1, host: h1, token: t}\n  - {id: s2, host: h2, token: t}\n");
        $client = Client::fromConfig($tmp, http: new MockHttpClient(function ($method, $url) {
            if (str_contains($url, 'h1')) {
                return new MockResponse(json_encode(['error' => 'not_found']), ['http_code' => 404]);
            }
            return new MockResponse(json_encode(['mission_id' => 'mid', 'lane' => 'normal']), ['http_code' => 200]);
        }));
        unlink($tmp);
        $info = $client->getMission('mid'); // no host → fan out across dugdales
        $this->assertNotNull($info);
        $this->assertSame('mid', $info->missionId);
    }

    public function testGetMissionFanOutReturnsNullWhenNowhere(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mc');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - {id: s1, host: h1, token: t}\n  - {id: s2, host: h2, token: t}\n");
        $client = Client::fromConfig($tmp, http: new MockHttpClient(
            fn() => new MockResponse(json_encode(['error' => 'not_found']), ['http_code' => 404]),
        ));
        unlink($tmp);
        $this->assertNull($client->getMission('ghost'));
    }

    public function testKillCallsExpectedEndpoint(): void
    {
        $seen = [];
        $client = $this->clientFor(function ($method, $url) use (&$seen) {
            $seen[] = "$method $url";
            return new MockResponse('', ['http_code' => 200]);
        });
        $client->kill('mid', signal: 'TERM', host: 's1');
        $this->assertStringContainsString('POST', $seen[0]);
        $this->assertStringContainsString('/v1/missions/mid/kill', $seen[0]);
    }

    public function testRestartReturnsNewId(): void
    {
        $client = $this->clientFor(fn() => new MockResponse(
            json_encode(['mission_id' => 'new-mid']),
            ['http_code' => 202],
        ));
        $this->assertSame('new-mid', $client->restart('old-mid', host: 's1'));
    }

    public function testDeleteSucceedsOn204(): void
    {
        $client = $this->clientFor(fn() => new MockResponse('', ['http_code' => 204]));
        $client->delete('mid', host: 's1');
        $this->assertTrue(true);
    }
}
