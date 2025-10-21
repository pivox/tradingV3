<?php

declare(strict_types=1);

namespace App\Tests\Indicator\ConditionLoader\Rule;

use App\Indicator\Condition\ConditionResult;
use App\Indicator\ConditionLoader\Cards\Rule\Rule;
use App\Indicator\ConditionLoader\Cards\Rule\RuleElementTrend;
use App\Indicator\ConditionLoader\ConditionRegistry;
use App\Indicator\Condition\ConditionInterface;
use PHPUnit\Framework\TestCase;

final class RuleTrendTest extends TestCase
{
    /**
     * @dataProvider provideTrendCases
     */
    public function testTrendRules(
        array $definition,
        array $context,
        bool $expectedPassed,
        callable $assertion
    ): void {
        $rule = (new Rule())->fill($definition);

        $result = $rule->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertSame($expectedPassed, $result->passed, 'Unexpected pass/fail result.');
        $assertion($this, $result);
    }

    public static function provideTrendCases(): iterable
    {
        $baseIncreasing = [
            'macd_hist_increasing_n' => [
                RuleElementTrend::INCREASING => [
                    'field' => 'macd_hist',
                    'n' => 2,
                    'strict' => true,
                    'eps' => 1.0e-8,
                ],
            ],
        ];

        $baseDecreasing = [
            'macd_hist_decreasing_n' => [
                RuleElementTrend::DECREASING => [
                    'field' => 'macd_hist',
                    'n' => 2,
                    'strict' => true,
                    'eps' => 1.0e-8,
                ],
            ],
        ];

        yield 'increasing_strict_passes' => [
            $baseIncreasing,
            ['macd_hist_series' => [0.012, 0.009, 0.005]],
            true,
            function (TestCase $self, ConditionResult $result): void {
                $self->assertArrayHasKey('diffs', $result->meta);
                $self->assertSame(2, $result->meta['comparisons'] ?? null);
                $self->assertGreaterThan(1.0e-3, $result->meta['diffs'][0] ?? 0.0);
            },
        ];

        yield 'increasing_strict_fails_within_eps' => [
            $baseIncreasing,
            ['macd_hist_series' => [0.012, 0.012 - 5.0e-9, 0.005]],
            false,
            function (TestCase $self, ConditionResult $result): void {
                $self->assertFalse($result->meta['missing_data'] ?? false);
            },
        ];

        yield 'increasing_non_strict_allows_small_drop' => [
            [
                'macd_hist_increasing_n' => [
                    RuleElementTrend::INCREASING => [
                        'field' => 'macd_hist',
                        'n' => 2,
                        'strict' => false,
                        'eps' => 1.0e-6,
                    ],
                ],
            ],
            ['macd_hist_series' => [1.0, 1.0 + 5.0e-7, 0.9995]],
            true,
            function (TestCase $self, ConditionResult $result): void {
                $self->assertArrayHasKey('diffs', $result->meta);
                $self->assertTrue($result->passed);
            },
        ];

        yield 'decreasing_strict_passes' => [
            $baseDecreasing,
            ['macd_hist_series' => [0.001, 0.005, 0.01]],
            true,
            function (TestCase $self, ConditionResult $result): void {
                $self->assertTrue($result->passed);
                $self->assertSame(2, $result->meta['comparisons'] ?? null);
            },
        ];

        yield 'decreasing_non_strict_allows_small_rise' => [
            [
                'macd_hist_decreasing_n' => [
                    RuleElementTrend::DECREASING => [
                        'field' => 'macd_hist',
                        'n' => 2,
                        'strict' => false,
                        'eps' => 1.0e-6,
                    ],
                ],
            ],
            ['macd_hist_series' => [0.01, 0.0100000005, 0.015]],
            true,
            function (TestCase $self, ConditionResult $result): void {
                $self->assertTrue($result->passed);
            },
        ];

        yield 'insufficient_points_flagged_as_missing' => [
            $baseIncreasing,
            ['macd_hist_series' => [0.01, 0.009]],
            false,
            function (TestCase $self, ConditionResult $result): void {
                $self->assertFalse($result->passed);
                $self->assertTrue($result->meta['missing_data'] ?? false);
                $self->assertSame(2, $result->meta['available_points'] ?? null);
            },
        ];

        yield 'missing_series_returns_missing_data' => [
            $baseIncreasing,
            [],
            false,
            function (TestCase $self, ConditionResult $result): void {
                $self->assertTrue($result->meta['missing_data'] ?? false);
            },
        ];
    }

    public function testInvalidDefinitionThrows(): void
    {
        $definition = [
            'macd_hist_increasing_n' => [
                RuleElementTrend::INCREASING => [
                    'n' => 2,
                ],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);

        (new Rule())->fill($definition);
    }

    public function testCompositeAnyOfRule(): void
    {
        $definition = [
            'close_above_vwap_or_ma9' => [
                'any_of' => [
                    'close_above_vwap',
                    'close_above_ma_9',
                ],
            ],
        ];

        $original = ConditionRegistry::$conditions;
        try {
            ConditionRegistry::$conditions = [
                'close_above_vwap' => new class implements ConditionInterface {
                    public function getName(): string { return 'close_above_vwap'; }
                    public function evaluate(array $context): ConditionResult
                    {
                        return new ConditionResult('close_above_vwap', true);
                    }
                },
                'close_above_ma_9' => new class implements ConditionInterface {
                    public function getName(): string { return 'close_above_ma_9'; }
                    public function evaluate(array $context): ConditionResult
                    {
                        return new ConditionResult('close_above_ma_9', false);
                    }
                },
            ];

            $rule = (new Rule())->fill($definition);
            $result = $rule->evaluate([]);

            $this->assertTrue($result->passed);
            $meta = $result->meta;
            $this->assertSame('any_of', $meta['type'] ?? null);
            $this->assertCount(2, $meta['items'] ?? []);
        } finally {
            ConditionRegistry::$conditions = $original;
        }
    }
}
