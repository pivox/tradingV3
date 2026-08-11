<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Evaluation;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\Indicator\Condition\CloseAboveMa9Condition;
use App\Indicator\Condition\CloseAboveVwapCondition;
use App\Indicator\Condition\NearVwapCondition;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Evaluation\StrictCompiledExpressionEvaluator;
use App\TradingCore\Rules\Evaluation\StrictConditionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(StrictCompiledExpressionEvaluator::class)]
final class StrictCompiledExpressionEvaluatorTest extends TestCase
{
    public function testSupportsEveryCompiledExpressionDeclaredByCatalog(): void
    {
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.0.0.yaml');
        $declared = array_values(array_filter(
            $catalog->conditionIds(),
            static fn (string $id): bool => str_starts_with($catalog->definition($id)->implementation, 'compiled_expression:'),
        ));
        $supported = StrictCompiledExpressionEvaluator::supportedIds();
        sort($supported, SORT_STRING);

        self::assertSame($declared, $supported);
    }

    public function testThresholdBoundariesAndMissingValuesFailClosed(): void
    {
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry([]));

        self::assertFalse($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => 19.999, 'gt' => 20.0])->passed);
        self::assertFalse($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => 20.0, 'gt' => 20.0])->passed);
        self::assertTrue($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => 20.001, 'gt' => 20.0])->passed);
        self::assertFalse($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => INF])->passed);
        self::assertTrue($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => INF])->meta['missing_data']);
        self::assertFalse($evaluator->evaluate('adx_min_for_trend_1h', ['adx' => [14 => 19.999], 'threshold' => 20.0])->passed);
        self::assertTrue($evaluator->evaluate('adx_min_for_trend_1h', ['adx' => [14 => 20.0], 'threshold' => 20.0])->passed);
        self::assertTrue($evaluator->evaluate('adx_min_for_trend_1h', ['adx' => [14 => 20.001], 'threshold' => 20.0])->passed);
        self::assertFalse($evaluator->evaluate('adx_min_for_trend_1h', ['adx' => [14 => INF], 'threshold' => 20.0])->passed);
    }

    public function testCompositePriceAndCrashConditionsUseStrictChildTruth(): void
    {
        $passingChildren = [];
        foreach (['close_below_ema_200', 'ema200_slope_neg', 'macd_hist_decreasing_n', 'adx_min_for_trend'] as $id) {
            $passingChildren[] = $this->condition($id, true);
        }
        $passingChildren[] = new CloseAboveVwapCondition();
        $passingChildren[] = new CloseAboveMa9Condition();
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry($passingChildren));

        self::assertTrue($evaluator->evaluate('close_above_vwap_and_ma9', ['close' => 101.0, 'vwap' => 100.0, 'ema' => [9 => 100.5]])->passed);
        self::assertFalse($evaluator->evaluate('close_above_vwap_and_ma9', ['close' => 100.0, 'vwap' => 100.0, 'ema' => [9 => 99.0]])->passed);
        self::assertFalse($evaluator->evaluate('close_above_vwap_and_ma9', ['close' => 101.0, 'vwap' => 100.0])->passed);
        $crashContext = $this->seriesProof('1h', 'short', ema: true, macd: true);
        self::assertTrue($evaluator->evaluate('crash_context_ok', $crashContext)->passed);

        $failing = $passingChildren;
        $failing[2] = $this->condition('macd_hist_decreasing_n', false);
        self::assertFalse((new StrictCompiledExpressionEvaluator(new StrictConditionRegistry($failing)))->evaluate('crash_context_ok', $crashContext)->passed);
    }

    public function testSourcedEmaCompositeThresholdsUsePublishedOverrides(): void
    {
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry([
            $this->condition('ema_20_gt_50', false),
            $this->condition('ema_20_slope_pos', false),
            $this->condition('close_above_ema_200', false),
            $this->condition('ema200_slope_pos', false),
            $this->condition('ema_50_gt_200', true),
            $this->condition('close_below_ema_200', false),
            $this->condition('ema200_slope_neg', false),
            $this->condition('ema_50_lt_200', true),
        ]));

        self::assertFalse($evaluator->evaluate('ema20_over_50_with_tolerance', [
            'ema' => [20 => 99.8799, 50 => 100.0], 'tolerance_ratio' => 0.0012,
        ])->passed);
        self::assertFalse($evaluator->evaluate('ema20_over_50_with_tolerance', [
            'ema' => [20 => 99.88, 50 => 100.0], 'tolerance_ratio' => 0.0012,
        ])->passed);
        self::assertTrue($evaluator->evaluate('ema20_over_50_with_tolerance', [
            'ema' => [20 => 99.8801, 50 => 100.0], 'tolerance_ratio' => 0.0012,
        ])->passed);
        self::assertTrue($evaluator->evaluate('ema20_over_50_with_tolerance_moderate', [
            'ema' => [20 => 99.86, 50 => 100.0], 'tolerance_ratio' => 0.0015,
        ])->passed);

        self::assertFalse($evaluator->evaluate('ema_above_200_with_tolerance', [
            'close' => 99.6999, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.003,
        ])->passed);
        self::assertFalse($evaluator->evaluate('ema_above_200_with_tolerance', [
            'close' => 99.7, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.003,
        ])->passed);
        self::assertTrue($evaluator->evaluate('ema_above_200_with_tolerance', [
            'close' => 99.7001, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.003,
        ])->passed);
        self::assertTrue($evaluator->evaluate('price_regime_ok_long', [
            'close' => 99.7001, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.003,
        ])->passed);
        self::assertFalse($evaluator->evaluate('price_regime_ok_long', [
            'close' => 99.7, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.003,
        ])->passed);

        self::assertTrue($evaluator->evaluate('ema_below_200_with_tolerance', [
            'close' => 99.7499, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.0025,
        ])->passed);
        self::assertFalse($evaluator->evaluate('ema_below_200_with_tolerance', [
            'close' => 99.75, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.0025,
        ])->passed);
        self::assertFalse($evaluator->evaluate('ema_below_200_with_tolerance', [
            'close' => 99.7501, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.0025,
        ])->passed);
        self::assertTrue($evaluator->evaluate('price_regime_ok_short', [
            'close' => 99.7499, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.0025,
        ])->passed);
        self::assertFalse($evaluator->evaluate('price_regime_ok_short', [
            'close' => 99.75, 'ema' => [200 => 100.0], 'tolerance_ratio' => 0.0025,
        ])->passed);
    }

    public function testRelaxedVwapPullbackAndCrashBoundariesAreExplicit(): void
    {
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry([
            $this->condition('atr_rel_in_range_5m', true),
            new NearVwapCondition(new NullLogger()),
        ]));
        $exactDistance = abs((100.4 / 100.0) - 1.0);
        self::assertTrue($evaluator->evaluate('close_above_vwap_or_ma9_relaxed', [
            'close' => 100.4, 'vwap' => 100.0, 'near_vwap_tolerance' => $exactDistance,
        ])->passed);
        self::assertFalse($evaluator->evaluate('close_above_vwap_or_ma9_relaxed', [
            'close' => 100.4, 'vwap' => 100.0, 'near_vwap_tolerance' => $exactDistance - 0.000001,
        ])->passed);

        self::assertFalse($evaluator->evaluate('pullback_confirmed', [
            'close' => 105.0, 'vwap' => 100.0, 'validity_bars' => 3,
        ])->passed);
        self::assertTrue($evaluator->evaluate('pullback_confirmed', [
            'close' => 105.0, 'vwap' => 100.0, 'validity_bars' => 3, 'pullback_age_bars' => 3,
        ])->passed);
        foreach ([-1, 3.001, '2'] as $invalidAge) {
            $invalid = $evaluator->evaluate('pullback_confirmed', [
                'close' => 100.0, 'vwap' => 100.0, 'validity_bars' => 3, 'pullback_age_bars' => $invalidAge,
            ]);
            self::assertFalse($invalid->passed);
            self::assertTrue($invalid->meta['invalid_numeric']);
        }

        $crash = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry(array_map(
            fn (string $id): ConditionInterface => $this->condition($id, true),
            ['macd_hist_decreasing_n', 'close_below_vwap', 'atr_rel_in_range_5m', 'volume_ratio_ok', 'ma9_cross_up_ma21', 'near_vwap'],
        )));
        $crashContext = $this->seriesProof('1m', 'short', macd: true);
        self::assertTrue($crash->evaluate('crash_short_pattern_1m', $crashContext + ['rsi' => 9.999, 'rsi_extreme_max' => 10.0])->passed);
        self::assertFalse($crash->evaluate('crash_short_pattern_1m', $crashContext + ['rsi' => 10.0, 'rsi_extreme_max' => 10.0])->passed);
        self::assertFalse($crash->evaluate('crash_short_pattern_1m', $crashContext + ['rsi' => 10.001, 'rsi_extreme_max' => 10.0])->passed);
        self::assertTrue($crash->evaluate('crash_short_entry_1m', $crashContext + ['rsi' => 9.999, 'rsi_extreme_max' => 10.0])->passed);
        self::assertFalse($crash->evaluate('crash_short_entry_1m', $crashContext + ['rsi' => 10.0, 'rsi_extreme_max' => 10.0])->passed);
    }

    /** @param array<string, mixed> $seriesProof */
    #[DataProvider('nestedEmaSeriesProofProvider')]
    public function testPriceRegimeRejectsUnprovedNestedEmaSeries(
        string $expression,
        string $side,
        array $seriesProof,
        bool $expectedPassed,
    ): void {
        $long = $side === 'long';
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.1.0.yaml');
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry([
            $this->condition($long ? 'ema_50_gt_200' : 'ema_50_lt_200', false),
            $this->condition($long ? 'close_above_ema_200' : 'close_below_ema_200', true),
            $this->condition($long ? 'ema200_slope_pos' : 'ema200_slope_neg', true),
        ]), $catalog);

        $result = $evaluator->evaluate($expression, [
            '_input_source' => 'indicator_snapshot',
            'timeframe' => '1h',
            'side' => $side,
            'series_order' => 'oldest_to_newest',
            'kline_time' => '2026-08-10T10:00:00+00:00',
        ] + $seriesProof);

        self::assertSame($expectedPassed, $result->passed);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, bool}> */
    public static function nestedEmaSeriesProofProvider(): iterable
    {
        $start = 1_786_352_400;
        foreach ([
            'long' => ['price_regime_ok_long', [100.0, 101.0]],
            'short' => ['price_regime_ok_short', [101.0, 100.0]],
        ] as $side => [$expression, $series]) {
            yield $side . ' forged order label only' => [$expression, $side, ['ema_200_slope' => $side === 'long' ? 1.0 : -1.0], false];
            yield $side . ' duplicate timestamps' => [$expression, $side, [
                'ema_200_series' => $series,
                'ema_200_series_timestamps' => [$start, $start],
            ], false];
            yield $side . ' timestamp gap' => [$expression, $side, [
                'ema_200_series' => $series,
                'ema_200_series_timestamps' => [$start, $start + 7200],
            ], false];
            yield $side . ' reverse timestamps' => [$expression, $side, [
                'ema_200_series' => $series,
                'ema_200_series_timestamps' => [$start + 3600, $start],
            ], false];
            yield $side . ' stale evenly spaced timestamps' => [$expression, $side, [
                'ema_200_series' => $series,
                'ema_200_series_timestamps' => [$start - 3600, $start],
            ], false];
            yield $side . ' future evenly spaced timestamps' => [$expression, $side, [
                'ema_200_series' => $series,
                'ema_200_series_timestamps' => [$start + 3600, $start + 7200],
            ], false];
            yield $side . ' canonical proof' => [$expression, $side, [
                'ema_200_series' => $series,
                'ema_200_series_timestamps' => [$start, $start + 3600],
            ], true];
        }
        yield 'long rejects string outside consumed tail' => ['price_regime_ok_long', 'long', [
            'ema_200_series' => ['garbage', 100.0, 101.0],
            'ema_200_series_timestamps' => [$start, $start + 3600, $start + 7200],
        ], false];
        yield 'long rejects infinity in consumed tail' => ['price_regime_ok_long', 'long', [
            'ema_200_series' => [100.0, INF],
            'ema_200_series_timestamps' => [$start, $start + 3600],
        ], false];
        yield 'long rejects NaN outside consumed tail' => ['price_regime_ok_long', 'long', [
            'ema_200_series' => [NAN, 100.0, 101.0],
            'ema_200_series_timestamps' => [$start, $start + 3600, $start + 7200],
        ], false];
    }

    /** @param array<string, mixed> $seriesProof */
    #[DataProvider('nestedCrashSeriesProofProvider')]
    public function testCrashPatternRejectsUnprovedNestedMacdSeries(array $seriesProof, bool $expectedPassed): void
    {
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.1.0.yaml');
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry(array_map(
            fn (string $id): ConditionInterface => $this->condition($id, true),
            ['ema_20_lt_50', 'close_below_vwap', 'macd_hist_decreasing_n', 'atr_rel_in_range_15m'],
        )), $catalog);

        $result = $evaluator->evaluate('crash_short_pattern_15m', [
            '_input_source' => 'indicator_snapshot',
            'timeframe' => '15m',
            'side' => 'short',
            'series_order' => 'oldest_to_newest',
            'kline_time' => '2026-08-10T10:00:00+00:00',
        ] + $seriesProof);

        self::assertSame($expectedPassed, $result->passed);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function nestedCrashSeriesProofProvider(): iterable
    {
        $start = 1_786_355_100;

        yield 'forged order label only' => [[], false];
        yield 'missing timestamps' => [['macd_hist_series' => [0.2, 0.1]], false];
        yield 'duplicate timestamps' => [[
            'macd_hist_series' => [0.2, 0.1],
            'macd_hist_series_timestamps' => [$start, $start],
        ], false];
        yield 'stale evenly spaced timestamps' => [[
            'macd_hist_series' => [0.2, 0.1],
            'macd_hist_series_timestamps' => [$start - 900, $start],
        ], false];
        yield 'future evenly spaced timestamps' => [[
            'macd_hist_series' => [0.2, 0.1],
            'macd_hist_series_timestamps' => [$start + 900, $start + 1800],
        ], false];
        yield 'string outside consumed tail' => [[
            'macd_hist_series' => ['garbage', 0.2, 0.1],
            'macd_hist_series_timestamps' => [$start, $start + 900, $start + 1800],
        ], false];
        yield 'infinity in consumed tail' => [[
            'macd_hist_series' => [0.2, INF],
            'macd_hist_series_timestamps' => [$start, $start + 900],
        ], false];
        yield 'NaN outside consumed tail' => [[
            'macd_hist_series' => [NAN, 0.2, 0.1],
            'macd_hist_series_timestamps' => [$start, $start + 900, $start + 1800],
        ], false];
        yield 'canonical proof' => [[
            'macd_hist_series' => [0.2, 0.1],
            'macd_hist_series_timestamps' => [$start, $start + 900],
        ], true];
    }

    private function condition(string $name, bool $passed): ConditionInterface
    {
        return new class($name, $passed) implements ConditionInterface {
            public function __construct(private string $name, private bool $passed) {}
            public function getName(): string { return $this->name; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult { return new ConditionResult($this->name, $this->passed); }
        };
    }

    /** @return array<string, mixed> */
    private function seriesProof(string $timeframe, string $side, bool $ema = false, bool $macd = false): array
    {
        $step = match ($timeframe) {
            '1m' => 60,
            '15m' => 900,
            '1h' => 3600,
            default => throw new \InvalidArgumentException('Unsupported test timeframe.'),
        };
        $end = 1_786_356_000;
        $start = $end - $step;
        $proof = [
            '_input_source' => 'indicator_snapshot',
            'timeframe' => $timeframe,
            'side' => $side,
            'series_order' => 'oldest_to_newest',
            'kline_time' => '2026-08-10T10:00:00+00:00',
        ];
        if ($ema) {
            $proof['ema_200_series'] = [101.0, 100.0];
            $proof['ema_200_series_timestamps'] = [$start, $start + $step];
        }
        if ($macd) {
            $proof['macd_hist_series'] = [0.2, 0.1];
            $proof['macd_hist_series_timestamps'] = [$start, $start + $step];
        }

        return $proof;
    }
}
