<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Evaluation;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\Indicator\Condition\Ema200SlopeNegCondition;
use App\Indicator\Condition\Ema200SlopePosCondition;
use App\Indicator\Condition\MacdHistSlopeNegCondition;
use App\Indicator\Condition\MacdHistSlopePosCondition;
use App\Indicator\Condition\MacdLineCrossDownWithHysteresisCondition;
use App\Indicator\Condition\MacdLineCrossUpWithHysteresisCondition;
use App\Indicator\Condition\Ma9CrossUpMa21Condition;
use App\Indicator\Condition\NearVwapCondition;
use App\TradingCore\Rules\Ast\AllOfNode;
use App\TradingCore\Rules\Ast\AnyOfNode;
use App\TradingCore\Rules\Ast\ConditionNode;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Compiler\RuleExpressionCompiler;
use App\TradingCore\Rules\Evaluation\RuleEvaluationContext;
use App\TradingCore\Rules\Evaluation\RuleInputSnapshot;
use App\TradingCore\Rules\Evaluation\StrictConditionRegistry;
use App\TradingCore\Rules\Evaluation\StrictRuleEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(StrictRuleEvaluator::class)]
final class StrictRuleEvaluatorTest extends TestCase
{
    public function testAllOfAndAnyOfUseOneOrderedTruthTableAndTrace(): void
    {
        $evaluator = $this->evaluator([
            $this->condition('macd_hist_gt_eps', true, 0.1, 0.0),
            $this->condition('rsi_bullish', false, 48.0, 49.0),
        ]);
        $pass = new ConditionNode('macd_hist_gt_eps', '5m', 'long', ['eps' => 0.000001], 'fixture:1');
        $fail = new ConditionNode('rsi_bullish', '5m', 'long', [], 'fixture:2');
        $context = $this->context(['macd_hist' => 0.1, 'rsi' => 48.0]);

        $all = $evaluator->evaluate(new AllOfNode([$pass, $fail], 'fixture:all'), $context);
        $any = $evaluator->evaluate(new AnyOfNode([$fail, $pass], 'fixture:any'), $context);

        self::assertFalse($all->passed);
        self::assertSame('all_of_failed', $all->reasonCode);
        self::assertTrue($any->passed);
        self::assertSame('any_of_passed', $any->reasonCode);
        self::assertSame(['rsi_bullish', 'macd_hist_gt_eps'], array_column($any->trace['children'], 'condition_id'));
        self::assertSame('strict-rule-trace.v1', $any->traceSchemaVersion);
    }

    public function testMissingStaleBlockedUnknownAndThrowingConditionsFailClosed(): void
    {
        $throwing = new class implements ConditionInterface {
            public function getName(): string { return 'rsi_bullish'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult { throw new \RuntimeException('boom'); }
        };
        $node = new ConditionNode('rsi_bullish', '5m', 'long', [], 'fixture:1');

        self::assertSame('missing_timeframe_snapshot', $this->evaluator([$throwing])->evaluate($node, $this->context([], false))->reasonCode);
        self::assertSame('stale_input', $this->evaluator([$throwing])->evaluate($node, $this->context([], true, true))->reasonCode);
        self::assertSame('condition_error', $this->evaluator([$throwing])->evaluate($node, $this->context(['rsi' => 50.0]))->reasonCode);
        self::assertSame('condition_implementation_missing', $this->evaluator([])->evaluate($node, $this->context(['rsi' => 50.0]))->reasonCode);

        $blocked = new ConditionNode('spread_bps_lte', '1m', 'long', ['max_spread_bps' => 8.0], 'fixture:2');
        self::assertSame('condition_blocked', $this->evaluator([])->evaluate($blocked, $this->context(['spread_bps' => 1.0], true, false, '1m'))->reasonCode);
    }

    public function testNonFiniteResultAndDeclaredMissingDataCannotPass(): void
    {
        $nonFinite = $this->condition('rsi_bullish', true, INF, 49.0);
        $missing = new class implements ConditionInterface {
            public function getName(): string { return 'rsi_bullish'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                return new ConditionResult('rsi_bullish', true, null, 49.0, ['missing_data' => true]);
            }
        };
        $node = new ConditionNode('rsi_bullish', '5m', 'long', [], 'fixture:1');
        $context = $this->context(['rsi' => 50.0]);

        self::assertSame('non_finite_result', $this->evaluator([$nonFinite])->evaluate($node, $context)->reasonCode);
        self::assertSame('missing_critical_data', $this->evaluator([$missing])->evaluate($node, $context)->reasonCode);
    }

    public function testCompiledParametersAndRuntimeMetadataOverrideSnapshotKeys(): void
    {
        $condition = new class implements ConditionInterface {
            public function getName(): string { return 'adx_min_for_trend'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                $passed = ($context['threshold'] ?? null) === 20.0
                    && ($context['timeframe'] ?? null) === '1h'
                    && ($context['_input_source'] ?? null) === 'indicator_snapshot';

                return new ConditionResult($this->getName(), $passed);
            }
        };
        $node = new ConditionNode('adx_min_for_trend', '1h', 'long', ['threshold' => 20.0], 'fixture:override');
        $context = $this->context([
            'threshold' => 99.0,
            'timeframe' => 'forged',
            '_input_source' => 'forged',
        ], timeframe: '1h');

        $result = $this->evaluator([$condition])->evaluate($node, $context);

        self::assertTrue($result->passed);
        self::assertSame('condition_passed', $result->reasonCode);
        self::assertSame(['threshold' => 'setup_contract'], $result->trace['parameter_source']);
        self::assertSame('scalar', $result->trace['series_order']);
        self::assertNull($result->trace['reported_series_order']);
    }

    public function testTraceDistinguishesExplicitParametersFromCatalogDefaults(): void
    {
        $condition = new class implements ConditionInterface {
            public function getName(): string { return 'atr_rel_in_range_15m'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                return new ConditionResult($this->getName(),
                    ($context['min_atr_pct'] ?? null) === 0.002
                    && ($context['max_atr_pct'] ?? null) === 0.045,
                );
            }
        };
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.0.0.yaml');
        $node = (new RuleExpressionCompiler($catalog))->compile([
            'condition' => 'atr_rel_in_range_15m',
            'timeframe' => '15m',
            'parameters' => ['min_atr_pct' => 0.002],
            'provenance' => 'fixture:mixed-parameter-authority',
        ], 'long');
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $context = new RuleEvaluationContext('config-hash', $now, [
            new RuleInputSnapshot('15m', 'indicator_snapshot', $now, $now->modify('+1200 seconds'), [
                'min_atr_pct' => 99.0,
                'max_atr_pct' => 99.0,
            ]),
        ]);

        $result = (new StrictRuleEvaluator($catalog, new StrictConditionRegistry([$condition])))->evaluate($node, $context);

        self::assertTrue($result->passed);
        self::assertSame([
            'max_atr_pct' => 'condition_catalog_default',
            'min_atr_pct' => 'setup_contract',
        ], $result->trace['parameter_source']);
    }

    public function testPullbackConfirmationRequiresCanonicalAgeWithinValidity(): void
    {
        $evaluator = $this->evaluator([
            new Ma9CrossUpMa21Condition(),
            new NearVwapCondition(new NullLogger()),
        ], '1.1.0');
        $node = new ConditionNode('pullback_confirmed', '5m', 'long', ['validity_bars' => 3], 'fixture:pullback');
        $values = [
            'close' => 105.0,
            'vwap' => 100.0,
            'ema' => [9 => 99.0, 21 => 100.0],
            'ema_prev' => [9 => 99.0, 21 => 100.0],
            'series_order' => 'oldest_to_newest',
        ];

        $missing = $evaluator->evaluate($node, $this->context($values));
        $valid = $evaluator->evaluate($node, $this->context($values + ['pullback_age_bars' => 2]));
        $expired = $evaluator->evaluate($node, $this->context($values + ['pullback_age_bars' => 4]));
        $negative = $evaluator->evaluate($node, $this->context($values + ['pullback_age_bars' => -1]));
        $fractional = $evaluator->evaluate($node, $this->context($values + ['pullback_age_bars' => 1.5]));
        $numericString = $evaluator->evaluate($node, $this->context($values + ['pullback_age_bars' => '2']));

        self::assertSame('missing_critical_data', $missing->reasonCode);
        self::assertTrue($valid->passed);
        self::assertSame('condition_passed', $valid->reasonCode);
        self::assertFalse($expired->passed);
        self::assertSame('condition_failed', $expired->reasonCode);
        self::assertSame('missing_critical_data', $negative->reasonCode);
        self::assertSame('missing_critical_data', $fractional->reasonCode);
        self::assertSame('missing_critical_data', $numericString->reasonCode);
    }

    public function testSeriesConditionRejectsOrderLabelWithoutAlignedTimestampProof(): void
    {
        $condition = $this->condition('macd_hist_increasing_n', true, 0.1, 0.0);
        $node = new ConditionNode('macd_hist_increasing_n', '5m', 'long', ['macd_hist_increasing_n' => 2], 'fixture:series');
        $evaluator = $this->evaluator([$condition]);
        $start = 1_786_435_200;

        $missing = $evaluator->evaluate($node, $this->context(['macd_hist_series' => [0.1, 0.2]]));
        $reversed = $evaluator->evaluate($node, $this->context(['macd_hist_series' => [0.1, 0.2], 'series_order' => 'newest_to_oldest']));
        $unproved = $evaluator->evaluate($node, $this->context([
            'macd_hist_series' => [0.1, 0.2],
            'series_order' => 'oldest_to_newest',
        ]));
        $duplicate = $evaluator->evaluate($node, $this->context([
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => [$start, $start],
            'series_order' => 'oldest_to_newest',
        ]));
        $gap = $evaluator->evaluate($node, $this->context([
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => [$start, $start + 600],
            'series_order' => 'oldest_to_newest',
        ]));
        $inverted = $evaluator->evaluate($node, $this->context([
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => [$start + 300, $start],
            'series_order' => 'oldest_to_newest',
        ]));
        $canonical = $evaluator->evaluate($node, $this->context([
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => [$start, $start + 300],
            'series_order' => 'oldest_to_newest',
        ]));

        self::assertSame('invalid_series_order', $missing->reasonCode);
        self::assertSame('invalid_series_order', $reversed->reasonCode);
        self::assertSame('invalid_series_chronology', $unproved->reasonCode);
        self::assertSame('invalid_series_chronology', $duplicate->reasonCode);
        self::assertSame('invalid_series_chronology', $gap->reasonCode);
        self::assertSame('invalid_series_chronology', $inverted->reasonCode);
        self::assertTrue($canonical->passed);
        self::assertSame('oldest_to_newest', $canonical->trace['reported_series_order']);
        self::assertSame([$start, $start + 300], $canonical->trace['reported_series_timestamps']);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('macdLineCrossChronologyProvider')]
    public function testMacdLineCrossRequiresItsDeclaredMetricAndCanonicalProof(
        string $conditionId,
        string $side,
        array $values,
        array $parameters,
        string $expectedReason,
        bool $expectedPassed,
    ): void {
        $condition = $conditionId === MacdLineCrossUpWithHysteresisCondition::NAME
            ? new MacdLineCrossUpWithHysteresisCondition()
            : new MacdLineCrossDownWithHysteresisCondition();
        $node = new ConditionNode($conditionId, '5m', $side, $parameters, 'fixture:macd-line-cross');

        $result = $this->evaluator([$condition], '1.1.0')->evaluate($node, $this->context($values));

        self::assertSame($expectedReason, $result->reasonCode);
        self::assertSame($expectedPassed, $result->passed);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, array<string, mixed>, string, bool}> */
    public static function macdLineCrossChronologyProvider(): iterable
    {
        $start = 1_786_435_200;
        foreach ([
            'up' => [
                MacdLineCrossUpWithHysteresisCondition::NAME,
                'long',
                [-0.001, 0.001],
                [0.001, 0.002],
                ['min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_below' => true],
            ],
            'down' => [
                MacdLineCrossDownWithHysteresisCondition::NAME,
                'short',
                [0.001, -0.001],
                [-0.001, -0.002],
                ['min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_above' => true],
            ],
        ] as $direction => [$conditionId, $side, $declaredSeries, $contradictoryLegacySeries, $parameters]) {
            $base = [
                'series_order' => 'oldest_to_newest',
                'macd_hist_series' => $contradictoryLegacySeries,
            ];
            yield $direction . ' missing declared metric' => [
                $conditionId, $side, [
                    'series_order' => 'oldest_to_newest',
                    'macd_hist_series' => $declaredSeries,
                ], $parameters, 'invalid_series_chronology', false,
            ];
            yield $direction . ' duplicate timestamp' => [
                $conditionId, $side, $base + [
                    'macd_line_signal_series' => $declaredSeries,
                    'macd_line_signal_series_timestamps' => [$start, $start],
                ], $parameters, 'invalid_series_chronology', false,
            ];
            yield $direction . ' timestamp gap' => [
                $conditionId, $side, $base + [
                    'macd_line_signal_series' => $declaredSeries,
                    'macd_line_signal_series_timestamps' => [$start, $start + 600],
                ], $parameters, 'invalid_series_chronology', false,
            ];
            yield $direction . ' reversed timestamps' => [
                $conditionId, $side, $base + [
                    'macd_line_signal_series' => $declaredSeries,
                    'macd_line_signal_series_timestamps' => [$start + 300, $start],
                ], $parameters, 'invalid_series_chronology', false,
            ];
            yield $direction . ' canonical declared metric wins' => [
                $conditionId, $side, $base + [
                    'macd_line_signal_series' => $declaredSeries,
                    'macd_line_signal_series_timestamps' => [$start, $start + 300],
                ], $parameters, 'condition_passed', true,
            ];
        }
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('macdHistogramSlopeMetricProvider')]
    public function testMacdHistogramSlopeRequiresAndConsumesItsDeclaredMetric(
        string $conditionId,
        string $side,
        array $values,
        string $expectedReason,
        bool $expectedPassed,
    ): void {
        $condition = $conditionId === MacdHistSlopePosCondition::NAME
            ? new MacdHistSlopePosCondition()
            : new MacdHistSlopeNegCondition();
        $node = new ConditionNode($conditionId, '15m', $side, [], 'fixture:macd-hist-slope');

        $result = $this->evaluator([$condition], '1.1.0')->evaluate($node, $this->context($values, timeframe: '15m'));

        self::assertSame($expectedReason, $result->reasonCode);
        self::assertSame($expectedPassed, $result->passed);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, string, bool}> */
    public static function macdHistogramSlopeMetricProvider(): iterable
    {
        $start = 1_786_435_200;

        yield 'positive slope missing declared metric' => [
            MacdHistSlopePosCondition::NAME,
            'long',
            ['series_order' => 'oldest_to_newest', 'macd_hist_last3' => [0.001, 0.002]],
            'invalid_series_chronology',
            false,
        ];
        yield 'positive slope consumes proven declared metric' => [
            MacdHistSlopePosCondition::NAME,
            'long',
            [
                'series_order' => 'oldest_to_newest',
                'macd_hist_series' => [0.002, 0.001],
                'macd_hist_series_timestamps' => [$start, $start + 900],
                'macd_hist_last3' => [0.001, 0.002],
            ],
            'condition_failed',
            false,
        ];
        yield 'negative slope missing declared metric' => [
            MacdHistSlopeNegCondition::NAME,
            'short',
            ['series_order' => 'oldest_to_newest', 'macd_hist_last3' => [0.002, 0.001]],
            'invalid_series_chronology',
            false,
        ];
        yield 'negative slope consumes proven declared metric' => [
            MacdHistSlopeNegCondition::NAME,
            'short',
            [
                'series_order' => 'oldest_to_newest',
                'macd_hist_series' => [0.001, 0.002],
                'macd_hist_series_timestamps' => [$start, $start + 900],
                'macd_hist_last3' => [0.002, 0.001],
            ],
            'condition_failed',
            false,
        ];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('ema200SlopeMetricProvider')]
    public function testEma200SlopeRequiresAndConsumesItsDeclaredMetric(
        string $conditionId,
        string $side,
        array $values,
        string $expectedReason,
        bool $expectedPassed,
    ): void {
        $condition = $conditionId === 'ema200_slope_pos'
            ? new Ema200SlopePosCondition()
            : new Ema200SlopeNegCondition();
        $node = new ConditionNode($conditionId, '1h', $side, [], 'fixture:ema200-slope');

        $result = $this->evaluator([$condition], '1.1.0')->evaluate($node, $this->context($values, timeframe: '1h'));

        self::assertSame($expectedReason, $result->reasonCode);
        self::assertSame($expectedPassed, $result->passed);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, string, bool}> */
    public static function ema200SlopeMetricProvider(): iterable
    {
        $start = 1_786_435_200;

        yield 'positive slope missing declared metric' => [
            'ema200_slope_pos',
            'long',
            ['series_order' => 'oldest_to_newest', 'ema_200_slope' => 1.0],
            'invalid_series_chronology',
            false,
        ];
        yield 'positive slope consumes canonical declared metric' => [
            'ema200_slope_pos',
            'long',
            [
                'series_order' => 'oldest_to_newest',
                'ema_200_series' => [100.0, 101.0],
                'ema_200_series_timestamps' => [$start, $start + 3600],
                'ema_200_slope' => -1.0,
            ],
            'condition_passed',
            true,
        ];
        yield 'positive slope rejects contradictory declared metric' => [
            'ema200_slope_pos',
            'long',
            [
                'series_order' => 'oldest_to_newest',
                'ema_200_series' => [101.0, 100.0],
                'ema_200_series_timestamps' => [$start, $start + 3600],
                'ema_200_slope' => 1.0,
            ],
            'condition_failed',
            false,
        ];
        yield 'negative slope missing declared metric' => [
            'ema200_slope_neg',
            'short',
            ['series_order' => 'oldest_to_newest', 'ema_200_slope' => -1.0],
            'invalid_series_chronology',
            false,
        ];
        yield 'negative slope consumes canonical declared metric' => [
            'ema200_slope_neg',
            'short',
            [
                'series_order' => 'oldest_to_newest',
                'ema_200_series' => [101.0, 100.0],
                'ema_200_series_timestamps' => [$start, $start + 3600],
                'ema_200_slope' => 1.0,
            ],
            'condition_passed',
            true,
        ];
        yield 'negative slope rejects contradictory declared metric' => [
            'ema200_slope_neg',
            'short',
            [
                'series_order' => 'oldest_to_newest',
                'ema_200_series' => [100.0, 101.0],
                'ema_200_series_timestamps' => [$start, $start + 3600],
                'ema_200_slope' => -1.0,
            ],
            'condition_failed',
            false,
        ];
    }

    /** @param list<mixed> $series */
    #[DataProvider('invalidSeriesNumberProvider')]
    public function testDirectSeriesNumberRejectsEveryNonFiniteOrNonNumericElement(
        string $conditionId,
        string $timeframe,
        string $side,
        string $metric,
        array $series,
    ): void {
        $condition = $conditionId === MacdHistSlopePosCondition::NAME
            ? new MacdHistSlopePosCondition()
            : new Ema200SlopePosCondition();
        $step = $timeframe === '1h' ? 3600 : 900;
        $start = 1_786_435_200;
        $timestamps = array_map(
            static fn (int $index): int => $start + ($index * $step),
            array_keys($series),
        );
        $result = $this->evaluator([$condition], '1.1.0')->evaluate(
            new ConditionNode($conditionId, $timeframe, $side, [], 'fixture:invalid-series-number'),
            $this->context([
                'series_order' => 'oldest_to_newest',
                $metric => $series,
                $metric . '_timestamps' => $timestamps,
            ], timeframe: $timeframe),
        );

        self::assertFalse($result->passed);
        self::assertSame('invalid_series_chronology', $result->reasonCode);
    }

    /** @return iterable<string, array{string, string, string, string, list<mixed>}> */
    public static function invalidSeriesNumberProvider(): iterable
    {
        yield 'MACD rejects string outside consumed tail' => [
            MacdHistSlopePosCondition::NAME, '15m', 'long', 'macd_hist_series', ['garbage', -1.0, 1.0],
        ];
        yield 'MACD rejects infinity in consumed tail' => [
            MacdHistSlopePosCondition::NAME, '15m', 'long', 'macd_hist_series', [-1.0, INF],
        ];
        yield 'MACD rejects NaN outside consumed tail' => [
            MacdHistSlopePosCondition::NAME, '15m', 'long', 'macd_hist_series', [NAN, -1.0, 1.0],
        ];
        yield 'EMA rejects numeric string in consumed tail' => [
            'ema200_slope_pos', '1h', 'long', 'ema_200_series', [100.0, '101.0'],
        ];
        yield 'EMA rejects infinity outside consumed tail' => [
            'ema200_slope_pos', '1h', 'long', 'ema_200_series', [INF, 100.0, 101.0],
        ];
        yield 'EMA rejects NaN in consumed tail' => [
            'ema200_slope_pos', '1h', 'long', 'ema_200_series', [100.0, NAN],
        ];
    }

    public function testGlobalIndicatorConditionAggregatesEveryAvailableTimeframeFailClosed(): void
    {
        $condition = new class implements ConditionInterface {
            public function getName(): string { return 'rsi_lt_70'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                $rsi = $context['rsi'] ?? null;

                return new ConditionResult($this->getName(), is_float($rsi) && $rsi < 70.0, is_float($rsi) ? $rsi : null, 70.0, [
                    'evaluated_timeframe' => $context['timeframe'] ?? null,
                ]);
            }
        };
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $context = new RuleEvaluationContext('config-hash', $now, [
            new RuleInputSnapshot('5m', 'indicator_snapshot', $now, $now->modify('+480 seconds'), ['rsi' => 65.0]),
            new RuleInputSnapshot('1h', 'indicator_snapshot', $now, $now->modify('+4500 seconds'), ['rsi' => 75.0]),
        ]);

        $result = $this->evaluator([$condition])->evaluate(
            new ConditionNode('rsi_lt_70', 'global', 'long', [], 'fixture:global'),
            $context,
        );

        self::assertFalse($result->passed);
        self::assertSame('condition_failed', $result->reasonCode);
        self::assertSame('all_available_timeframes', $result->trace['aggregation']);
        self::assertSame(['1h', '5m'], array_column($result->trace['children'], 'timeframe'));
        self::assertSame(['1h', '5m'], array_column(array_column($result->trace['children'], 'meta'), 'evaluated_timeframe'));
        self::assertSame([false, true], array_column($result->trace['children'], 'passed'));
        self::assertSame([4_500, 480], array_column($result->trace['children'], 'input_freshness_seconds'));
    }

    public function testGlobalIndicatorConditionRejectsWhenNoIndicatorSnapshotExists(): void
    {
        $result = $this->evaluator([])->evaluate(
            new ConditionNode('rsi_lt_70', 'global', 'long', [], 'fixture:global'),
            new RuleEvaluationContext('config-hash', new \DateTimeImmutable('2026-08-10T10:00:00+00:00'), []),
        );

        self::assertFalse($result->passed);
        self::assertSame('missing_timeframe_snapshot', $result->reasonCode);
    }

    /** @param list<ConditionInterface> $conditions */
    private function evaluator(array $conditions, string $catalogVersion = '1.0.0'): StrictRuleEvaluator
    {
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadVersion($catalogVersion, $root . '/config/trading/condition_catalog');

        return new StrictRuleEvaluator($catalog, new StrictConditionRegistry($conditions));
    }

    private function condition(string $name, bool $passed, ?float $value, ?float $threshold): ConditionInterface
    {
        return new class($name, $passed, $value, $threshold) implements ConditionInterface {
            public function __construct(private string $name, private bool $passed, private ?float $value, private ?float $threshold) {}
            public function getName(): string { return $this->name; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult { return new ConditionResult($this->name, $this->passed, $this->value, $this->threshold); }
        };
    }

    /** @param array<string, mixed> $values */
    private function context(array $values, bool $include = true, bool $stale = false, string $timeframe = '5m'): RuleEvaluationContext
    {
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $snapshots = $include ? [
            new RuleInputSnapshot(
                $timeframe,
                'indicator_snapshot',
                $stale ? $now->modify('-2 seconds') : $now,
                $stale ? $now->modify('-1 second') : $now->modify('+' . $this->freshnessSeconds($timeframe) . ' seconds'),
                $values,
            ),
        ] : [];

        return new RuleEvaluationContext('config-hash', $now, $snapshots);
    }

    private function freshnessSeconds(string $timeframe): int
    {
        return ['4h' => 18_000, '1h' => 4_500, '15m' => 1_200, '5m' => 480, '1m' => 180][$timeframe];
    }
}
