<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use Letts\Exceptions\AuthException;
use Letts\Exceptions\ConfigException;
use Letts\Exceptions\DispatchException;
use Letts\Exceptions\MissionFailedException;
use Letts\Exceptions\NoMatchingDugdaleException;
use Letts\Exceptions\StagingException;
use Letts\Exceptions\WaitTimeoutException;
use Letts\Result\LaunchFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The optional failure observer: a single \Closure registered on Client that
 * is called whenever a task fails to launch (no result), carrying a
 * LaunchFailure descriptor. See the failure-observer design spec.
 */
final class FailureObserverTest extends TestCase
{
    private function client(MockHttpClient $http, ?\Closure $obs = null): Client
    {
        $tmp = tempnam(sys_get_temp_dir(), 'r');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n    lanes: {normal: {concurrency: 1}}\n");
        $c = Client::fromConfig($tmp, http: $http, onLaunchFailure: $obs);
        unlink($tmp);
        return $c;
    }

    public function testDispatchLaunchFailureFiresObserverThenRethrows(): void
    {
        $http = new MockHttpClient(
            fn($method, $url) => new MockResponse(json_encode(['error' => 'unauthorized']), ['http_code' => 401]),
        );
        $captured = null;
        $client = $this->client($http, function (LaunchFailure $f) use (&$captured) { $captured = $f; });

        try {
            $client->dispatch(mission: 'X', host: 's1', lane: 'normal', input: ['a' => 1]);
            $this->fail('expected AuthException to propagate');
        } catch (AuthException) {
            // expected — observer must not swallow it
        }

        $this->assertInstanceOf(LaunchFailure::class, $captured);
        $this->assertSame('dispatch', $captured->method);
        $this->assertSame('dispatch', $captured->phase);
        $this->assertSame('X', $captured->mission);
        $this->assertSame('s1', $captured->host);
        $this->assertSame(['a' => 1], $captured->input);
        $this->assertNotSame('', (string) $captured->missionId, 'missionId must be assigned pre-flight');
        $this->assertFalse($captured->retryable, '401 auth is a deterministic, non-retryable failure');
        $this->assertInstanceOf(AuthException::class, $captured->exception);
    }

    public function testTryDispatchLaunchFailureFiresObserverAndReturnsNull(): void
    {
        $http = new MockHttpClient(
            fn($method, $url) => new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 401]),
        );
        $captured = null;
        $client = $this->client($http, function (LaunchFailure $f) use (&$captured) { $captured = $f; });

        $result = $client->tryDispatch(mission: 'X', host: 's1', lane: 'normal');

        $this->assertNull($result, 'tryDispatch must swallow the launch failure and return null');
        $this->assertInstanceOf(LaunchFailure::class, $captured);
        $this->assertSame('tryDispatch', $captured->method);
        $this->assertSame('dispatch', $captured->phase);
        $this->assertNotSame('', (string) $captured->missionId);
    }

    public function testRunDispatchPhaseFailureFiresObserverThenRethrows(): void
    {
        $http = new MockHttpClient(
            fn($method, $url) => new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 401]),
        );
        $captured = null;
        $client = $this->client($http, function (LaunchFailure $f) use (&$captured) { $captured = $f; });

        try {
            $client->run(mission: 'X', host: 's1', lane: 'normal');
            $this->fail('expected AuthException to propagate');
        } catch (AuthException) {
        }

        $this->assertInstanceOf(LaunchFailure::class, $captured);
        $this->assertSame('run', $captured->method);
        $this->assertSame('dispatch', $captured->phase);
        $this->assertNotSame('', (string) $captured->missionId);
    }

    public function testRunStreamPhaseFailureFiresObserverWithMissionId(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            if (str_contains($url, '/v1/dispatch')) {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            // /events → 410 Gone: mapped to DispatchException immediately, no reconnect.
            return new MockResponse(json_encode(['error' => 'gone']), ['http_code' => 410]);
        });
        $captured = null;
        $client = $this->client($http, function (LaunchFailure $f) use (&$captured) { $captured = $f; });

        try {
            $client->run(mission: 'X', host: 's1', lane: 'normal');
            $this->fail('expected a stream-phase exception');
        } catch (DispatchException) {
        }

        $this->assertInstanceOf(LaunchFailure::class, $captured);
        $this->assertSame('run', $captured->method);
        $this->assertSame('stream', $captured->phase, 'the mission launched; only the event stream dropped');
        $this->assertSame('mid', $captured->missionId);
    }

    public function testRunParallelDispatchFailuresFireObserverPerHost(): void
    {
        $http = new MockHttpClient(
            fn($method, $url) => new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 401]),
        );
        $fires = [];
        $client = $this->client($http, function (LaunchFailure $f) use (&$fires) { $fires[] = $f; });

        $results = $client->runParallel([
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'A', 'input' => []],
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'B', 'input' => []],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('auth', $results[0]->error->kind);
        $this->assertSame('auth', $results[1]->error->kind);
        $this->assertCount(2, $fires, 'observer fires once per failed host');
        $this->assertSame('runParallel', $fires[0]->method);
        $this->assertSame('dispatch', $fires[0]->phase);
        $this->assertSame(['A', 'B'], [$fires[0]->mission, $fires[1]->mission]);
    }

    public function testRunParallelBadFileBecomesStagingHostErrorAndFanOutContinues(): void
    {
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
        $fires = [];
        $client = $this->client($http, function (LaunchFailure $f) use (&$fires) { $fires[] = $f; });

        $results = $client->runParallel([
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'BAD', 'files' => ['blob' => '/no/such/file-x'], 'input' => []],
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'OK', 'input' => []],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('staging', $results[0]->error->kind, 'a bad file is a per-host error, not a fan-out abort');
        $this->assertTrue($results[1]->isSuccess(), 'the fan-out continued to the good job');
        $this->assertCount(1, $fires);
        $this->assertSame('BAD', $fires[0]->mission);
        $this->assertSame('dispatch', $fires[0]->phase);
        $this->assertArrayHasKey('blob', $fires[0]->files);
    }

    public function testMissionFailedExceptionDoesNotFireObserver(): void
    {
        // The mission launched and ran; a non-success outcome is a result, not a
        // launch failure (MissionFailedException carries getResult()).
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            return new MockResponse(
                ["{\"seq\":1,\"event\":\"done\",\"outcome\":\"failed\",\"exit_code\":1,\"fail_message\":\"boom\"}\n"],
                ['http_code' => 200],
            );
        });
        $fired = false;
        $client = $this->client($http, function () use (&$fired) { $fired = true; });

        $this->expectException(MissionFailedException::class);
        try {
            $client->run(mission: 'X', host: 's1', lane: 'normal');
        } finally {
            $this->assertFalse($fired, 'a mission failure must not reach the launch observer');
        }
    }

    public function testWaitTimeoutDoesNotFireObserver(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            // Stream opens but never reaches `done`: the wait deadline ends it.
            return new MockResponse(["{\"seq\":1,\"event\":\"queued\"}\n"], ['http_code' => 200]);
        });
        $fired = false;
        $client = $this->client($http, function () use (&$fired) { $fired = true; });

        $this->expectException(WaitTimeoutException::class);
        try {
            $client->run(mission: 'X', host: 's1', lane: 'normal', waitTimeout: '50ms');
        } finally {
            $this->assertFalse($fired, 'the mission is still running; not a launch failure');
        }
    }

    public function testPostSuccessDownloadStagingErrorDoesNotFireObserver(): void
    {
        $dir = sys_get_temp_dir() . '/letts-obs-' . uniqid();
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            if (str_contains($url, '/events')) {
                return new MockResponse(
                    ["{\"seq\":1,\"event\":\"done\",\"outcome\":\"success\",\"exit_code\":0,\"outputs\":{\"result\":{\"staging_id\":\"sid\",\"sha256\":\"\",\"size\":0}}}\n"],
                    ['http_code' => 200],
                );
            }
            // staging download fails AFTER the mission already succeeded.
            return new MockResponse('gone', ['http_code' => 404]);
        });
        $fired = false;
        $client = $this->client($http, function () use (&$fired) { $fired = true; });

        try {
            $client->run(mission: 'X', host: 's1', lane: 'normal', downloadOutputsTo: $dir);
            $this->fail('expected a StagingException from the post-success download');
        } catch (StagingException) {
            $this->assertFalse($fired, 'a post-success download error is not a launch failure');
        } finally {
            @rmdir($dir);
        }
    }

    public function testNoObserverRegisteredDoesNotCrashOrLog(): void
    {
        $http = new MockHttpClient(
            fn($method, $url) => new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 401]),
        );
        $client = $this->client($http);   // no observer

        $log = tempnam(sys_get_temp_dir(), 'log');
        $prev = ini_set('error_log', $log);
        try {
            try {
                $client->dispatch(mission: 'X', host: 's1', lane: 'normal');
                $this->fail('expected AuthException');
            } catch (AuthException) {
            }
            $this->assertNull($client->tryDispatch(mission: 'X', host: 's1', lane: 'normal'));
            $this->assertSame('', (string) file_get_contents($log), 'no observer ⇒ nothing logged');
        } finally {
            ini_set('error_log', (string) $prev);
            @unlink($log);
        }
    }

    public function testObserverExceptionIsSwallowedAndLogged(): void
    {
        $http = new MockHttpClient(
            fn($method, $url) => new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 401]),
        );
        $client = $this->client($http, function (LaunchFailure $f) {
            throw new \RuntimeException('observer blew up');
        });

        $log = tempnam(sys_get_temp_dir(), 'log');
        $prev = ini_set('error_log', $log);
        try {
            try {
                $client->dispatch(mission: 'X', host: 's1', lane: 'normal');
                $this->fail('control flow must be unchanged — AuthException still propagates');
            } catch (AuthException) {
            }
            $this->assertStringContainsString('failure observer threw', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $prev);
            @unlink($log);
        }
    }

    public function testWithFailureObserverPreservesMatchOverrideBothOrderings(): void
    {
        $http = new MockHttpClient(
            fn($method, $url) => new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 401]),
        );
        $captured = null;
        $obs = function (LaunchFailure $f) use (&$captured) { $captured = $f; };
        $base = $this->client($http);

        $a = $base->withFailureObserver($obs)->withMatch(['x']);
        $b = $base->withMatch(['x'])->withFailureObserver($obs);

        $this->assertSame(['x'], $a->getMatchOverride());
        $this->assertSame(['x'], $b->getMatchOverride(), 'withFailureObserver must preserve the match override');

        foreach ([$a, $b] as $client) {
            $captured = null;
            try {
                $client->dispatch(mission: 'X', host: 's1', lane: 'normal');
            } catch (AuthException) {
            }
            $this->assertInstanceOf(LaunchFailure::class, $captured, 'observer survives the other wither');
        }
    }

    public function testMissionIdEqualsIdempotencyKeyWhenPostReached(): void
    {
        $seenKey = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$seenKey) {
            if (preg_match('/idempotency-key:\s*(\S+)/i', implode("\n", $options['headers'] ?? []), $m)) {
                $seenKey = $m[1];
            }
            return new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 401]);
        });
        $captured = null;
        $client = $this->client($http, function (LaunchFailure $f) use (&$captured) { $captured = $f; });

        try {
            $client->dispatch(mission: 'X', host: 's1', lane: 'normal');
        } catch (AuthException) {
        }

        $this->assertNotNull($seenKey, 'the POST carried an Idempotency-Key');
        $this->assertSame($seenKey, $captured->missionId, 'the observed missionId is the Idempotency-Key for an idempotent retry');
    }

    public function testRunOnAllNoMatchPropagatesWithoutFiringObserver(): void
    {
        $http = new MockHttpClient(fn($method, $url) => new MockResponse('', ['http_code' => 200]));
        $fired = false;
        $client = $this->client($http, function () use (&$fired) { $fired = true; });

        $this->expectException(NoMatchingDugdaleException::class);
        try {
            $client->runOnAll(match: ['nope'], lane: 'normal', mission: 'X');
        } finally {
            $this->assertFalse($fired, 'a config-level fan-out throw is not routed per-host through the observer');
        }
    }

    public function testSingleDispatchConfigErrorFiresObserver(): void
    {
        $http = new MockHttpClient(fn($method, $url) => new MockResponse('', ['http_code' => 200]));
        $captured = null;
        $client = $this->client($http, function (LaunchFailure $f) use (&$captured) { $captured = $f; });

        try {
            $client->dispatch(mission: 'X', host: 'ghost', lane: 'normal');
            $this->fail('expected a ConfigException for an unknown host');
        } catch (ConfigException) {
        }

        $this->assertInstanceOf(LaunchFailure::class, $captured, 'config errors fire on single-target methods');
        $this->assertFalse($captured->retryable);
    }

    public function testRunParallelStreamDropFiresObserverPerHostWithStreamPhase(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            if ($method === 'POST') {
                return new MockResponse(json_encode(['mission_id' => 'mid']), ['http_code' => 202]);
            }
            // Stream opens but ends without a `done` event (no reconnect in fan-out).
            return new MockResponse(["{\"seq\":1,\"event\":\"queued\"}\n"], ['http_code' => 200]);
        });
        $fires = [];
        $client = $this->client($http, function (LaunchFailure $f) use (&$fires) { $fires[] = $f; });

        $results = $client->runParallel([
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'A', 'input' => []],
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'B', 'input' => []],
        ]);

        $this->assertCount(2, $results);
        $this->assertCount(2, $fires);
        $this->assertSame('stream', $fires[0]->phase, 'dispatch succeeded; the event stream dropped');
        $this->assertSame('stream', $fires[1]->phase);
        $this->assertSame('mid', $fires[0]->missionId);
    }

    public function testAddressingBadRequestFiresObserver(): void
    {
        $http = new MockHttpClient(fn($method, $url) => new MockResponse('', ['http_code' => 200]));
        $captured = null;
        $client = $this->client($http, function (LaunchFailure $f) use (&$captured) { $captured = $f; });

        try {
            $client->dispatch(mission: 'X', route: 'r', host: 's1', lane: 'normal');
            $this->fail('expected a BadRequestException for ambiguous addressing');
        } catch (\Letts\Exceptions\BadRequestException) {
        }

        $this->assertInstanceOf(LaunchFailure::class, $captured);
        $this->assertSame('dispatch', $captured->phase);
        $this->assertFalse($captured->retryable);
    }
}
