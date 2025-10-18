<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\EmaAbove200WithToleranceCondition;
use PHPUnit\Framework\TestCase;

class EmaAbove200WithToleranceConditionTest extends TestCase
{
    private EmaAbove200WithToleranceCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new EmaAbove200WithToleranceCondition();
    }

    public function testCloseAboveEmaPasses(): void
    {
        $context = [
            'close' => 101.0,
            'ema' => [200 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertTrue($result->passed);
    }

    public function testCloseSlightlyBelowWithinTolerancePasses(): void
    {
        $context = [
            'close' => 99.9,
            'ema' => [200 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertTrue($result->passed);
    }

    public function testCloseWellBelowFails(): void
    {
        $context = [
            'close' => 95.0,
            'ema' => [200 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertFalse($result->passed);
    }
}

