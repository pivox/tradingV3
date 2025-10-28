<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: null, name: 'rsi_lt_85')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_lt_85')]

final class RsiLt85Condition extends AbstractCondition
{
    public function getName(): string { return 'rsi_lt_85'; }

    public function evaluate(array $context): ConditionResult
    {
        $threshold = (float)($context['rsi_lt_85_threshold'] ?? 85.0);
        $rsi = $context['rsi'] ?? null;
        if (!is_float($rsi)) {
            return $this->result($this->getName(), false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'RSI',
            ]));
        }
        $passed = $rsi < $threshold;
        return $this->result($this->getName(), $passed, $rsi, $threshold, $this->baseMeta($context, [
            'source' => 'RSI',
        ]));
    }
}
