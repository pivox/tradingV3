<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1h', '4h', '15m','5m', '1m'], side: 'long', name: 'ema200_slope_pos')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'ema200_slope_pos')]

final class Ema200SlopePosCondition extends AbstractCondition
{
    public function getName(): string { return 'ema200_slope_pos'; }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $slope = $this->slope($context);
        if ($slope === null) {
            return $this->result($this->getName(), false, null, 0.0, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }
        $passed = $slope > 0.0;
        return $this->result($this->getName(), $passed, $slope, 0.0, $this->baseMeta($context, [
            'source' => 'EMA',
        ]));
    }

    /** @param array<string, mixed> $context */
    private function slope(array $context): ?float
    {
        if (array_key_exists('ema_200_series', $context)) {
            $series = $context['ema_200_series'];
            if (!is_array($series) || !array_is_list($series) || count($series) < 2) {
                return null;
            }
            $previous = $series[count($series) - 2];
            $current = $series[count($series) - 1];
            if ((!is_int($previous) && !is_float($previous)) || (!is_int($current) && !is_float($current))) {
                return null;
            }
            $slope = (float) $current - (float) $previous;

            return is_finite($slope) ? $slope : null;
        }

        $legacySlope = $context['ema_200_slope'] ?? null;

        return is_float($legacySlope) && is_finite($legacySlope) ? $legacySlope : null;
    }
}
