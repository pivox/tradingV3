<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1h', '4h'], side: 'short', name: 'close_minus_ema_200_lt')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'close_minus_ema_200_lt')]

final class CloseMinusEma200LtCondition extends AbstractCondition
{
    public function __construct(private float $defaultThreshold = 0.0, private float $eps = 1.0e-12) {}

    public function getName(): string { return 'close_minus_ema_200_lt'; }

    public function evaluate(array $context): ConditionResult
    {
        $close  = $context['close']     ?? null;
        $ema200 = $context['ema'][200]  ?? null;
        $thr    = isset($context['threshold']) && is_float($context['threshold'])
            ? $context['threshold']
            : $this->defaultThreshold;

        if (!is_float($close) || !is_float($ema200) || abs($ema200) < $this->eps) {
            return $this->result($this->getName(), false, null, $thr, $this->baseMeta($context, ['missing_data' => true]));
        }

        $ratio  = ($close / $ema200) - 1.0; // négatif si close < ema200
        $passed = $ratio < $thr;

        return $this->result($this->getName(), $passed, $ratio, $thr, $this->baseMeta($context, [
            'close'  => $close,
            'ema200' => $ema200,
            'ratio'  => $ratio,
            'source' => 'EMA',
        ]));
    }
}
