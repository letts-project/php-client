<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Auth;
use Letts\Config\Config;
use Letts\Config\Dugdale;
use Letts\Config\EnvSubstitutor;
use Letts\Config\Scope;
use Letts\Config\TokenResolver;
use Letts\Exceptions\ConfigException;
use PHPUnit\Framework\TestCase;

final class TokenResolverTest extends TestCase
{
    private function resolver(Config $c, ?\Closure $env = null): TokenResolver
    {
        return new TokenResolver($c, new EnvSubstitutor($env ?? fn() => null));
    }

    public function testDispatchTokenFromDugdale(): void
    {
        $c = new Config(
            auth: new Auth(token: 'global'),
            dugdales: [new Dugdale(id: 's1', token: 'own')],
        );
        $this->assertSame('own', $this->resolver($c)->resolve('s1', Scope::Dispatch));
    }

    public function testDispatchTokenFromGlobal(): void
    {
        $c = new Config(
            auth: new Auth(token: 'global'),
            dugdales: [new Dugdale(id: 's1')],
        );
        $this->assertSame('global', $this->resolver($c)->resolve('s1', Scope::Dispatch));
    }

    public function testAdminTokenMissingThrows(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1')]);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no admin token');
        $this->resolver($c)->resolve('s1', Scope::Admin);
    }

    public function testExecTokenFromDugdale(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1', execToken: 'own-exec')]);
        $this->assertSame('own-exec', $this->resolver($c)->resolve('s1', Scope::Exec));
    }

    public function testEnvSubstitution(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1', token: '${TOK}')]);
        $r = $this->resolver($c, fn(string $k) => ['TOK' => 'resolved'][$k] ?? null);
        $this->assertSame('resolved', $r->resolve('s1', Scope::Dispatch));
    }

    public function testUnknownDugdaleThrows(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1')]);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not found');
        $this->resolver($c)->resolve('unknown', Scope::Dispatch);
    }
}
