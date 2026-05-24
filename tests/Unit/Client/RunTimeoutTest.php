<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use Letts\Exceptions\WaitTimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RunTimeoutTest extends TestCase
{
    public function testWaitTimeoutSurfacesAsWaitTimeoutException(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wt');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n    lanes: {normal: {concurrency: 1}}\n");
        $http = new MockHttpClient(function ($method) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            // A stream that opens but never reaches `done`: the wait deadline,
            // not a connection failure, ends the run — so it must surface as a
            // WaitTimeoutException, distinct from a transport NetworkException.
            return new MockResponse(["{\"seq\":1,\"event\":\"queued\"}\n"], ['http_code' => 200]);
        });
        $client = Client::fromConfig($tmp, http: $http);
        unlink($tmp);

        $this->expectException(WaitTimeoutException::class);
        $client->run(host: 's1', lane: 'normal', mission: 'X', waitTimeout: '50ms');
    }
}
