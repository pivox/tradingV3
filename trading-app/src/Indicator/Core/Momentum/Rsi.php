<?php
// src/Indicator/Momentum/Rsi.php

namespace App\Indicator\Core\Momentum;

use App\Indicator\Core\IndicatorInterface;

/**
 * RSI (Relative Strength Index) — J. Welles Wilder (1978)
 * - Calcule un RSI lissé (Wilder) avec initialisation par moyennes simples de gains/pertes.
 * - Retourne une valeur (calculate) ou une série (calculateFull).
 */
 
final class Rsi implements IndicatorInterface
{
    /**
     * Description textuelle de l'indicateur RSI.
     * - $detailed=false: résumé court
     * - $detailed=true: formule et étapes de calcul
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return 'RSI (Relative Strength Index, Wilder 1978) mesurant la force relative des hausses/baisses sur une période donnée.';
        }
        return implode("\n", [
            'RSI (Wilder):',
            '- Initialisation: moyennes simples des gains/pertes sur la fenêtre (period).',
            '- Lissage Wilder: avgGain_t = ((avgGain_{t-1}*(period-1)) + gain_t)/period, idem pour avgLoss.',
            '- RS = avgGain/avgLoss (si avgLoss=0 => RS=+∞).',
            '- RSI = 100 - 100/(1 + RS).',
        ]);
    }

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
        // Prefer TRADER extension if available
        if (function_exists('trader_rsi')) {
            $res = \trader_rsi($closes, $period);
            if (is_array($res)) {
                // trader_rsi returns an array aligned from the first computable index
                return ['rsi' => array_values(array_map('floatval', $res))];
            }
        }

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

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $closes */
        $closes = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 14;
        return $this->calculate($closes, $period);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $closes */
        $closes = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 14;
        return $this->calculateFull($closes, $period);
    }
}
