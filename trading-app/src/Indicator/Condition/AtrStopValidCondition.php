<?php

namespace App\Indicator\Condition;

final class AtrStopValidCondition extends AbstractCondition
{
    public function getName(): string { return 'atr_stop_valid'; }

    public function evaluate(array $context): ConditionResult
    {
        $atr   = $context['atr'] ?? null;
        $entry = $context['entry_price'] ?? null;
        $stop  = $context['stop_loss'] ?? null;
        $k     = $context['atr_k'] ?? ($context['k'] ?? 1.5); // fallback

        // Exclure cette condition du contexte de revalidation (pas de prix d'entrée ni de stop loss)
        if (!is_float($entry) || !is_float($stop)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, [
                'not_applicable' => true,
                'reason' => 'Cette condition nécessite un prix d\'entrée et un stop loss (contexte de trading en temps réel)',
                'context' => 'revalidation'
            ]));
        }

        if (!is_float($atr) || !is_float($k)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        if ($atr <= 0.0 || $entry <= 0.0 || $k <= 0.0) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['invalid_inputs' => true]));
        }
        $target = $k * $atr;
        $distance = abs($entry - $stop);
        $ratio = $target > 0 ? $distance / $target : null;
        $passed = $ratio !== null && $ratio >= 0.95 && $ratio <= 1.05; // tolérance ±5%
        return $this->result($this->getName(), $passed, $ratio, 1.0, $this->baseMeta($context, [
            'atr' => $atr,
            'k' => $k,
            'target_distance' => $target,
            'actual_distance' => $distance,
            'source' => 'ATR',
        ]));
    }
}
