<?php

namespace App\Indicator\Condition;

final class MacdSignalCrossUpCondition extends AbstractCondition
{
    public function getName(): string { return 'macd_signal_cross_up'; }

    public function evaluate(array $context): ConditionResult
    {
        $macd = $context['macd']['macd'] ?? null;
        $signal = $context['macd']['signal'] ?? null;
        $prevMacd = $context['previous']['macd']['macd'] ?? null;
        $prevSignal = $context['previous']['macd']['signal'] ?? null;

        if (!is_float($macd) || !is_float($signal) || !is_float($prevMacd) || !is_float($prevSignal)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }

        $crossed = $prevMacd <= $prevSignal && $macd > $signal;
        $diff = $macd - $signal; // valeur brute de l'écart

        return $this->result($this->getName(), $crossed, $diff, null, $this->baseMeta($context, [
            'macd' => $macd,
            'signal' => $signal,
            'prev_macd' => $prevMacd,
            'prev_signal' => $prevSignal,
            'source' => 'MACD'
        ]));
    }
}
