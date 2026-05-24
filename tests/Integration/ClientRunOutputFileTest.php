<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientRunOutputFileTest extends DugdaleFixture
{
    public function testOutputFileDownloadedToDir(): void
    {
        $dir = sys_get_temp_dir() . '/letts-out-' . uniqid();
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'output_file',
            downloadOutputsTo: $dir,
        );
        $this->assertTrue($r->isSuccess());
        $this->assertFileExists("$dir/result");
        $this->assertSame('hello-from-mission', file_get_contents("$dir/result"));
        unlink("$dir/result");
        rmdir($dir);
    }
}
