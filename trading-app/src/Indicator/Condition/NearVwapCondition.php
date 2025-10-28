<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: null, name: 'near_vwap')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'near_vwap')]

final class NearVwapCondition extends AbstractCondition
{
    public function __construct(private float $defaultTolerance = 0.0015) {}

    public function getName(): string { return 'near_vwap'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $vwap = $context['vwap'] ?? null;
        if (!is_float($close) || !is_float($vwap) || $vwap == 0.0) {
            return $this->result($this->getName(), false, null, $this->defaultTolerance, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'VWAP',
            ]));
        }
        $tol = $context['near_vwap_tolerance'] ?? $this->defaultTolerance;
        if (!is_float($tol)) $tol = (float) $tol;
        $ratio = abs(($close / $vwap) - 1.0);
        $passed = $ratio <= $tol;
        return $this->result($this->getName(), $passed, $ratio, $tol, $this->baseMeta($context, [
            'vwap' => $vwap,
            'tolerance' => $tol,
        ]));
    }
}


