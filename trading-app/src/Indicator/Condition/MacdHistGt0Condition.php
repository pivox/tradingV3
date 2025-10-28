<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: 'macd_hist_gt_0')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'macd_hist_gt_0')]

final class MacdHistGt0Condition extends AbstractCondition
{
    public function getName(): string { return 'macd_hist_gt_0'; }

    public function evaluate(array $context): ConditionResult
    {
        $hist = $context['macd']['hist'] ?? null;
        if (!is_float($hist)) {
            return $this->result($this->getName(), false, null, 0.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $hist > 0.0;
        return $this->result($this->getName(), $passed, $hist, 0.0, $this->baseMeta($context, ['source' => 'MACD']));
    }
}
