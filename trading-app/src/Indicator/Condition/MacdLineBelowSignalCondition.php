<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'short', name: 'macd_line_below_signal')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'macd_line_below_signal')]

final class MacdLineBelowSignalCondition extends AbstractCondition
{
    public function getName(): string { return 'macd_line_below_signal'; }

    public function evaluate(array $context): ConditionResult
    {
        $macd = $context['macd']['macd'] ?? null;
        $signal = $context['macd']['signal'] ?? null;
        if (!is_float($macd) || !is_float($signal)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'MACD',
            ]));
        }
        $diff = $macd - $signal;
        $passed = $diff < 0.0;
        return $this->result($this->getName(), $passed, $diff, 0.0, $this->baseMeta($context, [
            'source' => 'MACD',
        ]));
    }
}

