<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\AtrRelInRange15mCondition;
use PHPUnit\Framework\TestCase;

class AtrRelInRange15mConditionTest extends TestCase
{
    private AtrRelInRange15mCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new AtrRelInRange15mCondition();
    }

    public function testWithinRangePasses(): void
    {
        $context = ['atr' => 0.3, 'close' => 100.0];
        $this->assertTrue($this->condition->evaluate($context)->passed);
    }

    public function testBelowRangeFails(): void
    {
        $context = ['atr' => 0.05, 'close' => 100.0];
        $this->assertFalse($this->condition->evaluate($context)->passed);
    }

    public function testAboveRangeFails(): void
    {
        $context = ['atr' => 1.0, 'close' => 100.0];
        $this->assertFalse($this->condition->evaluate($context)->passed);
    }
}

