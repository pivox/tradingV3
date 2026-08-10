<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

use App\Indicator\Condition\ConditionResult;

final readonly class StrictCompiledExpressionEvaluator
{
    private const IDS = [
        'adx_min_for_trend_1h',
        'close_above_vwap_and_ma9',
        'close_above_vwap_or_ma9_relaxed',
        'close_below_vwap_or_ma9',
        'crash_context_ok',
        'crash_short_entry_1m',
        'crash_short_pattern_15m',
        'crash_short_pattern_1m',
        'crash_short_pattern_5m',
        'pullback_confirmed',
        'rsi_5m_gt_floor',
    ];

    public function __construct(private StrictConditionRegistry $registry)
    {
    }

    /** @return list<string> */
    public static function supportedIds(): array
    {
        return self::IDS;
    }

    /** @param array<string, mixed> $context */
    public function evaluate(string $conditionId, array $context): ConditionResult
    {
        if (!in_array($conditionId, self::IDS, true)) {
            return $this->result($conditionId, false, null, null, ['unknown_expression' => true]);
        }

        return match ($conditionId) {
            'adx_min_for_trend_1h' => $this->numberComparison($conditionId, $context['adx_1h'] ?? $context['adx'] ?? null, 20.0, '>='),
            'rsi_5m_gt_floor' => $this->numberComparison($conditionId, $context['rsi'] ?? null, (float) ($context['gt'] ?? 20.0), '>'),
            'close_above_vwap_and_ma9' => $this->pricePair($conditionId, $context, true, false),
            'close_below_vwap_or_ma9' => $this->pricePair($conditionId, $context, false, true),
            'close_above_vwap_or_ma9_relaxed' => $this->relaxedCloseAbove($context),
            'pullback_confirmed' => $this->anyChildren($conditionId, ['ma9_cross_up_ma21', 'near_vwap'], $context),
            'crash_context_ok' => $this->allChildren($conditionId, ['price_regime_ok_short', 'ema200_slope_neg', 'macd_hist_decreasing_n', 'adx_min_for_trend'], $context),
            'crash_short_pattern_15m' => $this->allChildren($conditionId, ['ema_20_lt_50', 'close_below_vwap', 'macd_hist_decreasing_n', 'atr_rel_in_range_15m'], $context),
            'crash_short_pattern_5m' => $this->allChildren($conditionId, ['ema_20_lt_50', 'close_below_vwap', 'macd_hist_decreasing_n', 'atr_rel_in_range_5m', 'volume_ratio_ok', 'rsi_5m_gt_floor'], $context),
            'crash_short_pattern_1m' => $this->crashPattern1m($context),
            'crash_short_entry_1m' => $this->crashEntry1m($context),
        };
    }

    /** @param array<string, mixed> $context */
    private function pricePair(string $name, array $context, bool $above, bool $any): ConditionResult
    {
        $close = $this->number($context['close'] ?? null);
        $vwap = $this->number($context['vwap'] ?? null);
        $ma9 = $this->number($context['ma_9'] ?? ($context['ema'][9] ?? null));
        if ($close === null || $vwap === null || $ma9 === null) {
            return $this->result($name, false, null, null, ['missing_data' => true]);
        }
        $vwapPassed = $above ? $close > $vwap : $close < $vwap;
        $maPassed = $above ? $close > $ma9 : $close < $ma9;
        $passed = $any ? ($vwapPassed || $maPassed) : ($vwapPassed && $maPassed);

        return $this->result($name, $passed, $close, null, ['vwap' => $vwap, 'ma_9' => $ma9]);
    }

    /** @param array<string, mixed> $context */
    private function relaxedCloseAbove(array $context): ConditionResult
    {
        $strict = $this->child('close_above_vwap_or_ma9', $context);
        $atr = $this->child('atr_rel_in_range_5m', $context);
        $close = $this->number($context['close'] ?? null);
        $vwap = $this->number($context['vwap'] ?? null);
        $near = $close !== null && $vwap !== null && $vwap > 0.0 && abs(($close / $vwap) - 1.0) <= 0.004;
        $passed = $strict->passed || ($atr->passed && $near);

        return $this->result('close_above_vwap_or_ma9_relaxed', $passed, $close, null, [
            'children' => [$strict->toArray(), $atr->toArray()],
            'near_vwap_0_004' => $near,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function crashPattern1m(array $context): ConditionResult
    {
        $base = $this->allChildren('crash_short_pattern_1m', ['macd_hist_decreasing_n', 'close_below_vwap', 'atr_rel_in_range_5m', 'volume_ratio_ok'], $context);
        $rsi = $this->numberComparison('rsi_1m_lt_extreme', $context['rsi'] ?? null, 10.0, '<');

        return $this->result('crash_short_pattern_1m', $base->passed && $rsi->passed, null, null, [
            'children' => [...($base->meta['children'] ?? []), $rsi->toArray()],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function crashEntry1m(array $context): ConditionResult
    {
        $pattern = $this->crashPattern1m($context);
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
        if (in_array($conditionId, self::IDS, true)) {
            return $this->evaluate($conditionId, $context);
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

        return $result;
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
