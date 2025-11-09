<?php

declare(strict_types=1);

namespace App\Tests\Indicator\ConditionLoader\Rule;

use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\ConditionLoader\Cards\Rule\Rule;
use PHPUnit\Framework\TestCase;

final class RuleMacdHysteresisTest extends TestCase
{
    public function testCrossUpPassesWithRecentCross(): void
    {
        $definition = [
            'macd_line_cross_up_with_hysteresis' => [
                'require_prev_below' => true,
                'min_gap' => 0.0003,
                'cool_down_bars' => 2,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                0.00045,
                0.00058,
                -0.00072,
                -0.00041,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertTrue($result->passed, 'Expected cross up with hysteresis to pass.');
        $this->assertSame(1, $result->meta['bars_since_cross'] ?? null);
        $this->assertEqualsWithDelta(0.00058, $result->meta['trigger_gap'] ?? 0.0, 1.0e-8);
        $this->assertArrayHasKey('series_sample', $result->meta);
    }

    public function testCrossUpFailsWhenPreviousNotBelowThreshold(): void
    {
        $definition = [
            'macd_line_cross_up_with_hysteresis' => [
                'require_prev_below' => true,
                'min_gap' => 0.0003,
                'cool_down_bars' => 2,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                0.00045,   // current
                0.00040,   // previous bar (never went below threshold)
                0.00010,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertFalse($result->passed, 'Cross up should fail without prior gap below threshold.');
        $this->assertSame('no_recent_cross', $result->meta['reason'] ?? null);
    }

    public function testCrossDownPassesWithinCooldownWindow(): void
    {
        $definition = [
            'macd_line_cross_down_with_hysteresis' => [
                'require_prev_above' => true,
                'min_gap' => 0.0003,
                'cool_down_bars' => 3,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                -0.00038,
                -0.00042,
                0.00052,
                0.00061,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertTrue($result->passed, 'Expected cross down with hysteresis to pass.');
        $this->assertSame(1, $result->meta['bars_since_cross'] ?? null);
        $this->assertLessThan(0.0, $result->meta['trigger_gap'] ?? 1.0);
    }

    public function testCrossDownFailsWhenCrossOutsideCooldown(): void
    {
        $definition = [
            'macd_line_cross_down_with_hysteresis' => [
                'require_prev_above' => true,
                'min_gap' => 0.0003,
                'cool_down_bars' => 1,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                -0.00032,
                -0.00028,
                -0.00018,
                0.00045,    // cross happened 3 bars ago (> cooldown)
                0.00060,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertFalse($result->passed, 'Cross down should fail when outside cooldown window.');
        $this->assertSame('no_recent_cross', $result->meta['reason'] ?? null);
    }
}
