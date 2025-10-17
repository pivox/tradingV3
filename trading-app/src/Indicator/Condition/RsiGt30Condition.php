<?php

namespace App\Indicator\Condition;

final class RsiGt30Condition extends AbstractCondition
{
    public function getName(): string { return 'rsi_gt_30'; }

    public function evaluate(array $context): ConditionResult
    {
        $rsi = $context['rsi'] ?? null;
        if (!is_float($rsi)) {
            return $this->result($this->getName(), false, null, 30.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $rsi > 30.0;
        return $this->result($this->getName(), $passed, $rsi, 30.0, $this->baseMeta($context, ['source' => 'RSI']));
    }
}
