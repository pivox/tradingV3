<?php

namespace App\Indicator\Condition;

final class Ema50Gt200Condition extends AbstractCondition
{
    public function getName(): string { return 'ema_50_gt_200'; }

    public function evaluate(array $context): ConditionResult
    {
        $ema50 = $context['ema'][50] ?? null;
        $ema200 = $context['ema'][200] ?? null;
        if (!is_float($ema50) || !is_float($ema200)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $ema50 > $ema200;
        $ratio = $ema200 != 0.0 ? ($ema50 / $ema200) - 1.0 : null;
        return $this->result($this->getName(), $passed, $ratio, null, $this->baseMeta($context, [
            'ema50' => $ema50,
            'ema200' => $ema200,
            'source' => 'EMA'
        ]));
    }
}
