<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadAndResolveAppliesExtends(): void
    {
        $yaml = <<<YAML
        templates:
          k:
            mission_dir: /var/www
            lanes: {normal: {concurrency: 4}}
        dugdales:
          - id: s1
            host: h
            extends: k
        YAML;
        $tmp = tempnam(sys_get_temp_dir(), 'letts-cl');
        file_put_contents($tmp, $yaml);
        chmod($tmp, 0600);
        try {
            $cfg = ConfigLoader::loadFromPath($tmp);
            $this->assertSame('/var/www', $cfg->dugdales[0]->missionDir);
            $this->assertSame(4, $cfg->dugdales[0]->lanes['normal']->concurrency);
        } finally {
            unlink($tmp);
        }
    }
}
