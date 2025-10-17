<?php
declare(strict_types=1);

namespace App\Indicator;

/**
 * ATR calculator (Wilder or Simple) for OHLCV arrays.
 * Each candle must include: high, low, close (floats).
 */
final class AtrCalculator
{
    /**
     * @param array<int, array{high: float, low: float, close: float}> $ohlc
     */
    public function compute(array $ohlc, int $period = 14, string $method = 'wilder'): float
    {
        if ($period <= 0) {
            throw new \InvalidArgumentException('ATR period must be > 0');
        }
        $n = count($ohlc);
        if ($n <= $period) {
            throw new \InvalidArgumentException('Not enough candles to compute ATR');
        }


// True Range series
        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $h = (float)$ohlc[$i]['high'];
            $l = (float)$ohlc[$i]['low'];
            $pc = (float)$ohlc[$i - 1]['close'];
            $tr = max($h - $l, abs($h - $pc), abs($l - $pc));
            $trs[] = $tr;
        }


        if ($method === 'simple') {
// Simple moving average of last $period TRs
            $slice = array_slice($trs, -$period);
            return array_sum($slice) / $period;
        }


// Wilder: seed with SMA of first $period TRs, then recursive smoothing
        $seed = array_slice($trs, 0, $period);
        $atr = array_sum($seed) / $period;
        for ($i = $period; $i < count($trs); $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        }
        return $atr;
    }


    /**
     * Calcule la série ATR complète (Wilder ou Simple) pour chaque point disponible après amorçage.
     * Retourne un tableau d'ATR aligné sur les TRs (taille n-1), où les index < ($period) ne sont pas lissés.
     *
     * @param array<int, array{high: float, low: float, close: float}> $ohlc
     * @return float[]
     */
    public function computeSeries(array $ohlc, int $period = 14, string $method = 'wilder'): array
    {
        if ($period <= 0) {
            return [];
        }
        $n = count($ohlc);
        if ($n <= $period) {
            return [];
        }

        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $h = (float)$ohlc[$i]['high'];
            $l = (float)$ohlc[$i]['low'];
            $pc = (float)$ohlc[$i - 1]['close'];
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        if ($method === 'simple') {
            $series = [];
            for ($i = $period; $i <= count($trs); $i++) {
                $slice = array_slice($trs, $i - $period, $period);
                $series[] = array_sum($slice) / $period;
            }
            return $series; // longueur = count($trs) - $period + 1
        }

        // Wilder: amorçage SMA des $period premiers TRs, puis lissage récursif
        $seed = array_slice($trs, 0, $period);
        $atr = array_sum($seed) / $period;
        $series = [$atr];
        for ($i = $period; $i < count($trs); $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
            $series[] = $atr;
        }
        return $series; // longueur = count($trs) - $period + 1
    }

    /**
     * Variante robuste au bruit pour 1m/5m:
     * - Si timeframe=1m, filtre les valeurs aberrantes: ATR_t > 3 * median(ATR dernière 50) => cap à 3*median
     * - Si toutes bougies quasi plates, applique un plancher = tickSize * 2
     * - En cas d'échantillon insuffisant, retourne 0.0
     */
    public function computeWithRules(
        array $ohlc,
        int $period = 14,
        string $method = 'wilder',
        ?string $timeframe = null,
        float $tickSize = 0.0
    ): float {
        $n = count($ohlc);
        if ($period <= 0 || $n <= $period) {
            return 0.0;
        }

        // Série ATR pour pouvoir estimer la médiane roulante
        $series = $this->computeSeries($ohlc, $period, $method);
        if ($series === []) {
            return 0.0;
        }
        $latest = (float) end($series);

        // Plancher tick*2 si quasi flat
        if ($tickSize > 0.0) {
            $isFlat = true;
            for ($i = 1; $i < $n; $i++) {
                $h = (float)$ohlc[$i]['high'];
                $l = (float)$ohlc[$i]['low'];
                if (($h - $l) > 0.0) { $isFlat = false; break; }
            }
            if ($isFlat) {
                $latest = max($latest, $tickSize * 2.0);
            }
        }

        // Filtrage outliers spécifique 1m: cap à 3x médiane(50)
        if ($timeframe === '1m') {
            $window = 50;
            $tail = array_slice($series, -$window);
            if (count($tail) > 0) {
                sort($tail);
                $mIdx = (int) floor((count($tail) - 1) / 2);
                $median = $tail[$mIdx] ?? 0.0;
                $cap = 3.0 * $median;
                if ($median > 0.0 && $latest > $cap) {
                    $latest = $cap; // ignorer l'excès
                }
            }
        }

        return $latest;
    }

    /**
     * Mélange dynamique ATR 1m/5m avec poids w (0..1).
     */
    public function dynamicMix(float $atr1m, float $atr5m, float $w): float
    {
        $w = max(0.0, min(1.0, $w));
        return ($w * $atr1m) + ((1.0 - $w) * $atr5m);
    }


    /**
     * Convenience helper: stop price from ATR multiple.
     * @param 'long'|'short' $side
     */
    public function stopFromAtr(float $entry, float $atr, float $k, string $side): float
    {
        if ($k <= 0.0) {
            throw new \InvalidArgumentException('ATR multiplier k must be > 0');
        }
        return $side === 'long'
            ? max(0.0, $entry - $k * $atr)
            : max(0.0, $entry + $k * $atr);
    }
}
