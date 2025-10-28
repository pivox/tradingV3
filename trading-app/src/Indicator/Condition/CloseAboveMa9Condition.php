<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: 'close_above_ma_9')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'close_above_ma_9')]

final class CloseAboveMa9Condition extends AbstractCondition
{
    public function getName(): string { return 'close_above_ma_9'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ema9 = $context['ema'][9] ?? null;
        if (!is_float($close) || !is_float($ema9)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }
        // Protéger contre la division par zéro si EMA9 == 0
        $ratio = abs($ema9) > 1.0e-12 ? (($close / $ema9) - 1.0) : ($close - $ema9);
        $passed = $close > $ema9;
        return $this->result($this->getName(), $passed, $ratio, 0.0, $this->baseMeta($context, [
            'ema9' => $ema9,
            'source' => 'EMA',
        ]));
    }
}
