<?php

namespace App\Indicator\Condition;

final class Ema20Gt50Condition extends AbstractCondition
{
    public function getName(): string { return 'ema_20_gt_50'; }

    public function evaluate(array $context): ConditionResult
    {
        $ema20 = $context['ema'][20] ?? null;
        $ema50 = $context['ema'][50] ?? null;
        if (!is_float($ema20) || !is_float($ema50)) {
            dd($context);
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $ema20 > $ema50;
        $ratio = $ema50 != 0.0 ? ($ema20 / $ema50) - 1.0 : null;
        return $this->result($this->getName(), $passed, $ratio, null, $this->baseMeta($context, [
            'ema20' => $ema20,
            'ema50' => $ema50,
            'source' => 'EMA'
        ]));
    }
}
