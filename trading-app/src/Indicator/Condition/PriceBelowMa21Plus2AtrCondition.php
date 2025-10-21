<?php

namespace App\Indicator\Condition;

final class PriceBelowMa21Plus2AtrCondition extends AbstractCondition
{
    public function getName(): string { return 'price_below_ma21_plus_2atr'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ema21 = $context['ema'][21] ?? null;
        $atr = $context['atr'] ?? null;
        if (!is_float($close) || !is_float($ema21) || !is_float($atr)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA+ATR',
            ]));
        }
        $thresholdPrice = $ema21 + 2.0 * $atr;
        $passed = $close < $thresholdPrice;
        $value = $close - $thresholdPrice;
        return $this->result($this->getName(), $passed, $value, 0.0, $this->baseMeta($context, [
            'ema21' => $ema21,
            'atr' => $atr,
            'threshold_price' => $thresholdPrice,
        ]));
    }
}



