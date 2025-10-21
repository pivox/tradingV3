<?php

namespace App\Indicator\Condition;

final class AtrRelInRange5mCondition extends AbstractCondition
{
    private const NAME = 'atr_rel_in_range_5m';
    private const MIN = 0.0007;  // élargi
    private const MAX = 0.0200;  // élargi

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

