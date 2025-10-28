<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'short', name: 'rsi_cross_down')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_cross_down')]

final class RsiCrossDownCondition extends AbstractCondition
{
    public function getName(): string { return 'rsi_cross_down'; }

    public function evaluate(array $context): ConditionResult
    {
        $level = (float)($context['rsi_cross_down_level'] ?? 70.0); // overbought -> sortie
        $rsi = $context['rsi'] ?? null;
        $prevRsi = $context['previous']['rsi'] ?? null;

        if (!is_float($rsi) || !is_float($prevRsi)) {
            return $this->result($this->getName(), false, null, $level, $this->baseMeta($context, ['missing_data' => true]));
        }
        $crossed = $prevRsi >= $level && $rsi < $level;
        $dist = $level != 0.0 ? ($level - $rsi) / $level : null; // distance relative sous le niveau
        return $this->result($this->getName(), $crossed, $dist, $level, $this->baseMeta($context, [
            'rsi' => $rsi,
            'prev_rsi' => $prevRsi,
            'level' => $level,
            'source' => 'RSI',
        ]));
    }
}
