<?php
// src/Service/Indicator/Volatility/Bollinger.php

namespace App\Indicator\Volatility;

/**
 * Bandes de Bollinger (John Bollinger)
 * - middle = SMA(period)
 * - upper  = middle + stdev * σ
 * - lower  = middle - stdev * σ
 * - width  = upper - lower  (utilisé pour estimer l'expansion de volatilité)
 *
 * Note: ici l'écart-type est calculé sur 'period' points (variance population sur la fenêtre).
 */
final class Bollinger
{
    /**
     * Dernier point (upper/lower/middle/width).
     * @param float[] $closes
     * @return array{upper: float, lower: float, middle: float, width: float}
     */
    public function calculate(array $closes, int $period = 20, float $stdev = 2.0): array
    {
        $full = $this->calculateFull($closes, $period, $stdev);
        if (empty($full['upper'])) {
            return ['upper'=>0.0,'lower'=>0.0,'middle'=>0.0,'width'=>0.0];
        }
        $upper  = (float) end($full['upper']);
        $lower  = (float) end($full['lower']);
        $middle = (float) end($full['middle']);
        $width  = $upper - $lower;

        return compact('upper','lower','middle','width');
    }

    /**
     * Séries complètes (upper/lower/middle/width).
     * @param float[] $closes
     * @return array{upper: float[], lower: float[], middle: float[], width: float[]}
     */
    public function calculateFull(array $closes, int $period = 20, float $stdev = 2.0): array
    {
        $n = count($closes);
        if ($n < $period) {
            return ['upper'=>[],'lower'=>[],'middle'=>[],'width'=>[]];
        }

        $middle = $this->smaSeries($closes, $period);
        $upper = []; $lower = []; $width = [];

        // Parcourt chaque fenêtre alignée au SMA
        $m = count($middle);
        for ($i = 0; $i < $m; $i++) {
            // la fenêtre dans 'closes' correspondant au middle[i]
            $start = $i;
            $end   = $i + $period; // non inclus
            $slice = array_slice($closes, $start, $period);

            $sigma = $this->stdOnWindow($slice);
            $u = $middle[$i] + $stdev * $sigma;
            $l = $middle[$i] - $stdev * $sigma;

            $upper[] = $u;
            $lower[] = $l;
            $width[] = $u - $l;
        }

        return compact('upper','lower','middle','width');
    }

    /** SMA série sur fenêtre glissante. */
    private function smaSeries(array $values, int $period): array
    {
        $n = count($values);
        if ($n < $period) return [];
        $out = [];
        $sum = 0.0;

        for ($i = 0; $i < $period; $i++) $sum += $values[$i];
        $out[] = $sum / $period;

        for ($i = $period; $i < $n; $i++) {
            $sum += $values[$i] - $values[$i - $period];
            $out[] = $sum / $period;
        }
        return $out;
    }

    /** Ecart-type (population) sur une fenêtre. */
    private function stdOnWindow(array $window): float
    {
        $m = count($window);
        if ($m === 0) return 0.0;

        $mean = array_sum($window) / $m;
        $var = 0.0;
        foreach ($window as $v) {
            $d = $v - $mean;
            $var += $d * $d;
        }
        $var /= $m; // population variance
        return sqrt($var);
    }
}
