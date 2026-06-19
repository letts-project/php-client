<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Config;
use Letts\Config\ConfigValidator;
use Letts\Config\Dugdale;
use Letts\Config\LaneCfg;
use Letts\Config\Template;
use Letts\Exceptions\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    public function testCatchesBadDugdaleId(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/dugdales\[0\]/');
        ConfigValidator::validate(new Config(dugdales: [new Dugdale(id: 'BAD ID')]));
    }

    public function testCatchesBadLaneName(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/dugdales\[0\]\.lanes/');
        ConfigValidator::validate(new Config(
            dugdales: [new Dugdale(id: 's1', lanes: ['BAD' => new LaneCfg(concurrency: 1)])],
        ));
    }

    public function testCatchesBadTemplateName(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/templates\["BAD"\]/');
        ConfigValidator::validate(new Config(templates: ['BAD' => new Template()]));
    }

    public function testCatchesAliasCollision(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/aliases\["s1"\]/');
        ConfigValidator::validate(new Config(
            aliases: ['s1' => 'real-s1'],
            dugdales: [new Dugdale(id: 's1')],
        ));
    }

    public function testCatchesEmptyAliasValue(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('empty value');
        ConfigValidator::validate(new Config(aliases: ['prod' => '']));
    }

    public function testAcceptsEnvAliasValue(): void
    {
        ConfigValidator::validate(new Config(aliases: ['prod' => '${PROD_HOST}']));
        $this->assertTrue(true);
    }

    public function testRejectsNonSocksProxyScheme(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/dugdales\[0\].*socks5/');
        ConfigValidator::validate(new Config(
            dugdales: [new Dugdale(id: 's1', proxy: 'http://127.0.0.1:8080')],
        ));
    }

    public function testRejectsBadProxySchemeOnTemplate(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/templates\["k"\].*socks5/');
        ConfigValidator::validate(new Config(templates: ['k' => new Template(proxy: 'ftp://x')]));
    }

    public function testAcceptsSocks5AndSocks5hProxy(): void
    {
        ConfigValidator::validate(new Config(dugdales: [
            new Dugdale(id: 's1', proxy: 'socks5://h:1080'),
            new Dugdale(id: 's2', proxy: 'socks5h://h:1080'),
        ]));
        $this->assertTrue(true);
    }

    public function testAcceptsEnvProxyValue(): void
    {
        ConfigValidator::validate(new Config(dugdales: [new Dugdale(id: 's1', proxy: '${PROXY}')]));
        $this->assertTrue(true);
    }

    public function testAcceptsNumericAliasKey(): void
    {
        // Numeric server ids (e.g. `5: s5`) are valid alias keys even
        // though they cannot be dugdale ids.
        ConfigValidator::validate(new Config(
            aliases: [5 => 's5'],
            dugdales: [new Dugdale(id: 's5')],
        ));
        $this->assertTrue(true);
    }

    public function testCatchesBadAliasKey(): void
    {
        // Relaxing the leading-digit rule must not loosen the charset: a space
        // or uppercase still makes an invalid alias key.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('invalid alias key');
        ConfigValidator::validate(new Config(
            aliases: ['BAD KEY' => 's1'],
            dugdales: [new Dugdale(id: 's1')],
        ));
    }

    public function testCatchesPortOutOfRange(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/port/');
        ConfigValidator::validate(new Config(dugdales: [new Dugdale(id: 's1', port: 70000)]));
    }

    public function testAcceptsZeroPortAsUnset(): void
    {
        ConfigValidator::validate(new Config(dugdales: [new Dugdale(id: 's1', port: 0)]));
        $this->assertTrue(true);
    }

    public function testValidConfigPasses(): void
    {
        ConfigValidator::validate(new Config(
            dugdales: [new Dugdale(id: 's1', lanes: ['normal' => new LaneCfg(concurrency: 1)])],
        ));
        $this->assertTrue(true);
    }
}
