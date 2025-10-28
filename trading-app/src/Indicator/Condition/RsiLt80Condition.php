<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m'], side: 'long', name: 'rsi_lt_80')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_lt_80')]

final class RsiLt80Condition extends AbstractCondition
{
    public function getName(): string { return 'rsi_lt_80'; }

    public function evaluate(array $context): ConditionResult
    {
        $threshold = (float)($context['rsi_lt_80_threshold'] ?? 80.0);
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
