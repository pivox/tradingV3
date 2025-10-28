<?php
// src/Service/Indicator/Volatility/Bollinger.php

namespace App\Indicator\Core\Volatility;

use App\Indicator\Core\IndicatorInterface;

/**
 * Bandes de Bollinger (John Bollinger)
 * - middle = SMA(period)
 * - upper  = middle + stdev * σ
 * - lower  = middle - stdev * σ
 * - width  = upper - lower  (utilisé pour estimer l'expansion de volatilité)
 *
 * Note: ici l'écart-type est calculé sur 'period' points (variance population sur la fenêtre).
 */
 
final class Bollinger implements IndicatorInterface
{
    /**
     * Description textuelle des Bandes de Bollinger.
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return "Bandes de Bollinger: enveloppes autour d'une SMA, écartées de σ écarts-types.";
        }
        return implode("\n", [
            'Bollinger Bands:',
            '- middle = SMA(period).',
            '- upper  = middle + stdev * σ.',
            '- lower  = middle - stdev * σ.',
            '- σ = écart-type des prix sur la fenêtre (population).',
            '- width = upper - lower.',
        ]);
    }

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
        // Prefer TRADER extension if available
        if (function_exists('trader_bbands')) {
            // trader_bbands(real, timePeriod, nbDevUp, nbDevDn, matype=SMA)
            $res = \trader_bbands($closes, $period, $stdev, $stdev);
            if (is_array($res) && isset($res[0], $res[1], $res[2])) {
                $upper  = array_values(array_map('floatval', (array)$res[0]));
                $middle = array_values(array_map('floatval', (array)$res[1]));
                $lower  = array_values(array_map('floatval', (array)$res[2]));
                $width  = [];
                $m = min(count($upper), count($lower));
                for ($i = 0; $i < $m; $i++) { $width[] = $upper[$i] - $lower[$i]; }
                return compact('upper','lower','middle','width');
            }
        }

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

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $closes */
        $closes = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 20;
        $sigma  = isset($args[2]) ? (float)$args[2] : 2.0;
        return $this->calculate($closes, $period, $sigma);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $closes */
        $closes = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 20;
        $sigma  = isset($args[2]) ? (float)$args[2] : 2.0;
        return $this->calculateFull($closes, $period, $sigma);
    }
}
