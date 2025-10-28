<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '1h', '1m', '4h', '5m'], side: 'short', name: 'close_below_ema_200')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'close_below_ema_200')]

final class CloseBelowEma200Condition extends AbstractCondition
{
    public function getName(): string { return 'close_below_ema_200'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ema200 = $context['ema'][200] ?? null;
        if (!is_float($close) || !is_float($ema200)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $close < $ema200;
        $dist = $ema200 != 0.0 ? ($close / $ema200) - 1.0 : null;
        return $this->result($this->getName(), $passed, $dist, null, $this->baseMeta($context, [
            'close' => $close,
            'ema200' => $ema200,
            'source' => 'EMA'
        ]));
    }
}
