<?php
declare(strict_types=1);

namespace Letts\Internal\Client;

use Letts\Client;
use Letts\Config\HostSelector;
use Letts\Config\Scope;
use Letts\Exceptions\BadRequestException;
use Letts\Internal\Http\StagingUploader;
use Letts\Internal\IdsUuidV7;

/**
 * Shared dispatch logic used by Client::dispatch and Client::run. Resolves
 * the addressing trio (route XOR host XOR match), uploads files via staging,
 * and POSTs to /v1/dispatch.
 */
final class DispatchExecutor
{
    public function __construct(private readonly Client $client) {}

    /**
     * @param array<string, string> $files       — ['key' => '/local/path']
     * @param array<string, mixed>  $input
     * @param list<string>          $match
     * @return array{missionId: string, host: string}
     */
    public function dispatch(
        ?string $route, int|string|null $host, array $match,
        ?string $lane,
        string $mission,
        array $input,
        array $files,
        ?string $timeout,
        ?string $missionId,
    ): array {
        $target = $this->resolveTarget($route, $host, $match, $lane);
        $hostId = $target['host'];
        $laneName = $target['lane'];

        $stagedFiles = [];
        if ($files !== []) {
            $rawT = $this->client->rawTransportFor($hostId, Scope::Dispatch);
            $retry = $this->client->transportFor($hostId, Scope::Dispatch);
            $uploader = new StagingUploader($rawT, $retry);
            foreach ($files as $role => $localPath) {
                $up = $uploader->upload($localPath);
                $stagedFiles[] = ['role' => $role, 'staging_id' => $up['staging_id']];
            }
        }

        $missionId ??= IdsUuidV7::generate();
        $body = [
            'mission_id' => $missionId,
            'mission'    => $mission,
            'lane'       => $laneName,
            'input'      => (object) $input,
            'files'      => $stagedFiles,
        ];
        if ($timeout !== null) {
            $body['timeout'] = $timeout;
        }
        $resp = $this->client->transportFor($hostId, Scope::Dispatch)->jsonRequest(
            'POST', '/v1/dispatch', body: $body,
            extraHeaders: ['Idempotency-Key' => $missionId],
        );
        return ['missionId' => (string) ($resp['mission_id'] ?? $missionId), 'host' => $hostId];
    }

    /**
     * @param list<string> $match
     * @return array{host: string, lane: string}
     */
    public function resolveTarget(?string $route, int|string|null $host, array $match, ?string $lane): array
    {
        $hasRoute = $route !== null && $route !== '';
        $hasHost  = $host !== null && $host !== '';
        $hasMatch = $match !== [];
        $count = ($hasRoute ? 1 : 0) + ($hasHost ? 1 : 0) + ($hasMatch ? 1 : 0);
        if ($count !== 1) {
            throw new BadRequestException('must specify exactly one of: route, host, match');
        }
        if ($hasRoute) {
            return $this->client->getHostResolver()->resolveRoute($route);
        }
        if ($lane === null || $lane === '') {
            throw new BadRequestException('lane is required when route is not used');
        }
        if ($hasHost) {
            return ['host' => $this->client->getHostResolver()->resolve($host), 'lane' => $lane];
        }
        // Auto-select among dugdales that carry BOTH the labels and the lane.
        $picked = HostSelector::pickOne($this->client->getConfig(), $match, $lane);
        return ['host' => $picked->id, 'lane' => $lane];
    }
}
