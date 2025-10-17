<?php

namespace App\Indicator\Condition;

final class CloseAboveVwapCondition extends AbstractCondition
{
    public function getName(): string { return 'close_above_vwap'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $vwap  = $context['vwap'] ?? null;
        if (!is_float($close) || !is_float($vwap)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        $diffPct = $vwap != 0.0 ? ($close - $vwap) / $vwap : null;
        $passed = $close > $vwap;
        return $this->result($this->getName(), $passed, $diffPct, null, $this->baseMeta($context, [
            'close' => $close,
            'vwap' => $vwap,
            'source' => 'VWAP'
        ]));
    }
}
