<?php

declare(strict_types=1);

namespace App\Tests\Indicator\ConditionLoader\Rule;

use App\Indicator\Condition\ConditionResult;
use App\Indicator\ConditionLoader\Cards\Rule\Rule;
use PHPUnit\Framework\TestCase;

final class RuleMacdDerivativeTest extends TestCase
{
    public function testPositiveDerivativePasses(): void
    {
        $definition = [
            'macd_hist_slope_pos' => [
                'derivative_gt' => 0.0,
                'persist_n' => 2,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                0.0018,
                0.0012,
                0.0005,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertTrue($result->passed);
        $this->assertSame([0.0006, 0.0007], array_map(static fn($v): float => round($v, 7), $result->meta['diffs'] ?? []));
    }

    public function testPositiveDerivativeFailsWhenSlopeDrops(): void
    {
        $definition = [
            'macd_hist_slope_pos' => [
                'derivative_gt' => 0.0,
                'persist_n' => 2,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                0.0010,
                0.0012,
                0.0009,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertFalse($result->passed);
        $this->assertSame('derivative_not_gt', $result->meta['reason'] ?? null);
        $this->assertSame(0, $result->meta['failed_at'] ?? null);
    }

    public function testNegativeDerivativePasses(): void
    {
        $definition = [
            'macd_hist_slope_neg' => [
                'derivative_lt' => 0.0,
                'persist_n' => 2,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                -0.0004,
                -0.0001,
                0.0002,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertTrue($result->passed);
        $this->assertCount(2, $result->meta['diffs'] ?? []);
        $this->assertLessThan(0.0, $result->meta['diffs'][0] ?? 1.0);
    }

    public function testDerivativeFailsWithInsufficientPoints(): void
    {
        $definition = [
            'macd_hist_slope_pos' => [
                'derivative_gt' => 0.0,
                'persist_n' => 3,
            ],
        ];

        $context = [
            'macd_hist_series' => [
                0.0009,
                0.0007,
                0.0005,
            ],
        ];

        $rule = (new Rule())->fill($definition);
        $result = $rule->evaluate($context);

        $this->assertFalse($result->passed);
        $this->assertSame('insufficient_points', $result->meta['reason'] ?? null);
        $this->assertTrue($result->meta['missing_data'] ?? false);
    }
}
