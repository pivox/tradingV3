<?php

namespace App\Indicator\Condition;

final class RsiGt20Condition extends AbstractCondition
{
    public function getName(): string { return 'rsi_gt_20'; }

    public function evaluate(array $context): ConditionResult
    {
        $threshold = (float)($context['rsi_gt_20_threshold'] ?? 20.0);
        $rsi = $context['rsi'] ?? null;
        if (!is_float($rsi)) {
            return $this->result($this->getName(), false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'RSI',
            ]));
        }
        $passed = $rsi > $threshold;
        return $this->result($this->getName(), $passed, $rsi, $threshold, $this->baseMeta($context, [
            'source' => 'RSI',
        ]));
    }
}
