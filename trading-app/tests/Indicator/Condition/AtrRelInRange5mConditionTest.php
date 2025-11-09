<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\AtrRelInRange5mCondition;
use PHPUnit\Framework\TestCase;

class AtrRelInRange5mConditionTest extends TestCase
{
    private AtrRelInRange5mCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new AtrRelInRange5mCondition();
    }

    public function testWithinRangePasses(): void
    {
        $context = ['atr' => 0.2, 'close' => 100.0];
        $this->assertTrue($this->condition->evaluate($context)->passed);
    }

    public function testBelowRangeFails(): void
    {
        $context = ['atr' => 0.05, 'close' => 200.0];
        $this->assertFalse($this->condition->evaluate($context)->passed);
    }

    public function testAboveRangeFails(): void
    {
        // ratio = 2.5 / 100.0 = 0.025 > 0.0200 (MAX), doit échouer
        $context = ['atr' => 2.5, 'close' => 100.0];
        $this->assertFalse($this->condition->evaluate($context)->passed);
    }
}

