<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Mission;

use Letts\Internal\Mission\OutputRegistry;
use PHPUnit\Framework\TestCase;

final class OutputRegistryTest extends TestCase
{
    public function testRegisterDedupesKeysAndExposesList(): void
    {
        $r = new OutputRegistry('/tmp');
        $r->register('result');
        $r->register('result');
        $r->register('extra');
        $this->assertSame(['result', 'extra'], $r->keys());
    }

    public function testAssertAllPresentPassesWhenFilesExist(): void
    {
        $workDir = sys_get_temp_dir() . '/letts-or-' . uniqid();
        mkdir("$workDir/out", 0o755, recursive: true);
        file_put_contents("$workDir/out/result", 'payload-bytes');

        $r = new OutputRegistry($workDir);
        $r->register('result');
        $r->assertAllPresent();
        $this->addToAssertionCount(1);

        unlink("$workDir/out/result");
        rmdir("$workDir/out");
        rmdir($workDir);
    }

    public function testAssertAllPresentThrowsOnMissingFile(): void
    {
        $workDir = sys_get_temp_dir() . '/letts-or-' . uniqid();
        mkdir("$workDir/out", 0o755, recursive: true);
        $r = new OutputRegistry($workDir);
        $r->register('ghost');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ghost');
        try {
            $r->assertAllPresent();
        } finally {
            rmdir("$workDir/out");
            rmdir($workDir);
        }
    }
}
