<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Config;
use Letts\Config\Dugdale;
use Letts\Config\EnvSubstitutor;
use Letts\Config\ProxyResolver;
use PHPUnit\Framework\TestCase;

final class ProxyResolverTest extends TestCase
{
    private function resolver(Config $c, ?\Closure $env = null): ProxyResolver
    {
        return new ProxyResolver($c, new EnvSubstitutor($env ?? fn() => null));
    }

    public function testNoProxyResolvesEmpty(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1')]);
        $this->assertSame('', $this->resolver($c)->resolve('s1'));
    }

    public function testUnknownDugdaleResolvesEmpty(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1')]);
        $this->assertSame('', $this->resolver($c)->resolve('nope'));
    }

    public function testSocks5hPassesThrough(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1', proxy: 'socks5h://u:p@127.0.0.1:1080')]);
        $this->assertSame('socks5h://u:p@127.0.0.1:1080', $this->resolver($c)->resolve('s1'));
    }

    public function testSocks5NormalizedToSocks5h(): void
    {
        // socks5:// would make curl resolve DNS locally; we force remote DNS.
        $c = new Config(dugdales: [new Dugdale(id: 's1', proxy: 'socks5://127.0.0.1:1080')]);
        $this->assertSame('socks5h://127.0.0.1:1080', $this->resolver($c)->resolve('s1'));
    }

    public function testNormalizationLeavesEmbeddedCredentialsUntouched(): void
    {
        // Only the leading scheme is rewritten, not a literal "socks5://" that
        // might appear inside a percent-encoded password.
        $c = new Config(dugdales: [new Dugdale(id: 's1', proxy: 'socks5://user:socks5%3A%2F%2F@h:1080')]);
        $this->assertSame('socks5h://user:socks5%3A%2F%2F@h:1080', $this->resolver($c)->resolve('s1'));
    }

    public function testEnvSubstitution(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1', proxy: 'socks5h://${PROXY_HOST}:1080')]);
        $r = $this->resolver($c, fn(string $k) => ['PROXY_HOST' => '10.0.0.9'][$k] ?? null);
        $this->assertSame('socks5h://10.0.0.9:1080', $r->resolve('s1'));
    }
}
