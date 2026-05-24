<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Mission;

use Letts\Internal\Mission\InputParser;
use PHPUnit\Framework\TestCase;

final class InputParserTest extends TestCase
{
    public function testParsesFlat(): void
    {
        $p = InputParser::fromString('{"item_id":123,"name":"x"}');
        $this->assertSame(123, $p->get('item_id'));
        $this->assertSame('x', $p->get('name'));
    }

    public function testDotNotationNested(): void
    {
        $p = InputParser::fromString('{"config":{"timeout":60,"deep":{"k":"v"}}}');
        $this->assertSame(60, $p->get('config.timeout'));
        $this->assertSame('v', $p->get('config.deep.k'));
    }

    public function testDefault(): void
    {
        $p = InputParser::fromString('{}');
        $this->assertSame('def', $p->get('missing', 'def'));
    }

    public function testHas(): void
    {
        $p = InputParser::fromString('{"a":{"b":1}}');
        $this->assertTrue($p->has('a.b'));
        $this->assertFalse($p->has('a.x'));
        $this->assertFalse($p->has('z'));
    }

    public function testAllReturnsRoot(): void
    {
        $p = InputParser::fromString('{"a":1,"b":2}');
        $this->assertSame(['a' => 1, 'b' => 2], $p->all());
    }

    public function testEmptyInputIsEmpty(): void
    {
        $p = InputParser::fromString('');
        $this->assertSame([], $p->all());
    }

    public function testNullInputIsEmpty(): void
    {
        // Dugdale writes the literal `null` to stdin when a mission is
        // dispatched with empty input. It must NOT fatal.
        $p = InputParser::fromString('null');
        $this->assertSame([], $p->all());
    }

    public function testScalarInputIsEmpty(): void
    {
        // A top-level scalar cannot be dot-navigated; treat as empty rather
        // than crashing the mission on startup.
        $this->assertSame([], InputParser::fromString('42')->all());
        $this->assertSame([], InputParser::fromString('"hi"')->all());
    }

    public function testMissingWithoutDefaultThrows(): void
    {
        $p = InputParser::fromString('{}');
        $this->expectException(\InvalidArgumentException::class);
        $p->get('missing');
    }
}
