<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientRunParallelMixedTest extends DugdaleFixture
{
    public function testOneSuccessOneFailed(): void
    {
        $results = $this->client()->runParallel([
            ['host' => 'local', 'lane' => 'normal', 'mission' => 'echo_input', 'input' => ['v' => 1]],
            ['host' => 'local', 'lane' => 'normal', 'mission' => 'fail_message', 'input' => []],
        ]);
        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isReachable());
        $this->assertTrue($results[0]->isSuccess());
        $this->assertTrue($results[1]->isReachable());
        $this->assertFalse($results[1]->isSuccess());
        $this->assertSame('failed', $results[1]->result->outcome);
    }
}
