<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\MacdHistGtEpsCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class MacdHistGtEpsConditionTest extends TestCase
{
    private MacdHistGtEpsCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new MacdHistGtEpsCondition();
    }

    public function testGetName(): void
    {
        $this->assertSame('macd_hist_gt_eps', $this->condition->getName());
    }

    public function testHistogramAboveThresholdPasses(): void
    {
        $context = [
            'macd' => ['hist' => 0.000001],
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertTrue($result->passed);
        $this->assertEqualsWithDelta(0.000001, $result->value, 1e-9);
    }

    public function testHistogramWithinEpsPasses(): void
    {
        $context = [
            'macd' => ['hist' => -5.0e-7],
        ];

        $result = $this->condition->evaluate($context);

        $this->assertTrue($result->passed, 'Histogram within epsilon tolerance should pass.');
    }

    public function testHistogramBelowToleranceFails(): void
    {
        $context = [
            'macd' => ['hist' => -0.01],
        ];

        $result = $this->condition->evaluate($context);

        $this->assertFalse($result->passed);
    }
}

