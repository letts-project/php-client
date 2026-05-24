<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientStagingUploadTest extends DugdaleFixture
{
    public function testUploadAndConsumeInMission(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($tmp, str_repeat('B', 1024));
        $expectedSha = hash_file('sha256', $tmp);

        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'uses_input_files',
            files: ['photo' => $tmp],
        );
        $this->assertTrue($r->isSuccess(), 'mission failed: ' . ($r->failMessage ?? ''));
        $this->assertSame($expectedSha, $r->return['sha']);
        $this->assertSame(1024, $r->return['size']);
        unlink($tmp);
    }

    public function testContentAddressedReuseSkipsSecondUpload(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dup');
        file_put_contents($tmp, 'same-bytes');

        $c = $this->client();
        $r1 = $c->run(host: 'local', lane: 'normal', mission: 'uses_input_files', files: ['photo' => $tmp]);
        $this->assertTrue($r1->isSuccess());
        $r2 = $c->run(host: 'local', lane: 'normal', mission: 'uses_input_files', files: ['photo' => $tmp]);
        $this->assertSame($r1->return['sha'], $r2->return['sha']);
        unlink($tmp);
    }
}
