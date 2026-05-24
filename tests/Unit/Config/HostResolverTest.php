<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Config;
use Letts\Config\Dugdale;
use Letts\Config\EnvSubstitutor;
use Letts\Config\HostResolver;
use Letts\Exceptions\ConfigException;
use PHPUnit\Framework\TestCase;

final class HostResolverTest extends TestCase
{
    private function resolver(Config $c, ?\Closure $env = null): HostResolver
    {
        return new HostResolver($c, new EnvSubstitutor($env ?? fn() => null));
    }

    public function testResolvesDirectDugdaleId(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1')]);
        $this->assertSame('s1', $this->resolver($c)->resolve('s1'));
    }

    public function testResolvesAliasOneHop(): void
    {
        $c = new Config(
            aliases: ['local' => 's7'],
            dugdales: [new Dugdale(id: 's7')],
        );
        $this->assertSame('s7', $this->resolver($c)->resolve('local'));
    }

    public function testResolvesEnvAlias(): void
    {
        $c = new Config(
            aliases: ['local' => '${LOCAL}'],
            dugdales: [new Dugdale(id: 's3')],
        );
        $r = $this->resolver($c, fn(string $k) => ['LOCAL' => 's3'][$k] ?? null);
        $this->assertSame('s3', $r->resolve('local'));
    }

    public function testUnknownHostThrows(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1')]);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not found');
        $this->resolver($c)->resolve('unknown');
    }

    public function testSelfReferenceThrows(): void
    {
        $c = new Config(aliases: ['a' => 'a']);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('self-referential');
        $this->resolver($c)->resolve('a');
    }

    public function testCycleThrows(): void
    {
        $c = new Config(aliases: ['a' => 'b', 'b' => 'a']);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cycle');
        $this->resolver($c)->resolve('a');
    }

    public function testDepthLimit(): void
    {
        $aliases = [];
        for ($i = 0; $i < 9; $i++) {
            $aliases["a$i"] = 'a' . ($i + 1);
        }
        $c = new Config(aliases: $aliases);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('max depth');
        $this->resolver($c)->resolve('a0');
    }
}
