<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\ConfigParser;
use Letts\Exceptions\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigParserTest extends TestCase
{
    public function testParseMinimal(): void
    {
        $yaml = <<<YAML
        dugdales:
          - id: s1
            host: server1.internal
            token: tok-disp
            lanes:
              normal: {concurrency: 4}
        YAML;
        $c = ConfigParser::parse($yaml);
        $this->assertCount(1, $c->dugdales);
        $this->assertSame('s1', $c->dugdales[0]->id);
        $this->assertSame(4, $c->dugdales[0]->lanes['normal']->concurrency);
    }

    public function testParsesPerDugdaleProxy(): void
    {
        $yaml = <<<YAML
        templates:
          k:
            proxy: "socks5h://10.0.0.1:1080"
        dugdales:
          - id: s1
            host: h
            proxy: "socks5h://127.0.0.1:1080"
        YAML;
        $c = ConfigParser::parse($yaml);
        $this->assertSame('socks5h://127.0.0.1:1080', $c->dugdales[0]->proxy);
        $this->assertSame('socks5h://10.0.0.1:1080', $c->templates['k']->proxy);
    }

    public function testParseFullExample(): void
    {
        $yaml = <<<YAML
        auth:
          token: "\${LETTS_DISPATCH_TOKEN}"
          admin_token: "\${LETTS_ADMIN_TOKEN}"
        defaults:
          port: 7180
        selector:
          match: [prod, backend]
        routes:
          normal: {host: local, lane: normal}
        aliases:
          local: s7
        templates:
          k:
            mission_dir: /var/www/missions
            labels: [prod, backend]
            lanes:
              normal: {concurrency: 10}
        dugdales:
          - id: s1
            host: server1.internal
            extends: k
          - id: s7
            host: server7.internal
            extends: k
        YAML;
        $c = ConfigParser::parse($yaml);
        $this->assertSame(7180, $c->defaults->port);
        $this->assertSame('normal', $c->routes['normal']->lane);
        $this->assertSame('s7', $c->aliases['local']);
        $this->assertSame('/var/www/missions', $c->templates['k']->missionDir);
        $this->assertCount(2, $c->dugdales);
    }

    public function testInvalidYamlThrowsConfigException(): void
    {
        $this->expectException(ConfigException::class);
        ConfigParser::parse("dugdales: [not-a-mapping]");
    }

    public function testRejectsUnknownTopLevelKey(): void
    {
        $this->expectException(\Letts\Exceptions\ConfigException::class);
        $this->expectExceptionMessage('unknown key: mystery_field');
        ConfigParser::parse("mystery_field: 42\ndugdales: []");
    }

    public function testRejectsUnknownDugdaleKey(): void
    {
        $this->expectException(\Letts\Exceptions\ConfigException::class);
        ConfigParser::parse("dugdales:\n  - id: s1\n    dispach_token: oops");
    }

    public function testRejectsUnknownLaneKey(): void
    {
        $this->expectException(\Letts\Exceptions\ConfigException::class);
        ConfigParser::parse("dugdales:\n  - id: s1\n    lanes:\n      normal: {concurrency: 4, paralellism: 8}");
    }

    public function testNullLaneIsParsedAsDeletion(): void
    {
        // A null lane value means "delete the inherited lane". It must
        // parse (not throw "must have concurrency") and be recorded for the merger.
        $c = ConfigParser::parse(
            "dugdales:\n  - id: s1\n    extends: k\n    lanes:\n      parsers: null\n      normal: {concurrency: 2}",
        );
        $d = $c->dugdales[0];
        $this->assertArrayHasKey('normal', $d->lanes);
        $this->assertArrayNotHasKey('parsers', $d->lanes);
        $this->assertContains('parsers', $d->nullifiedLanes);
    }
}
