<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Exceptions\ConflictException;
use Letts\Internal\IdsUuidV7;
use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientDispatchTest extends DugdaleFixture
{
    public function testDispatchReturnsId(): void
    {
        $id = $this->client()->dispatch(
            host: 'local', lane: 'normal', mission: 'echo_input', input: ['x' => 1],
        );
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testIdempotencyKeyReplay(): void
    {
        $c = $this->client();
        $mid = IdsUuidV7::generate();
        $id = $c->dispatch(host: 'local', lane: 'normal', mission: 'echo_input', input: [], missionId: $mid);
        $id2 = $c->dispatch(host: 'local', lane: 'normal', mission: 'echo_input', input: [], missionId: $mid);
        $this->assertSame($id, $id2);
    }

    public function testIdempotencyConflictOnPayloadMismatch(): void
    {
        $c = $this->client();
        $mid = IdsUuidV7::generate();
        $c->dispatch(host: 'local', lane: 'normal', mission: 'echo_input', input: ['a' => 1], missionId: $mid);
        $this->expectException(ConflictException::class);
        $c->dispatch(host: 'local', lane: 'normal', mission: 'echo_input', input: ['a' => 2], missionId: $mid);
    }
}
