<?php
declare(strict_types=1);

namespace Letts\Tests\Unit;

use Letts\Exceptions\InterruptedException;
use Letts\Mission;
use PHPUnit\Framework\TestCase;

final class MissionStandaloneTest extends TestCase
{
    public function testStandaloneInputAccessor(): void
    {
        $m = Mission::startStandalone(['s.php', '--input={"item_id":7,"config":{"timeout":60}}']);
        $this->assertSame(7, $m->input('item_id'));
        $this->assertSame(60, $m->input('config.timeout'));
        $this->assertSame(99, $m->input('missing', 99));
        $this->assertTrue($m->has('config.timeout'));
        $this->assertFalse($m->has('nope'));
    }

    public function testStandaloneAll(): void
    {
        $m = Mission::startStandalone(['s.php', '--input={"a":1}']);
        $this->assertSame(['a' => 1], $m->all());
    }

    public function testCheckSignalThrowsWhenRequested(): void
    {
        $m = Mission::startStandalone(['s.php']);
        $m->forceInterruptForTest();
        $this->expectException(InterruptedException::class);
        $m->checkSignal();
    }

    public function testStandaloneFileFallsBackToInputPath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mf');
        file_put_contents($tmp, 'photo-bytes');
        $m = Mission::startStandalone(['s.php', '--input=' . json_encode(['photo' => $tmp])]);
        // Without a dugdale to materialize files, the input value IS the path.
        $this->assertSame($tmp, $m->file('photo'));
        $this->assertSame(11, $m->fileSize('photo'));
        unlink($tmp);
    }

    public function testStandaloneFileMissingKeyThrows(): void
    {
        $m = Mission::startStandalone(['s.php', '--input={"a":1}']);
        $this->expectException(\InvalidArgumentException::class);
        $m->file('photo');
    }

    public function testInvalidOutputKeyRejected(): void
    {
        $m = Mission::startStandalone(['s.php']);
        $this->expectException(\InvalidArgumentException::class);
        $m->outputFile('../escape');
    }

    public function testInvalidOutputKeyRejectedInOutputPath(): void
    {
        $m = Mission::startStandalone(['s.php']);
        $this->expectException(\InvalidArgumentException::class);
        $m->outputPath('a/b');
    }
}
