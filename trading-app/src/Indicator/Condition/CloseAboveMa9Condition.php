<?php

namespace App\Indicator\Condition;

final class CloseAboveMa9Condition extends AbstractCondition
{
    public function getName(): string { return 'close_above_ma_9'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ema9 = $context['ema'][9] ?? null;
        if (!is_float($close) || !is_float($ema9)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }
        $ratio = ($close / $ema9) - 1.0;
        $passed = $ratio >= 0.0;
        return $this->result($this->getName(), $passed, $ratio, 0.0, $this->baseMeta($context, [
            'ema9' => $ema9,
            'source' => 'EMA',
        ]));
    }
}



