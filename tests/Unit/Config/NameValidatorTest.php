<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\NameValidator;
use PHPUnit\Framework\TestCase;

final class NameValidatorTest extends TestCase
{
    public function testValidDugdaleIds(): void
    {
        foreach (['s1', 'server-1', 'main_prod', 'a', 'abc1_2-3'] as $id) {
            $this->assertTrue(NameValidator::isDugdaleId($id), "expected valid: $id");
        }
    }

    public function testInvalidDugdaleIds(): void
    {
        foreach (['', '1s', 'S1', 'with space', 'Длинный', str_repeat('a', 65)] as $id) {
            $this->assertFalse(NameValidator::isDugdaleId($id), "expected invalid: $id");
        }
    }

    public function testValidLaneNames(): void
    {
        foreach (['normal', 'high', 'a', 'lane-1', 'lane_2'] as $n) {
            $this->assertTrue(NameValidator::isLaneName($n));
        }
    }

    public function testInvalidLaneNames(): void
    {
        foreach (['', 'Normal', '1lane', 'with space', str_repeat('a', 33)] as $n) {
            $this->assertFalse(NameValidator::isLaneName($n));
        }
    }

    public function testTemplateAndRouteRegexMatchesDugdaleId(): void
    {
        $this->assertTrue(NameValidator::isTemplateName('my-tpl'));
        $this->assertTrue(NameValidator::isRouteName('manual'));
        $this->assertFalse(NameValidator::isTemplateName('Bad'));
    }
}
