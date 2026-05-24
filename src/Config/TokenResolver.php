<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\ConfigException;

/**
 * Resolves the right token for (dugdaleId, scope) preferring dugdale-local
 * over global Auth fallbacks, then performs env substitution. Mirrors Go
 * internal/lettsconfig ResolveToken.
 */
final class TokenResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly EnvSubstitutor $env,
    ) {}

    public function resolve(string $dugdaleId, Scope $scope): string
    {
        $d = $this->config->findDugdale($dugdaleId);
        if ($d === null) {
            throw new ConfigException("dugdale \"$dugdaleId\" not found in letts.yaml");
        }
        $raw = match ($scope) {
            Scope::Dispatch => $d->token !== '' ? $d->token : $this->config->auth->token,
            Scope::Admin    => $d->adminToken !== '' ? $d->adminToken : $this->config->auth->adminToken,
            Scope::Exec     => $d->execToken !== '' ? $d->execToken : $this->config->auth->execToken,
        };
        if ($raw === '') {
            throw new ConfigException("no {$scope->value} token configured for dugdale \"$dugdaleId\"");
        }
        return $this->env->substitute($raw);
    }
}
