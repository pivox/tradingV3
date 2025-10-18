<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\RsiLtSoftcapCondition;
use PHPUnit\Framework\TestCase;

class RsiLtSoftcapConditionTest extends TestCase
{
    private RsiLtSoftcapCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new RsiLtSoftcapCondition();
    }

    public function testBelowThresholdPasses(): void
    {
        $context = ['rsi' => 70.0];
        $this->assertTrue($this->condition->evaluate($context)->passed);
    }

    public function testAboveThresholdFails(): void
    {
        $context = ['rsi' => 80.0];
        $this->assertFalse($this->condition->evaluate($context)->passed);
    }
}

