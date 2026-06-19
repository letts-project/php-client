<?php
declare(strict_types=1);

namespace Letts\Config;

/**
 * Resolves the effective SOCKS5 proxy URL for a dugdale: env-substitutes the
 * configured value and normalizes the scheme to socks5h:// so DNS is always
 * resolved at the proxy (parity with the Go client, whose x/net dialer is
 * always remote-DNS). An empty result means "no proxy, connect directly".
 *
 * Mirrors TokenResolver, but unlike a token an empty proxy is valid (not an
 * error), because most dugdales have no proxy at all.
 */
final class ProxyResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly EnvSubstitutor $env,
    ) {}

    public function resolve(string $dugdaleId): string
    {
        $d = $this->config->findDugdale($dugdaleId);
        if ($d === null || $d->proxy === '') {
            return '';
        }
        $p = $this->env->substitute($d->proxy);
        if ($p === '') {
            return '';
        }
        // A socks5:// URL would make curl resolve DNS locally; rewrite it to
        // socks5h:// so the proxy resolves the hostname, matching the Go client.
        // Leading-anchored so embedded credentials are left untouched.
        return preg_replace('#^socks5://#i', 'socks5h://', $p);
    }
}
