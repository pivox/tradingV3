<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\EmaBelow200WithToleranceCondition;
use PHPUnit\Framework\TestCase;

class EmaBelow200WithToleranceConditionTest extends TestCase
{
    private EmaBelow200WithToleranceCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new EmaBelow200WithToleranceCondition();
    }

    public function testCloseBelowEmaPasses(): void
    {
        $context = [
            'close' => 99.0,
            'ema' => [200 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertTrue($result->passed);
    }

    public function testCloseSlightlyAboveWithinTolerancePasses(): void
    {
        $context = [
            'close' => 100.1,
            'ema' => [200 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertTrue($result->passed);
    }

    public function testCloseWellAboveFails(): void
    {
        $context = [
            'close' => 103.0,
            'ema' => [200 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertFalse($result->passed);
    }
}

