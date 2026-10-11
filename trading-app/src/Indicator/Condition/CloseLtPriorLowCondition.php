<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The close is strictly below the lowest low of the ``lookback_bars`` closed bars before the current one.
 *
 * The series must be ordered oldest to newest and end with the current bar.
 *
 * Accepts integer and float inputs: canonical JSON normalizes integral floats to
 * integers, so a whole-number close must not read as missing data.
 */
#[AsIndicatorCondition(timeframes: ['4h'], side: 'short', name: 'close_lt_prior_low')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'close_lt_prior_low')]
final class CloseLtPriorLowCondition extends AbstractCondition
{
    public function getName(): string
    {
        return 'close_lt_prior_low';
    }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $series = $context['low_series'] ?? null;
        $bars = $context['lookback_bars'] ?? null;
        $seriesOrder = $context['series_order'] ?? null;
        if ($seriesOrder !== 'oldest_to_newest') {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'reason' => 'invalid_series_order',
                'series_order' => $seriesOrder,
                'required_series_order' => 'oldest_to_newest',
            ]));
        }
        if (!self::finite($close) || !\is_int($bars) || $bars < 1 || !\is_array($series) || !array_is_list($series)
            || \count($series) < $bars + 1) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        // The series ends with the current bar; the level comes from the bars before it.
        $prior = \array_slice($series, -($bars + 1), $bars);
        foreach ($prior as $value) {
            if (!self::finite($value)) {
                return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
            }
        }
        $close = (float) $close;
        $level = (float) min(array_map('floatval', $prior));

        return $this->result($this->getName(), $close < $level, $close - $level, $level, $this->baseMeta($context, [
            'close' => $close,
            'prior_low' => $level,
            'lookback_bars' => $bars,
        ]));
    }

    private static function finite(mixed $value): bool
    {
        return (\is_int($value) || \is_float($value)) && \is_finite((float) $value);
    }
}
