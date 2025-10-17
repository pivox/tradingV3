<?php

namespace App\Indicator\Condition;

final class RsiCrossUpCondition extends AbstractCondition
{
    public function getName(): string { return 'rsi_cross_up'; }

    public function evaluate(array $context): ConditionResult
    {
        $level = (float)($context['rsi_cross_up_level'] ?? 30.0); // oversold -> sortie
        $rsi = $context['rsi'] ?? null;
        $prevRsi = $context['previous']['rsi'] ?? null;

        if (!is_float($rsi) || !is_float($prevRsi)) {
            return $this->result($this->getName(), false, null, $level, $this->baseMeta($context, ['missing_data' => true]));
        }
        $crossed = $prevRsi <= $level && $rsi > $level;
        $dist = $level != 0.0 ? ($rsi - $level) / $level : null; // distance relative
        return $this->result($this->getName(), $crossed, $dist, $level, $this->baseMeta($context, [
            'rsi' => $rsi,
            'prev_rsi' => $prevRsi,
            'level' => $level,
            'source' => 'RSI',
        ]));
    }
}
