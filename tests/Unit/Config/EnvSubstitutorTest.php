<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\EnvSubstitutor;
use Letts\Exceptions\MissingEnvException;
use PHPUnit\Framework\TestCase;

final class EnvSubstitutorTest extends TestCase
{
    public function testSubstitutesSingleVar(): void
    {
        $sub = new EnvSubstitutor(fn(string $k) => ['FOO' => 'bar'][$k] ?? null);
        $this->assertSame('bar', $sub->substitute('${FOO}'));
    }

    public function testSubstitutesMultipleVars(): void
    {
        $sub = new EnvSubstitutor(fn(string $k) => ['A' => '1', 'B' => '2'][$k] ?? null);
        $this->assertSame('1-2-x', $sub->substitute('${A}-${B}-x'));
    }

    public function testReturnsInputUnchangedWhenNoVars(): void
    {
        $sub = new EnvSubstitutor(fn(string $k) => null);
        $this->assertSame('plain-text', $sub->substitute('plain-text'));
    }

    public function testThrowsMissingEnvException(): void
    {
        $sub = new EnvSubstitutor(fn(string $k) => null);
        $this->expectException(MissingEnvException::class);
        $this->expectExceptionMessage('UNSET');
        $sub->substitute('${UNSET}');
    }
}
