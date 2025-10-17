<?php

namespace App\Indicator\Condition;

final class AtrVolatilityOkCondition extends AbstractCondition
{
    public function getName(): string { return 'atr_volatility_ok'; }

    public function evaluate(array $context): ConditionResult
    {
        $atr   = $context['atr'] ?? null;
        $close = $context['close'] ?? null;
        $minPct = $context['min_atr_pct'] ?? 0.001;   // 0.1%
        $maxPct = $context['max_atr_pct'] ?? 0.03;    // 3%

        if ($minPct <= 0 || $maxPct <= 0 || $minPct >= $maxPct) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'invalid_thresholds' => true,
                'min_pct' => $minPct,
                'max_pct' => $maxPct,
            ]));
        }

        if ($atr === null || $close === null || $close <= 0.0) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }

        $ratio = $atr / $close; // ATR en % du prix (échelle 0-1)
        $passed = ($ratio >= $minPct) && ($ratio <= $maxPct);

        return $this->result($this->getName(), $passed, $ratio, null, $this->baseMeta($context, [
            'atr' => $atr,
            'price' => $close,
            'min_pct' => $minPct,
            'max_pct' => $maxPct,
            'source' => 'ATR',
        ]));
    }
}
