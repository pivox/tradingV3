<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

use App\Indicator\Condition\ConditionResult;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogException;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;

final readonly class StrictCompiledExpressionEvaluator
{
    private ConditionCatalog $catalog;

    private const IDS = [
        'adx_min_for_trend_1h',
        'close_above_vwap_and_ma9',
        'close_above_vwap_or_ma9',
        'close_above_vwap_or_ma9_relaxed',
        'close_below_vwap_or_ma9',
        'crash_context_ok',
        'crash_short_entry_1m',
        'crash_short_pattern_15m',
        'crash_short_pattern_1m',
        'crash_short_pattern_5m',
        'ema20_over_50_with_tolerance',
        'ema20_over_50_with_tolerance_moderate',
        'ema_above_200_with_tolerance',
        'ema_below_200_with_tolerance',
        'price_regime_ok_long',
        'price_regime_ok_short',
        'pullback_confirmed',
        'pullback_confirmed_ma9_21',
        'pullback_confirmed_vwap',
        'rsi_5m_gt_floor',
    ];

    private const REFERENCED_CONDITION_IDS = [
        'adx_min_for_trend', 'atr_rel_in_range_15m', 'atr_rel_in_range_5m',
        'close_above_ema_200', 'close_above_ma_9', 'close_above_vwap',
        'close_above_vwap_or_ma9', 'close_below_ema_200', 'close_below_ma_9', 'close_below_vwap',
        'crash_short_pattern_1m', 'ema200_slope_neg', 'ema200_slope_pos', 'ema_20_gt_50',
        'ema_20_lt_50', 'ema_20_slope_pos', 'ema_50_gt_200', 'ema_50_lt_200',
        'ema_above_200_with_tolerance', 'ema_below_200_with_tolerance', 'ma9_cross_up_ma21',
        'macd_hist_decreasing_n', 'near_vwap', 'price_regime_ok_short', 'rsi_5m_gt_floor', 'volume_ratio_ok',
    ];

    public function __construct(private StrictConditionRegistry $registry, ?ConditionCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 4) . '/config/trading/condition_catalog/1.0.0.yaml',
        );
    }

    /** @return list<string> */
    public static function supportedIds(): array
    {
        return self::IDS;
    }

    /** @return list<string> */
    public static function referencedConditionIds(): array
    {
        return self::REFERENCED_CONDITION_IDS;
    }

    /** @param array<string, mixed> $context */
    public function evaluate(string $conditionId, array $context): ConditionResult
    {
        if (!in_array($conditionId, self::IDS, true)) {
            return $this->result($conditionId, false, null, null, ['unknown_expression' => true]);
        }

        return match ($conditionId) {
            'adx_min_for_trend_1h' => $this->numberComparison($conditionId, $context['adx'][14] ?? $context['adx_1h'] ?? $context['adx'] ?? null, (float) ($context['threshold'] ?? 20.0), '>='),
            'rsi_5m_gt_floor' => $this->numberComparison($conditionId, $context['rsi'] ?? null, (float) ($context['gt'] ?? 20.0), '>'),
            'close_above_vwap_and_ma9' => $this->allChildren($conditionId, ['close_above_vwap', 'close_above_ma_9'], $context),
            'close_above_vwap_or_ma9' => $this->anyChildren($conditionId, ['close_above_vwap', 'close_above_ma_9'], $context),
            'close_below_vwap_or_ma9' => $this->anyChildren($conditionId, ['close_below_vwap', 'close_below_ma_9'], $context),
            'close_above_vwap_or_ma9_relaxed' => $this->relaxedCloseAbove($context),
            'ema20_over_50_with_tolerance', 'ema20_over_50_with_tolerance_moderate' => $this->ema20Tolerance($conditionId, $context),
            'ema_above_200_with_tolerance' => $this->ema200Tolerance($conditionId, $context, true),
            'ema_below_200_with_tolerance' => $this->ema200Tolerance($conditionId, $context, false),
            'price_regime_ok_long' => $this->priceRegime($conditionId, $context, true),
            'price_regime_ok_short' => $this->priceRegime($conditionId, $context, false),
            'pullback_confirmed' => $this->pullbackConfirmed($context),
            'pullback_confirmed_ma9_21' => $this->allChildren($conditionId, ['ma9_cross_up_ma21'], $context),
            'pullback_confirmed_vwap' => $this->allChildren($conditionId, ['near_vwap'], $context),
            'crash_context_ok' => $this->allChildren($conditionId, ['price_regime_ok_short', 'ema200_slope_neg', 'macd_hist_decreasing_n', 'adx_min_for_trend'], $context),
            'crash_short_pattern_15m' => $this->allChildren($conditionId, ['ema_20_lt_50', 'close_below_vwap', 'macd_hist_decreasing_n', 'atr_rel_in_range_15m'], $context),
            'crash_short_pattern_5m' => $this->allChildren($conditionId, ['ema_20_lt_50', 'close_below_vwap', 'macd_hist_decreasing_n', 'atr_rel_in_range_5m', 'volume_ratio_ok', 'rsi_5m_gt_floor'], $context),
            'crash_short_pattern_1m' => $this->crashPattern1m($context),
            'crash_short_entry_1m' => $this->crashEntry1m($context),
        };
    }

    /** @param array<string, mixed> $context */
    private function relaxedCloseAbove(array $context): ConditionResult
    {
        $strict = $this->child('close_above_vwap_or_ma9', $context);
        $atr = $this->child('atr_rel_in_range_5m', $context);
        $near = $this->child('near_vwap', $context);
        $passed = $strict->passed || ($atr->passed && $near->passed);

        return $this->result('close_above_vwap_or_ma9_relaxed', $passed, null, null, [
            'children' => [$strict->toArray(), $atr->toArray(), $near->toArray()],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function ema20Tolerance(string $name, array $context): ConditionResult
    {
        $tolerance = (float) ($context['tolerance_ratio'] ?? 0.0);
        $ema20 = $this->number($context['ema'][20] ?? null);
        $ema50 = $this->number($context['ema'][50] ?? null);
        $ratio = $ema20 !== null && $ema50 !== null && $ema50 !== 0.0 ? ($ema20 / $ema50) - 1.0 : null;
        $ratioResult = $this->numberComparison($name . '.ratio', $ratio, -$tolerance, '>');
        $direct = $this->child('ema_20_gt_50', $context);
        $slope = $this->child('ema_20_slope_pos', $context);

        return $this->result($name, $direct->passed || $ratioResult->passed || $slope->passed, $ratio, -$tolerance, [
            'operator' => 'any_of',
            'children' => [$direct->toArray(), $ratioResult->toArray(), $slope->toArray()],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function ema200Tolerance(string $name, array $context, bool $above): ConditionResult
    {
        $tolerance = (float) ($context['tolerance_ratio'] ?? 0.0);
        $close = $this->number($context['close'] ?? null);
        $ema200 = $this->number($context['ema'][200] ?? null);
        $ratio = $close !== null && $ema200 !== null && $ema200 !== 0.0 ? ($close / $ema200) - 1.0 : null;
        $ratioResult = $this->numberComparison($name . '.ratio', $ratio, -$tolerance, $above ? '>' : '<');
        $direct = $this->child($above ? 'close_above_ema_200' : 'close_below_ema_200', $context);
        $slope = $this->child($above ? 'ema200_slope_pos' : 'ema200_slope_neg', $context);

        return $this->result($name, $direct->passed || $ratioResult->passed || $slope->passed, $ratio, -$tolerance, [
            'operator' => 'any_of',
            'children' => [$direct->toArray(), $ratioResult->toArray(), $slope->toArray()],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function priceRegime(string $name, array $context, bool $long): ConditionResult
    {
        $tolerance = $long ? 'ema_above_200_with_tolerance' : 'ema_below_200_with_tolerance';
        $emaRelation = $long ? 'ema_50_gt_200' : 'ema_50_lt_200';
        $closeRelation = $long ? 'close_above_ema_200' : 'close_below_ema_200';
        $slope = $long ? 'ema200_slope_pos' : 'ema200_slope_neg';
        $first = $this->child($tolerance, $context);
        $second = $this->child($emaRelation, $context);
        $third = $this->child($closeRelation, $context);
        $fourth = $this->child($slope, $context);
        $passed = ($first->passed && $second->passed) || ($third->passed && $fourth->passed);

        return $this->result($name, $passed, null, null, [
            'operator' => 'any_of',
            'branches' => [
                ['operator' => 'all_of', 'children' => [$first->toArray(), $second->toArray()]],
                ['operator' => 'all_of', 'children' => [$third->toArray(), $fourth->toArray()]],
            ],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function pullbackConfirmed(array $context): ConditionResult
    {
        $confirmation = $this->anyChildren('pullback_confirmation', ['ma9_cross_up_ma21', 'near_vwap'], $context);
        $age = $this->number($context['pullback_age_bars'] ?? null);
        $validityBars = (float) ($context['validity_bars'] ?? 3);
        if ($age === null) {
            return $this->result('pullback_confirmed', false, null, $validityBars, [
                'missing_data' => true,
                'missing_field' => 'pullback_age_bars',
                'children' => [$confirmation->toArray()],
            ]);
        }
        $validity = $this->numberComparison('pullback_age_bars_lte', $age, $validityBars, '<=');

        return $this->result('pullback_confirmed', $confirmation->passed && $validity->passed, $age, $validityBars, [
            'operator' => 'all_of',
            'children' => [$confirmation->toArray(), $validity->toArray()],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function crashPattern1m(array $context): ConditionResult
    {
        $base = $this->allChildren('crash_short_pattern_1m', ['macd_hist_decreasing_n', 'close_below_vwap', 'atr_rel_in_range_5m', 'volume_ratio_ok'], $context);
        $rsi = $this->numberComparison('rsi_1m_lt_extreme', $context['rsi'] ?? null, (float) ($context['rsi_extreme_max'] ?? 10.0), '<');

        return $this->result('crash_short_pattern_1m', $base->passed && $rsi->passed, null, null, [
            'children' => [...($base->meta['children'] ?? []), $rsi->toArray()],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function crashEntry1m(array $context): ConditionResult
    {
        $pattern = $this->child('crash_short_pattern_1m', $context);
        $pullback = $this->anyChildren('crash_pullback_ready', ['ma9_cross_up_ma21', 'near_vwap'], $context);

        return $this->result('crash_short_entry_1m', $pattern->passed && $pullback->passed, null, null, [
            'children' => [$pattern->toArray(), $pullback->toArray()],
        ]);
    }

    /**
     * @param list<string> $children
     * @param array<string, mixed> $context
     */
    private function allChildren(string $name, array $children, array $context): ConditionResult
    {
        return $this->children($name, $children, $context, true);
    }

    /**
     * @param list<string> $children
     * @param array<string, mixed> $context
     */
    private function anyChildren(string $name, array $children, array $context): ConditionResult
    {
        return $this->children($name, $children, $context, false);
    }

    /**
     * @param list<string> $children
     * @param array<string, mixed> $context
     */
    private function children(string $name, array $children, array $context, bool $all): ConditionResult
    {
        $results = array_map(fn (string $child): ConditionResult => $this->child($child, $context), $children);
        $passes = array_map(static fn (ConditionResult $result): bool => $result->passed, $results);
        $passed = $all ? !in_array(false, $passes, true) : in_array(true, $passes, true);

        return $this->result($name, $passed, null, null, [
            'operator' => $all ? 'all_of' : 'any_of',
            'children' => array_map(static fn (ConditionResult $result): array => $result->toArray(), $results),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function child(string $conditionId, array $context): ConditionResult
    {
        try {
            $definition = $this->catalog->definition($conditionId);
        } catch (ConditionCatalogException) {
            return $this->result($conditionId, false, null, null, ['missing_catalog_definition' => true]);
        }
        if ($definition->status !== 'executable') {
            return $this->result($conditionId, false, null, null, ['blocked_catalog_definition' => true]);
        }
        if (isset($context['_input_source']) && $context['_input_source'] !== $definition->contextSource) {
            return $this->result($conditionId, false, null, null, ['incompatible_input_source' => true]);
        }
        if (isset($context['timeframe']) && !in_array($context['timeframe'], $definition->timeframes, true)) {
            return $this->result($conditionId, false, null, null, ['incompatible_timeframe' => true]);
        }
        if (isset($context['side']) && !in_array($context['side'], $definition->sides, true)) {
            return $this->result($conditionId, false, null, null, ['incompatible_side' => true]);
        }
        foreach ($definition->parameters as $name => $parameter) {
            if (!array_key_exists($name, $context)) {
                if ($parameter->required) {
                    return $this->result($conditionId, false, null, null, ['missing_catalog_parameter' => $name]);
                }
                $context[$name] = $parameter->default;
            }
        }
        $authority = [
            'catalog_implementation' => $definition->implementation,
            'catalog_provenance' => $definition->provenance,
        ];
        if (in_array($conditionId, self::IDS, true)) {
            $result = $this->evaluate($conditionId, $context);

            return new ConditionResult($result->name, $result->passed, $result->value, $result->threshold, $authority + $result->meta);
        }
        $condition = $this->registry->get($conditionId);
        if ($condition === null) {
            return $this->result($conditionId, false, null, null, ['missing_implementation' => true]);
        }
        try {
            $result = $condition->evaluate($context);
        } catch (\Throwable $exception) {
            return $this->result($conditionId, false, null, null, ['condition_error' => $exception::class]);
        }
        if ($result->name !== $conditionId || ($result->value !== null && !is_finite($result->value))) {
            return $this->result($conditionId, false, null, null, ['invalid_result' => true]);
        }

        return new ConditionResult($result->name, $result->passed, $result->value, $result->threshold, $authority + $result->meta);
    }

    private function numberComparison(string $name, mixed $raw, float $threshold, string $operator): ConditionResult
    {
        $value = $this->number($raw);
        if ($value === null || !is_finite($threshold)) {
            return $this->result($name, false, null, $threshold, ['missing_data' => true]);
        }
        $passed = match ($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            default => false,
        };

        return $this->result($name, $passed, $value, $threshold);
    }

    private function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $meta */
    private function result(string $name, bool $passed, ?float $value, ?float $threshold, array $meta = []): ConditionResult
    {
        return new ConditionResult($name, $passed, $value, $threshold, $meta);
    }
}
