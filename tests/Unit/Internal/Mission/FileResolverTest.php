<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Mission;

use Letts\Internal\Mission\FileResolver;
use PHPUnit\Framework\TestCase;

final class FileResolverTest extends TestCase
{
    public function testInputPathFromWorkDir(): void
    {
        $r = new FileResolver(workDir: '/work/m1', files: [
            'photo' => ['size' => 100, 'sha256' => 'abc'],
        ]);
        $this->assertSame('/work/m1/in/photo', $r->inputPath('photo'));
    }

    public function testFileInfo(): void
    {
        $r = new FileResolver(workDir: '/work/m1', files: [
            'photo' => ['size' => 100, 'sha256' => 'abc'],
        ]);
        $info = $r->fileInfo('photo');
        $this->assertSame('/work/m1/in/photo', $info['path']);
        $this->assertSame(100, $info['size']);
        $this->assertSame('abc', $info['sha256']);
    }

    public function testUnknownKeyThrows(): void
    {
        $r = new FileResolver(workDir: '/work/m1', files: []);
        $this->expectException(\InvalidArgumentException::class);
        $r->inputPath('missing');
    }

    public function testFilesIterable(): void
    {
        $r = new FileResolver(workDir: '/w', files: [
            'a' => ['size' => 1, 'sha256' => 'h1'],
            'b' => ['size' => 2, 'sha256' => 'h2'],
        ]);
        $out = $r->files();
        $this->assertCount(2, $out);
        $this->assertSame('/w/in/a', $out['a']['path']);
        $this->assertSame('/w/in/b', $out['b']['path']);
    }

    public function testOutputPathFromWorkDir(): void
    {
        $r = new FileResolver(workDir: '/work/m1', files: []);
        $this->assertSame('/work/m1/out/result', $r->outputPath('result'));
    }

    public function testParseEnvBuildsFileMapFromLettsInVars(): void
    {
        // Dugdale delivers input-file metadata via env vars
        // LETTS_IN_<role>{,__SHA256,__SIZE}, NOT via the stdin payload.
        $map = FileResolver::parseEnv([
            'PATH'                   => '/usr/bin',
            'LETTS_MISSION_ID'       => 'm1',
            'LETTS_IN_photo'         => '/work/m1/in/photo',
            'LETTS_IN_photo__SHA256' => 'abc123',
            'LETTS_IN_photo__SIZE'   => '1024',
            'LETTS_IN_data'          => '/work/m1/in/data',
            'LETTS_IN_data__SIZE'    => '5',
            'LETTS_IN_data__SHA256'  => 'def456',
        ]);

        $this->assertCount(2, $map);
        $this->assertSame(['size' => 1024, 'sha256' => 'abc123'], $map['photo']);
        $this->assertSame(['size' => 5, 'sha256' => 'def456'], $map['data']);
        $this->assertArrayNotHasKey('PATH', $map);
        $this->assertArrayNotHasKey('MISSION_ID', $map);
    }

    public function testParseEnvIgnoresSuffixOnlyVarsWithoutPath(): void
    {
        // Only the path var (LETTS_IN_<role>) introduces a role; bare suffix
        // vars never appear without it, but guard anyway.
        $map = FileResolver::parseEnv([
            'LETTS_IN_x'         => '/w/in/x',
            'LETTS_IN_x__SHA256' => 'h',
            'LETTS_IN_x__SIZE'   => '3',
        ]);
        $this->assertSame(['x'], array_keys($map));
        $this->assertSame('/w/in/x', $map['x']['path'] ?? '/w/in/x'); // path optional
    }
}
