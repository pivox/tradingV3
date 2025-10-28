<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: null, name: 'rsi_gt_30')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_gt_30')]

final class RsiGt30Condition extends AbstractCondition
{
    public function getName(): string { return 'rsi_gt_30'; }

    public function evaluate(array $context): ConditionResult
    {
        $rsi = $context['rsi'] ?? null;
        if (!is_float($rsi)) {
            return $this->result($this->getName(), false, null, 30.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $rsi > 30.0;
        return $this->result($this->getName(), $passed, $rsi, 30.0, $this->baseMeta($context, ['source' => 'RSI']));
    }
}
