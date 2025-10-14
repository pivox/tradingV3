<?php
// src/Indicator/Momentum/Rsi.php

namespace App\Indicator\Momentum;

/**
 * RSI (Relative Strength Index) — J. Welles Wilder (1978)
 * - Calcule un RSI lissé (Wilder) avec initialisation par moyennes simples de gains/pertes.
 * - Retourne une valeur (calculate) ou une série (calculateFull).
 */
final class Rsi
{
    /**
     * Retourne la dernière valeur du RSI ou null si pas assez de données.
     * @param float[] $closes
     */
    public function calculate(array $closes, int $period = 14): ?float
    {
        $out = $this->calculateFull($closes, $period);
        if (empty($out['rsi'])) {
            return null; // insuffisant -> null pour permettre missing_data
        }
        return (float) end($out['rsi']);
    }

    /** Alias explicite pour compat éventuelle. */
    public function calculateNullable(array $closes, int $period = 14): ?float
    {
        return $this->calculate($closes, $period);
    }

    /**
     * Retourne la série RSI complète (vide si insuffisant).
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
        for ($i = 1; $i <= $period; $i++) {
            $chg = $closes[$i] - $closes[$i - 1];
            if ($chg >= 0) $gains += $chg; else $losses -= $chg;
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        $rs  = ($avgLoss == 0.0) ? INF : $avgGain / $avgLoss;
        $rsi[$period] = 100.0 - (100.0 / (1.0 + $rs));
        for ($i = $period + 1; $i < $n; $i++) {
            $chg = $closes[$i] - $closes[$i - 1];
            $gain = $chg > 0 ? $chg : 0.0;
            $loss = $chg < 0 ? -$chg : 0.0;
            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
            $rs  = ($avgLoss == 0.0) ? INF : $avgGain / $avgLoss;
            $rsi[$i] = 100.0 - (100.0 / (1.0 + $rs));
        }
        $rsi = array_values($rsi);
        return ['rsi' => $rsi];
    }
}
