<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Discovery;
use Letts\Exceptions\ConfigException;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    public function testReturnsLettsConfigEnvFirst(): void
    {
        $tmp = sys_get_temp_dir() . '/letts-disc-' . uniqid();
        mkdir($tmp);
        $path = "$tmp/letts.yaml";
        file_put_contents($path, "dugdales: []\n");
        $found = Discovery::find(
            envLookup: fn(string $k) => $k === 'LETTS_CONFIG' ? $path : null,
            cwd: '/tmp/nowhere',
            home: '/tmp/nowhere',
        );
        $this->assertSame($path, $found);
        unlink($path);
        rmdir($tmp);
    }

    public function testReturnsCwdLettsYaml(): void
    {
        $tmp = sys_get_temp_dir() . '/letts-disc-' . uniqid();
        mkdir($tmp);
        file_put_contents("$tmp/letts.yaml", "dugdales: []\n");
        $found = Discovery::find(
            envLookup: fn() => null,
            cwd: $tmp, home: '/tmp/nowhere',
        );
        $this->assertSame("$tmp/letts.yaml", $found);
        unlink("$tmp/letts.yaml");
        rmdir($tmp);
    }

    public function testReturnsHomeDotLettsYaml(): void
    {
        // Matches the Go CLI cascade: ~/.letts/letts.yaml, NOT
        // ~/.config/letts. Ensures the PHP lib finds the same file the CLI does.
        $home = sys_get_temp_dir() . '/letts-home-' . uniqid();
        mkdir("$home/.letts", 0o755, recursive: true);
        file_put_contents("$home/.letts/letts.yaml", "dugdales: []\n");
        $found = Discovery::find(envLookup: fn() => null, cwd: '/tmp/nowhere-' . uniqid(), home: $home);
        $this->assertSame("$home/.letts/letts.yaml", $found);
        unlink("$home/.letts/letts.yaml");
        rmdir("$home/.letts");
        rmdir($home);
    }

    public function testLettsConfigSetButMissingIsHardError(): void
    {
        // Go treats $LETTS_CONFIG pointing at a missing file as a hard error,
        // not a silent fall-through to the cascade (discovery.go:38-43).
        $tmp = sys_get_temp_dir() . '/letts-disc-' . uniqid();
        mkdir($tmp);
        file_put_contents("$tmp/letts.yaml", "dugdales: []\n"); // cascade fallback exists
        $threw = false;
        try {
            Discovery::find(
                envLookup: fn(string $k) => $k === 'LETTS_CONFIG' ? "$tmp/missing.yaml" : null,
                cwd: $tmp, home: '/tmp/nowhere',
            );
        } catch (ConfigException) {
            $threw = true;
        }
        unlink("$tmp/letts.yaml");
        rmdir($tmp);
        $this->assertTrue($threw, '$LETTS_CONFIG pointing at a missing file must hard-error');
    }

    public function testThrowsWhenNothingFound(): void
    {
        $this->expectException(ConfigException::class);
        Discovery::find(
            envLookup: fn() => null,
            cwd: '/tmp/nowhere-' . uniqid(),
            home: '/tmp/nowhere-' . uniqid(),
        );
    }
}
