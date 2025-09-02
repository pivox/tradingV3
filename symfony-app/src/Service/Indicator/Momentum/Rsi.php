<?php
// src/Service/Indicator/Momentum/Rsi.php

namespace App\Service\Indicator\Momentum;

/**
 * RSI (Relative Strength Index) — J. Welles Wilder (1978)
 * - Calcule un RSI lissé (Wilder) avec initialisation par moyennes simples de gains/pertes.
 * - Retourne une valeur (calculate) ou une série (calculateFull).
 */
final class Rsi
{
    /**
     * Retourne la dernière valeur du RSI.
     * @param float[] $closes
     */
    public function calculate(array $closes, int $period = 14): float
    {
        $out = $this->calculateFull($closes, $period);
        return empty($out['rsi']) ? 0.0 : (float) end($out['rsi']);
    }

    /**
     * Retourne la série RSI complète.
     * @param float[] $closes
     * @return array{rsi: float[]}
     */
    public function calculateFull(array $closes, int $period = 14): array
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return ['rsi' => []];
        }

        $rsi = [];
        $gains = 0.0; $losses = 0.0;

        // 1) moyenne initiale des gains/pertes sur les "period" premières variations
        for ($i = 1; $i <= $period; $i++) {
            $chg = $closes[$i] - $closes[$i - 1];
            if ($chg >= 0) $gains += $chg; else $losses -= $chg;
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        // 2) premier RSI disponible à l'indice $period
        $rs  = ($avgLoss == 0.0) ? INF : $avgGain / $avgLoss;
        $rsi[$period] = 100.0 - (100.0 / (1.0 + $rs));

        // 3) lissage de Wilder pour le reste
        for ($i = $period + 1; $i < $n; $i++) {
            $chg = $closes[$i] - $closes[$i - 1];
            $gain = $chg > 0 ? $chg : 0.0;
            $loss = $chg < 0 ? -$chg : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;

            $rs  = ($avgLoss == 0.0) ? INF : $avgGain / $avgLoss;
            $rsi[$i] = 100.0 - (100.0 / (1.0 + $rs));
        }

        // Remettre la série alignée dès 0 (facultatif)
        $rsi = array_values($rsi);

        return ['rsi' => $rsi];
    }
}
