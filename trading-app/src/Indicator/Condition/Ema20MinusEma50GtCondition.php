<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '5m'], side: 'long', name: 'ema_20_minus_ema_50_gt')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'ema_20_minus_ema_50_gt')]

final class Ema20MinusEma50GtCondition extends AbstractCondition
{
    public function __construct(private float $defaultThreshold = 0.0, private float $eps = 1.0e-12) {}

    public function getName(): string { return 'ema_20_minus_ema_50_gt'; }

    public function evaluate(array $context): ConditionResult
    {
        $ema20 = $context['ema'][20] ?? null;
        $ema50 = $context['ema'][50] ?? null;
        $thr   = isset($context['threshold']) && is_float($context['threshold'])
            ? $context['threshold']
            : $this->defaultThreshold;

        if (!is_float($ema20) || !is_float($ema50) || abs($ema50) < $this->eps) {
            return $this->result($this->getName(), false, null, $thr, $this->baseMeta($context, ['missing_data' => true]));
        }

        $ratio  = ($ema20 / $ema50) - 1.0;
        $passed = $ratio > $thr;

        return $this->result($this->getName(), $passed, $ratio, $thr, $this->baseMeta($context, [
            'ema20'  => $ema20,
            'ema50'  => $ema50,
            'ratio'  => $ratio,
            'source' => 'EMA',
        ]));
    }
}
