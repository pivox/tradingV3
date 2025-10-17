<?php

namespace App\Indicator\Condition;

final class MacdHistGt0Condition extends AbstractCondition
{
    public function getName(): string { return 'macd_hist_gt_0'; }

    public function evaluate(array $context): ConditionResult
    {
        $hist = $context['macd']['hist'] ?? null;
        if (!is_float($hist)) {
            return $this->result($this->getName(), false, null, 0.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $hist > 0.0;
        return $this->result($this->getName(), $passed, $hist, 0.0, $this->baseMeta($context, ['source' => 'MACD']));
    }
}
