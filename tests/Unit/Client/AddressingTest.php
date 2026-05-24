<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use Letts\Exceptions\NoMatchingDugdaleException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Addressing resolution edge cases: a default label scope from withMatch()
 * or selector.match must not interfere with explicit route/host dispatch,
 * and fan-out must refuse to run unscoped.
 */
final class AddressingTest extends TestCase
{
    private function client(string $yaml, MockHttpClient $http): Client
    {
        $tmp = tempnam(sys_get_temp_dir(), 'addr');
        chmod($tmp, 0600);
        file_put_contents($tmp, $yaml);
        $c = Client::fromConfig($tmp, http: $http);
        unlink($tmp);
        return $c;
    }

    private static function dispatchOk(): MockHttpClient
    {
        return new MockHttpClient(fn() => new MockResponse(
            json_encode(['mission_id' => 'mid', 'status' => 'queued']),
            ['http_code' => 202],
        ));
    }

    public function testScopedClientStillDispatchesByRoute(): void
    {
        $yaml = <<<YAML
            routes:
              normal: {host: s1, lane: normal}
            dugdales:
              - id: s1
                host: h
                token: t
                labels: [prod]
                lanes: {normal: {concurrency: 1}}
            YAML;
        $client = $this->client($yaml, self::dispatchOk())->withMatch(['prod']);
        // A label scope is a default for auto-select, not an extra addressing
        // selector — combining it with an explicit route must not be ambiguous.
        $id = $client->dispatch(route: 'normal', mission: 'X');
        $this->assertSame('mid', $id);
    }

    public function testScopedClientStillDispatchesByHost(): void
    {
        $yaml = <<<YAML
            dugdales:
              - id: s1
                host: h
                token: t
                labels: [prod]
                lanes: {normal: {concurrency: 1}}
            YAML;
        $client = $this->client($yaml, self::dispatchOk())->withMatch(['prod']);
        $id = $client->dispatch(host: 's1', lane: 'normal', mission: 'X');
        $this->assertSame('mid', $id);
    }

    public function testDispatchAutoSelectFallsBackToSelectorMatch(): void
    {
        $yaml = <<<YAML
            selector:
              match: [prod]
            dugdales:
              - id: s1
                host: h
                port: 7180
                token: t
                labels: [prod]
                lanes: {normal: {concurrency: 1}}
              - id: s2
                host: h
                port: 7280
                token: t
                labels: [dev]
                lanes: {normal: {concurrency: 1}}
            YAML;
        $seen = [];
        $http = new MockHttpClient(function ($method, string $url) use (&$seen) {
            $seen[] = $url;
            return new MockResponse(json_encode(['mission_id' => 'mid', 'status' => 'queued']), ['http_code' => 202]);
        });
        // Lane-only call: selector.match supplies the label filter, so only
        // the prod dugdale qualifies for auto-select.
        $id = $this->client($yaml, $http)->dispatch(lane: 'normal', mission: 'X');
        $this->assertSame('mid', $id);
        $this->assertStringContainsString(':7180/', $seen[0]);
    }

    public function testDispatchWithoutAnyAddressingThrows(): void
    {
        // No route, no host, no match — and no selector.match to fall back to.
        $yaml = <<<YAML
            dugdales:
              - id: s1
                host: h
                token: t
                labels: [prod]
                lanes: {normal: {concurrency: 1}}
            YAML;
        $this->expectException(\Letts\Exceptions\BadRequestException::class);
        $this->client($yaml, new MockHttpClient())->dispatch(lane: 'normal', mission: 'X');
    }

    public function testRunOnAllFallsBackToSelectorMatch(): void
    {
        $yaml = <<<YAML
            selector:
              match: [prod]
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
            YAML;
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid', 'status' => 'queued']), ['http_code' => 202]);
            }
            return new MockResponse(["{\"seq\":1,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0}\n"], ['http_code' => 200]);
        });
        // No explicit match → selector.match scopes the fan-out to prod only.
        $results = $this->client($yaml, $http)->runOnAll(mission: 'X', lane: 'normal');
        $this->assertCount(1, $results);
        $this->assertSame('s1', $results[0]->host);
    }

    public function testRunOnAllRefusesWithoutAnyLabelFilter(): void
    {
        $yaml = <<<YAML
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
            YAML;
        // No match arg, no withMatch, no selector.match → fanning out to every
        // lane-bearing dugdale (incl. dev) would be a footgun.
        $this->expectException(NoMatchingDugdaleException::class);
        $this->client($yaml, new MockHttpClient())->runOnAll(mission: 'X', lane: 'normal');
    }
}
