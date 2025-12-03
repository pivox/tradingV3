<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m'], side: null, name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class AtrRelInRangeMicroCondition extends AbstractCondition
{
    private const NAME = 'atr_rel_in_range_micro';
    private const MIN = 0.0005;
    private const MAX = 0.045;

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $atr = $context['atr'] ?? null;
        $price = $context['close'] ?? null;

        if (!is_float($atr) || !is_float($price) || $price <= 0.0) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'ATR',
            ]));
        }

        $ratio = $atr / $price;
        $passed = $ratio >= self::MIN && $ratio <= self::MAX;

        return $this->result(self::NAME, $passed, $ratio, null, $this->baseMeta($context, [
            'atr' => $atr,
            'price' => $price,
            'min_pct' => self::MIN,
            'max_pct' => self::MAX,
            'source' => 'ATR',
        ]));
    }
}
