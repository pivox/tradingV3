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
        self::assertTrue($evaluator->evaluate('crash_context_ok', [])->passed);

        $failing = $passingChildren;
        $failing[2] = $this->condition('macd_hist_decreasing_n', false);
        self::assertFalse((new StrictCompiledExpressionEvaluator(new StrictConditionRegistry($failing)))->evaluate('crash_context_ok', [])->passed);
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
        self::assertTrue($crash->evaluate('crash_short_pattern_1m', ['rsi' => 9.999, 'rsi_extreme_max' => 10.0])->passed);
        self::assertFalse($crash->evaluate('crash_short_pattern_1m', ['rsi' => 10.0, 'rsi_extreme_max' => 10.0])->passed);
        self::assertFalse($crash->evaluate('crash_short_pattern_1m', ['rsi' => 10.001, 'rsi_extreme_max' => 10.0])->passed);
        self::assertTrue($crash->evaluate('crash_short_entry_1m', ['rsi' => 9.999, 'rsi_extreme_max' => 10.0])->passed);
        self::assertFalse($crash->evaluate('crash_short_entry_1m', ['rsi' => 10.0, 'rsi_extreme_max' => 10.0])->passed);
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
}
