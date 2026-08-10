<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: null, name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]

final class AtrRelInRange15mCondition extends AbstractCondition
{
    private const NAME = 'atr_rel_in_range_15m';
    private const DEFAULT_MIN = 0.0005;
    private const DEFAULT_MAX = 0.045;

    public function getName(): string
    {
        return self::NAME;
    }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $atr = $context['atr'] ?? null;
        $price = $context['close'] ?? null;
        $min = $context['min_atr_pct'] ?? self::DEFAULT_MIN;
        $max = $context['max_atr_pct'] ?? self::DEFAULT_MAX;

        if (!is_float($atr) || !is_float($price) || $price <= 0.0
            || (!is_int($min) && !is_float($min)) || (!is_int($max) && !is_float($max))) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'ATR',
            ]));
        }
        $min = (float) $min;
        $max = (float) $max;
        if ($min <= 0.0 || $max <= $min) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, ['missing_data' => true, 'source' => 'ATR']));
        }

        $ratio = $atr / $price;
        $passed = $ratio >= $min && $ratio <= $max;

        return $this->result(self::NAME, $passed, $ratio, null, $this->baseMeta($context, [
            'atr' => $atr,
            'price' => $price,
            'min_pct' => $min,
            'max_pct' => $max,
            'source' => 'ATR',
        ]));
    }
}
