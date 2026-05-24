<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class MissionShutdownOOMTest extends DugdaleFixture
{
    public function testOomReportedAsOutcome(): void
    {
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'allocate_oom',
            throwOnFailure: false,
        );
        // Two paths can surface here:
        //  - dugdale's stderr OOMDetector matches "Allowed memory size of …" →
        //    outcome=oom, fail_reason=php_memory_limit (fail_message/details
        //    intentionally NOT carried).
        //  - If the marker is missed and the fd 3 `fail` event from
        //    ShutdownHandler wins instead, outcome=failed with reason and message.
        $this->assertContains($r->outcome, ['failed', 'oom'], "outcome=$r->outcome");
        // failReason is the load-bearing classification — both paths set it
        // to php_memory_limit (or another php_* reason on the fd3 path).
        $this->assertSame('php_memory_limit', $r->failReason, "failReason=$r->failReason");
    }
}
