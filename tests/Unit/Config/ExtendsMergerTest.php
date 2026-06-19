<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Config;
use Letts\Config\Dugdale;
use Letts\Config\ExtendsMerger;
use Letts\Config\LaneCfg;
use Letts\Config\Runtime;
use Letts\Config\Template;
use Letts\Exceptions\ConfigException;
use PHPUnit\Framework\TestCase;

final class ExtendsMergerTest extends TestCase
{
    public function testDugdaleInheritsTemplateMissionDir(): void
    {
        $c = new Config(
            templates: ['k' => new Template(missionDir: '/var/www/missions')],
            dugdales: [new Dugdale(id: 's1', extends: 'k')],
        );
        $merged = ExtendsMerger::merge($c);
        $this->assertSame('/var/www/missions', $merged->dugdales[0]->missionDir);
    }

    public function testDugdaleOverridesTemplateField(): void
    {
        $c = new Config(
            templates: ['k' => new Template(missionDir: '/template/dir')],
            dugdales: [new Dugdale(id: 's1', extends: 'k', missionDir: '/own/dir')],
        );
        $merged = ExtendsMerger::merge($c);
        $this->assertSame('/own/dir', $merged->dugdales[0]->missionDir);
    }

    public function testLanesUnionedTemplatePlusDugdale(): void
    {
        $c = new Config(
            templates: ['k' => new Template(lanes: ['normal' => new LaneCfg(concurrency: 10)])],
            dugdales: [new Dugdale(
                id: 's1', extends: 'k',
                lanes: ['high' => new LaneCfg(concurrency: 2)],
            )],
        );
        $merged = ExtendsMerger::merge($c);
        $lanes = $merged->dugdales[0]->lanes;
        $this->assertSame(10, $lanes['normal']->concurrency);
        $this->assertSame(2, $lanes['high']->concurrency);
    }

    public function testLabelsReplacedWhenDugdaleSpecifies(): void
    {
        // Go extends.go: labels are REPLACED (not unioned) when the
        // dugdale specifies its own.
        $c = new Config(
            templates: ['k' => new Template(labels: ['prod', 'common'])],
            dugdales: [new Dugdale(id: 's1', extends: 'k', labels: ['dev', 'fast'])],
        );
        $merged = ExtendsMerger::merge($c);
        $this->assertSame(['dev', 'fast'], $merged->dugdales[0]->labels);
    }

    public function testLabelsInheritedWhenDugdaleEmpty(): void
    {
        $c = new Config(
            templates: ['k' => new Template(labels: ['prod', 'common'])],
            dugdales: [new Dugdale(id: 's1', extends: 'k')],
        );
        $merged = ExtendsMerger::merge($c);
        $this->assertSame(['prod', 'common'], $merged->dugdales[0]->labels);
    }

    public function testRuntimeDeepMergedInheritsUnsetFields(): void
    {
        // Go deep-merges runtime field-by-field. A dugdale overriding only the
        // path template still inherits the template's command_template.
        $c = new Config(
            templates: ['k' => new Template(runtime: new Runtime(
                missionPathTemplate: '{mission}.php',
                commandTemplate: ['php', '/runner.php', '{mission}'],
                validateMissionFile: true,
            ))],
            dugdales: [new Dugdale(id: 's1', extends: 'k', runtime: new Runtime(
                missionPathTemplate: 'custom/{mission}.php',
            ))],
        );
        $merged = ExtendsMerger::merge($c);
        $rt = $merged->dugdales[0]->runtime;
        $this->assertSame('custom/{mission}.php', $rt->missionPathTemplate);
        $this->assertSame(['php', '/runner.php', '{mission}'], $rt->commandTemplate);
    }

    public function testRuntimeFullyInheritedWhenDugdaleHasNone(): void
    {
        $c = new Config(
            templates: ['k' => new Template(runtime: new Runtime(
                missionPathTemplate: '{mission}.php',
                commandTemplate: ['php', '{mission_path}'],
                validateMissionFile: true,
            ))],
            dugdales: [new Dugdale(id: 's1', extends: 'k')],
        );
        $rt = ExtendsMerger::merge($c)->dugdales[0]->runtime;
        $this->assertSame('{mission}.php', $rt->missionPathTemplate);
        $this->assertSame(['php', '{mission_path}'], $rt->commandTemplate);
        $this->assertTrue($rt->validateMissionFile);
    }

    public function testNullLaneDeletesInheritedLane(): void
    {
        // `lanes: {parsers: null}` in a dugdale removes the inherited
        // template lane.
        $c = new Config(
            templates: ['k' => new Template(lanes: [
                'normal'  => new LaneCfg(10),
                'parsers' => new LaneCfg(3),
            ])],
            dugdales: [new Dugdale(id: 's1', extends: 'k', nullifiedLanes: ['parsers'])],
        );
        $lanes = ExtendsMerger::merge($c)->dugdales[0]->lanes;
        $this->assertArrayHasKey('normal', $lanes);
        $this->assertArrayNotHasKey('parsers', $lanes);
    }

    public function testProxyInheritedFromTemplate(): void
    {
        $c = new Config(
            templates: ['k' => new Template(proxy: 'socks5h://10.0.0.1:1080')],
            dugdales: [new Dugdale(id: 's1', extends: 'k')],
        );
        $merged = ExtendsMerger::merge($c);
        $this->assertSame('socks5h://10.0.0.1:1080', $merged->dugdales[0]->proxy);
    }

    public function testProxyOverriddenByDugdale(): void
    {
        $c = new Config(
            templates: ['k' => new Template(proxy: 'socks5h://10.0.0.1:1080')],
            dugdales: [new Dugdale(id: 's1', extends: 'k', proxy: 'socks5h://127.0.0.1:9050')],
        );
        $merged = ExtendsMerger::merge($c);
        $this->assertSame('socks5h://127.0.0.1:9050', $merged->dugdales[0]->proxy);
    }

    public function testUnknownTemplateThrows(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1', extends: 'missing')]);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown template: missing');
        ExtendsMerger::merge($c);
    }
}
