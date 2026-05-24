<?php
// src/Client.php
declare(strict_types=1);

namespace Letts;

use Letts\Config\Config;
use Letts\Config\ConfigLoader;
use Letts\Config\EnvSubstitutor;
use Letts\Config\HostResolver;
use Letts\Config\Scope;
use Letts\Config\TokenResolver;
use Letts\Internal\Http\HttpTransport;
use Letts\Internal\Http\RetryClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Public PHP client for the letts/dugdale daemon.
 *
 * Construction: Client::default() or Client::fromConfig($path). Both accept
 * an optional injected HttpClientInterface for tests.
 *
 * Per-host transport pool: each (host, scope) gets a memoized HttpTransport and
 * RetryClient pair the first time it's needed.
 */
final class Client
{
    /** @var array<string, array{HttpTransport, RetryClient}> */
    private array $transportCache = [];

    /**
     * @param array{
     *   connect_timeout?: int, request_timeout?: int,
     *   max_connections_per_host?: int,
     *   retry_attempts?: int, retry_backoff?: list<int>,
     * } $opts
     * @param list<string> $matchOverride
     */
    private function __construct(
        private readonly Config $config,
        private readonly array $opts,
        private readonly HttpClientInterface $http,
        private readonly HostResolver $hostResolver,
        private readonly TokenResolver $tokenResolver,
        private readonly array $matchOverride = [],
    ) {}

    public static function default(?HttpClientInterface $http = null): self
    {
        $env = static fn(string $k): ?string => ($v = getenv($k)) === false ? null : $v;
        $config = ConfigLoader::loadDefault($env);
        return self::buildFromConfig($config, [], $http ?? HttpClient::create());
    }

    /**
     * @param array{
     *   connect_timeout?: int, request_timeout?: int,
     *   max_connections_per_host?: int,
     *   retry_attempts?: int, retry_backoff?: list<int>,
     * } $opts
     */
    public static function fromConfig(
        string $path,
        array $opts = [],
        ?HttpClientInterface $http = null,
    ): self {
        $config = ConfigLoader::loadFromPath($path);
        // max_connections_per_host is a client-level pool setting, not a
        // per-request option — it goes in HttpClient::create()'s second arg,
        // not the default-options array (CurlHttpClient rejects it there).
        return self::buildFromConfig($config, $opts, $http ?? HttpClient::create(
            self::httpDefaultOptions($opts),
            $opts['max_connections_per_host'] ?? 4,
        ));
    }

    /**
     * Translate the public tuning knobs into HttpClient default request
     * options. `request_timeout` is the per-request inactivity window;
     * `connect_timeout` bounds the connection phase (best-effort — curl's
     * connect timeout is not enforced uniformly on every platform).
     *
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public static function httpDefaultOptions(array $opts): array
    {
        $out = ['timeout' => $opts['request_timeout'] ?? 30];
        if (isset($opts['connect_timeout'])) {
            $out['max_connect_duration'] = $opts['connect_timeout'];
        }
        return $out;
    }

    /** @param array<string, mixed> $opts */
    private static function buildFromConfig(Config $config, array $opts, HttpClientInterface $http): self
    {
        $env = new EnvSubstitutor(static fn(string $k): ?string => ($v = getenv($k)) === false ? null : $v);
        return new self(
            $config, $opts, $http,
            new HostResolver($config, $env),
            new TokenResolver($config, $env),
        );
    }

    /** @internal — exposed for dispatch/run executors. */
    public function transportFor(string $dugdaleId, Scope $scope): RetryClient
    {
        $cacheKey = "$dugdaleId:" . $scope->value;
        if (!isset($this->transportCache[$cacheKey])) {
            $d = $this->config->findDugdale($dugdaleId);
            if ($d === null) {
                throw new \Letts\Exceptions\ConfigException("dugdale $dugdaleId not found");
            }
            $baseUrl = $this->baseUrlFor($d);
            $token = $this->tokenResolver->resolve($dugdaleId, $scope);
            $http = new HttpTransport($this->http, $baseUrl, $token, $dugdaleId);
            $retry = new RetryClient(
                $http,
                maxAttempts: (int) ($this->opts['retry_attempts'] ?? 3),
                backoffMs: $this->opts['retry_backoff'] ?? [100, 500, 2000],
            );
            $this->transportCache[$cacheKey] = [$http, $retry];
        }
        return $this->transportCache[$cacheKey][1];
    }

    public function rawTransportFor(string $dugdaleId, Scope $scope): HttpTransport
    {
        $this->transportFor($dugdaleId, $scope);
        return $this->transportCache["$dugdaleId:" . $scope->value][0];
    }

    private function baseUrlFor(\Letts\Config\Dugdale $d): string
    {
        if ($d->url !== '') {
            return rtrim($d->url, '/');
        }
        $port = $d->port !== 0 ? $d->port : ($this->config->defaults->port !== 0 ? $this->config->defaults->port : 7180);
        return "http://$d->host:$port";
    }

    public function getConfig(): Config { return $this->config; }
    public function getHostResolver(): HostResolver { return $this->hostResolver; }

    /**
     * Returns a scoped copy of this client with an overridden auto-select
     * label filter. Original instance untouched.
     *
     * @param list<string> $match
     */
    public function withMatch(array $match): self
    {
        return new self(
            $this->config, $this->opts, $this->http,
            $this->hostResolver, $this->tokenResolver,
            $match,
        );
    }

    /** @return list<string> */
    public function getMatchOverride(): array { return $this->matchOverride; }

    /**
     * @param list<string>            $match
     * @param array<string, mixed>    $input
     * @param array<string, string>   $files
     */
    public function dispatch(
        string  $mission,
        ?string $route = null, ?string $host = null, array $match = [],
        ?string $lane = null,
        array   $input = [],
        array   $files = [],
        ?string $timeout = null,
        ?string $missionId = null,
    ): string {
        $exec = new \Letts\Internal\Client\DispatchExecutor($this);
        return $exec->dispatch(
            $route, $host, $this->effectiveMatch($route, $host, $match),
            $lane, $mission, $input, $files, $timeout, $missionId,
        )['missionId'];
    }

    /**
     * Label filter for auto-select: the call's own match wins, then the
     * withMatch() scope, then selector.match from letts.yaml. Defaults only
     * apply when neither route nor host is given — they are auto-select
     * scoping, and must not turn an explicit route/host call into an
     * ambiguous one.
     *
     * @param list<string> $match
     * @return list<string>
     */
    private function effectiveMatch(?string $route, ?string $host, array $match): array
    {
        if ($match !== []) {
            return $match;
        }
        if (($route !== null && $route !== '') || ($host !== null && $host !== '')) {
            return [];
        }
        return $this->matchOverride !== [] ? $this->matchOverride : $this->config->selector->match;
    }

    /**
     * @param list<string>          $match
     * @param array<string, mixed>  $input
     * @param array<string, string> $files
     */
    public function run(
        string  $mission,
        ?string $route = null, ?string $host = null, array $match = [],
        ?string $lane = null,
        array   $input = [],
        array   $files = [],
        ?string $timeout = null,
        ?string $waitTimeout = null,
        ?\Closure $onProgress = null,
        ?string $downloadOutputsTo = null,
        bool    $throwOnFailure = true,
        ?string $missionId = null,
        bool    $fetchLogs = false,
    ): \Letts\Result\RunResult {
        $exec = new \Letts\Internal\Client\RunExecutor($this);
        return $exec->run(
            $route, $host, $this->effectiveMatch($route, $host, $match),
            $lane, $mission, $input, $files,
            $timeout, $waitTimeout, $onProgress, $downloadOutputsTo,
            $throwOnFailure, $missionId, $fetchLogs,
        );
    }

    /**
     * @param list<array{
     *   route?: string, host?: string, match?: list<string>,
     *   lane?: string, mission: string, input?: array<string, mixed>,
     *   files?: array<string, string>, timeout?: string,
     * }> $jobs
     * @param ?string $waitTimeout how long to wait for terminal events across
     *   the whole fan-out; jobs still running past it surface a HostError of
     *   kind "timeout" (their missions keep running on the daemons)
     * @return list<\Letts\Result\HostResult>
     */
    public function runParallel(array $jobs, ?string $waitTimeout = null): array
    {
        return (new \Letts\Internal\Client\ParallelExecutor($this))->runParallel($jobs, $waitTimeout);
    }

    /** @internal — used by ParallelExecutor to multiplex event streams via curl_multi. */
    public function httpClient(): HttpClientInterface
    {
        return $this->http;
    }

    /**
     * @param list<string>          $match
     * @param array<string, mixed>  $input
     * @param array<string, string> $files
     * @return list<\Letts\Result\HostResult>
     */
    public function runOnAll(
        string  $mission,
        ?string $lane = null,
        array   $match = [],
        array   $input = [],
        array   $files = [],
        ?string $timeout = null,
        ?string $waitTimeout = null,
    ): array {
        if ($lane === null || $lane === '') {
            throw new \Letts\Exceptions\BadRequestException('lane is required for runOnAll');
        }
        // Label filter resolution: explicit match > withMatch() scope >
        // selector.match from letts.yaml. An empty result would mean "every
        // dugdale that has the lane" — too easy to hit dev/staging boxes by
        // accident, so fanning out always requires SOME label filter.
        $effectiveMatch = $match !== []
            ? $match
            : ($this->matchOverride !== [] ? $this->matchOverride : $this->config->selector->match);
        if ($effectiveMatch === []) {
            throw new \Letts\Exceptions\NoMatchingDugdaleException(
                'runOnAll needs a label filter: pass match: [...] or set selector.match in letts.yaml',
            );
        }
        // Fan out only to dugdales that carry the labels AND declare the lane.
        $cands = \Letts\Config\HostSelector::candidates($this->config, $effectiveMatch, $lane);
        if ($cands === []) {
            throw new \Letts\Exceptions\NoMatchingDugdaleException(
                'runOnAll: no dugdale matches labels: ' . implode(',', $effectiveMatch)
                    . " (lane: $lane)",
            );
        }
        $jobs = [];
        foreach ($cands as $d) {
            $jobs[] = [
                'host' => $d->id, 'lane' => $lane, 'mission' => $mission,
                'input' => $input, 'files' => $files, 'timeout' => $timeout,
            ];
        }
        return $this->runParallel($jobs, $waitTimeout);
    }

    /**
     * @param list<string> $match
     * @return list<\Letts\Config\Dugdale>
     */
    public function dugdales(array $match = []): array
    {
        return \Letts\Config\HostSelector::candidates($this->config, $match);
    }

    public function getMission(string $id, ?string $host = null): ?\Letts\Result\MissionInfo
    {
        if ($host !== null) {
            return $this->getMissionOnHost($id, $this->hostResolver->resolve($host));
        }
        // host omitted → fan out across all dugdales; return the
        // first that has the mission. A mission id is an unguessable capability,
        // so querying each host by id is safe. Per-host errors (unreachable,
        // auth) are skipped so one bad host doesn't mask a good answer.
        foreach ($this->config->dugdales as $d) {
            try {
                $info = $this->getMissionOnHost($id, $d->id);
                if ($info !== null) {
                    return $info;
                }
            } catch (\Letts\Exceptions\LettsException) {
                continue;
            }
        }
        return null;
    }

    private function getMissionOnHost(string $id, string $hostId): ?\Letts\Result\MissionInfo
    {
        try {
            $data = $this->transportFor($hostId, \Letts\Config\Scope::Dispatch)
                ->jsonRequest('GET', "/v1/missions/$id");
            return \Letts\Result\MissionInfo::fromApiResponse($data);
        } catch (\Letts\Exceptions\DispatchException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<\Letts\Result\MissionInfo>
     */
    public function listMissions(?string $host = null, array $filters = []): array
    {
        if ($host === null) {
            throw new \Letts\Exceptions\BadRequestException('host is required for listMissions');
        }
        $hostId = $this->hostResolver->resolve($host);
        $qs = http_build_query($filters);
        $path = '/v1/missions' . ($qs !== '' ? "?$qs" : '');
        // GET /v1/missions (list/search) is admin-only.
        $data = $this->transportFor($hostId, \Letts\Config\Scope::Admin)
            ->jsonRequest('GET', $path);
        $rows = $data['missions'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = \Letts\Result\MissionInfo::fromApiResponse($r);
        }
        return $out;
    }

    /**
     * kill/restart are NOT auto-retried (unlike dispatch, which is protected
     * by its Idempotency-Key): each successful restart enqueues a NEW mission,
     * so blindly re-sending after an ambiguous network failure could double
     * the work; a re-sent kill can land after the mission finished and
     * misreport `mission_done`. On NetworkException the caller decides —
     * e.g. check getMission() first.
     */
    public function kill(string $id, string $signal = 'TERM', ?string $host = null): void
    {
        if ($host === null) {
            throw new \Letts\Exceptions\BadRequestException('host is required for kill');
        }
        $hostId = $this->hostResolver->resolve($host);
        $this->rawTransportFor($hostId, \Letts\Config\Scope::Admin)
            ->jsonRequest('POST', "/v1/missions/$id/kill", body: ['signal' => $signal]);
    }

    public function restart(string $id, ?string $host = null): string
    {
        if ($host === null) {
            throw new \Letts\Exceptions\BadRequestException('host is required for restart');
        }
        $hostId = $this->hostResolver->resolve($host);
        $resp = $this->rawTransportFor($hostId, \Letts\Config\Scope::Admin)
            ->jsonRequest('POST', "/v1/missions/$id/restart");
        return (string) ($resp['mission_id'] ?? '');
    }

    public function delete(string $id, ?string $host = null, bool $force = false): void
    {
        if ($host === null) {
            throw new \Letts\Exceptions\BadRequestException('host is required for delete');
        }
        $hostId = $this->hostResolver->resolve($host);
        $path = "/v1/missions/$id" . ($force ? '?force=true' : '');
        $this->transportFor($hostId, \Letts\Config\Scope::Admin)
            ->jsonRequest('DELETE', $path);
    }
}
