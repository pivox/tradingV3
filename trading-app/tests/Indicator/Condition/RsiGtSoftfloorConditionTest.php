<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\RsiGtSoftfloorCondition;
use PHPUnit\Framework\TestCase;

class RsiGtSoftfloorConditionTest extends TestCase
{
    private RsiGtSoftfloorCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new RsiGtSoftfloorCondition();
    }

    public function testAboveThresholdPasses(): void
    {
        $this->assertTrue($this->condition->evaluate(['rsi' => 30.0])->passed);
    }

    public function testBelowThresholdFails(): void
    {
        $this->assertFalse($this->condition->evaluate(['rsi' => 15.0])->passed);
    }
}

