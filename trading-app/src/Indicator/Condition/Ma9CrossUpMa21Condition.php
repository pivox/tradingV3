<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: 'ma9_cross_up_ma21')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'ma9_cross_up_ma21')]

final class Ma9CrossUpMa21Condition extends AbstractCondition
{
    public function getName(): string { return 'ma9_cross_up_ma21'; }

    public function evaluate(array $context): ConditionResult
    {
        $ema = $context['ema'] ?? null;
        $emaPrev = $context['ema_prev'] ?? null;
        $ema9 = is_array($ema) ? ($ema[9] ?? null) : null;
        $ema21 = is_array($ema) ? ($ema[21] ?? null) : null;
        $ema9Prev = is_array($emaPrev) ? ($emaPrev[9] ?? null) : null;
        $ema21Prev = is_array($emaPrev) ? ($emaPrev[21] ?? null) : null;
        if (!is_float($ema9) || !is_float($ema21) || !is_float($ema9Prev) || !is_float($ema21Prev)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }
        $crossedUp = ($ema9Prev <= $ema21Prev) && ($ema9 > $ema21);
        $value = $ema9 - $ema21;
        return $this->result($this->getName(), $crossedUp, $value, 0.0, $this->baseMeta($context, [
            'source' => 'EMA',
        ]));
    }
}

