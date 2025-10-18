<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\Ema20Over50WithToleranceCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class Ema20Over50WithToleranceConditionTest extends TestCase
{
    private Ema20Over50WithToleranceCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new Ema20Over50WithToleranceCondition();
    }

    public function testGetName(): void
    {
        $this->assertSame('ema20_over_50_with_tolerance', $this->condition->getName());
    }

    public function testStrictlyAbovePasses(): void
    {
        $context = [
            'ema' => [20 => 102.0, 50 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0, $result->value);
    }

    public function testWithinTolerancePasses(): void
    {
        $context = [
            'ema' => [20 => 99.95, 50 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertTrue($result->passed, 'Difference within tolerance should pass.');
    }

    public function testBeyondToleranceFails(): void
    {
        $context = [
            'ema' => [20 => 95.0, 50 => 100.0],
        ];

        $result = $this->condition->evaluate($context);
        $this->assertFalse($result->passed);
    }
}

