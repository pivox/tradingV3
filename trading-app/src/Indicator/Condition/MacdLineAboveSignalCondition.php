<?php

namespace App\Indicator\Condition;

final class MacdLineAboveSignalCondition extends AbstractCondition
{
    public function getName(): string { return 'macd_line_above_signal'; }

    public function evaluate(array $context): ConditionResult
    {
        $macd = $context['macd']['macd'] ?? null;
        $signal = $context['macd']['signal'] ?? null;
        if (!is_float($macd) || !is_float($signal)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'MACD',
            ]));
        }
        $diff = $macd - $signal;
        $passed = $diff > 0.0;
        return $this->result($this->getName(), $passed, $diff, 0.0, $this->baseMeta($context, [
            'source' => 'MACD',
        ]));
    }
}



