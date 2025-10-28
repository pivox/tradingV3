<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1h', '4h'], side: 'long', name: 'ema200_slope_pos')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'ema200_slope_pos')]

final class Ema200SlopePosCondition extends AbstractCondition
{
    public function getName(): string { return 'ema200_slope_pos'; }

    public function evaluate(array $context): ConditionResult
    {
        $slope = $context['ema_200_slope'] ?? null;
        if (!is_float($slope)) {
            return $this->result($this->getName(), false, null, 0.0, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }
        $passed = $slope > 0.0;
        return $this->result($this->getName(), $passed, $slope, 0.0, $this->baseMeta($context, [
            'source' => 'EMA',
        ]));
    }
}


