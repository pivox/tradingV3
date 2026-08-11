<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1h', '4h'], side: 'short', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class Ema200SlopeNegCondition extends AbstractCondition
{
    public const NAME = 'ema200_slope_neg';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Pente de l'EMA200 négative (momentum baissier de fond).";
    }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $slope = $this->slope($context);
        if ($slope === null) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $slope < 0.0;
        return $this->result(self::NAME, $passed, $slope, 0.0, $this->baseMeta($context));
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
