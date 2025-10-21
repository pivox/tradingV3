<?php

namespace App\Indicator\Condition;

final class Ema200SlopePosCondition extends AbstractCondition
{
    public function getName(): string { return 'ema200_slope_pos'; }

    public function evaluate(array $context): ConditionResult
    {
        $slope = $context['ema_200_slope'] ?? null;
        if (!is_float($slope)) {
            return $this->result($this->getName(), false, null, 0.0, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }
        $passed = $slope > 0.0;
        return $this->result($this->getName(), $passed, $slope, 0.0, $this->baseMeta($context, [
            'source' => 'EMA',
        ]));
    }
}



