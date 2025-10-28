<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'short', name: 'macd_signal_cross_down')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'macd_signal_cross_down')]

final class MacdSignalCrossDownCondition extends AbstractCondition
{
    public function getName(): string { return 'macd_signal_cross_down'; }

    public function evaluate(array $context): ConditionResult
    {
        $macd = $context['macd']['macd'] ?? null;
        $signal = $context['macd']['signal'] ?? null;
        $prevMacd = $context['previous']['macd']['macd'] ?? null;
        $prevSignal = $context['previous']['macd']['signal'] ?? null;

        if (!is_float($macd) || !is_float($signal) || !is_float($prevMacd) || !is_float($prevSignal)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }

        $crossed = $prevMacd >= $prevSignal && $macd < $signal;
        $diff = $macd - $signal;

        return $this->result($this->getName(), $crossed, $diff, null, $this->baseMeta($context, [
            'macd' => $macd,
            'signal' => $signal,
            'prev_macd' => $prevMacd,
            'prev_signal' => $prevSignal,
            'source' => 'MACD'
        ]));
    }
}
