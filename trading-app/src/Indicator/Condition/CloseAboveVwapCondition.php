<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: 'close_above_vwap')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'close_above_vwap')]

final class CloseAboveVwapCondition extends AbstractCondition
{
    public function getName(): string { return 'close_above_vwap'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $vwap  = $context['vwap'] ?? null;
        if (!is_float($close) || !is_float($vwap)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        $diffPct = $vwap != 0.0 ? ($close - $vwap) / $vwap : null;
        $passed = $close > $vwap;
        return $this->result($this->getName(), $passed, $diffPct, null, $this->baseMeta($context, [
            'close' => $close,
            'vwap' => $vwap,
            'source' => 'VWAP'
        ]));
    }
}
