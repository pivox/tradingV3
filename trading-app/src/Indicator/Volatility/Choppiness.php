<?php
namespace App\Indicator\Volatility;

/**
 * Choppiness Index (CHOP)
 * CHOP = 100 * log10( sum(TR, n) / (max(high,n) - min(low,n)) ) / log10(n)
 */
final class Choppiness
{
    /**
     * @param float[] $highs
     * @param float[] $lows
     * @param float[] $closes
     * @return float[] CHOP series
     */
    public function calculateFull(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $n = min(count($highs), count($lows), count($closes));
        $out = [];
        if ($n === 0 || $period < 2) return $out;

        // True Range series
        $tr = [];
        for ($i = 0; $i < $n; $i++) {
            $h = (float)$highs[$i];
            $l = (float)$lows[$i];
            $c1 = $i > 0 ? (float)$closes[$i-1] : (float)$closes[$i];
            $tr[] = max($h - $l, abs($h - $c1), abs($l - $c1));
        }

        $logN = log10(max(2, $period));
        $sumTR = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumTR += $tr[$i];
            if ($i >= $period) { $sumTR -= $tr[$i - $period]; }

            if ($i + 1 >= $period) {
                $start = $i - $period + 1;
                $maxH = max(array_slice($highs, $start, $period));
                $minL = min(array_slice($lows,  $start, $period));
                $den = $maxH - $minL;
                $out[] = $den > 0 ? 100.0 * (log10($sumTR / $den) / $logN) : 0.0;
            } else {
                $out[] = 0.0;
            }
        }

        return $out;
    }

    /**
     * @return float last CHOP
     */
    public function calculate(array $highs, array $lows, array $closes, int $period = 14): float
    {
        $s = $this->calculateFull($highs, $lows, $closes, $period);
        return empty($s) ? 0.0 : (float) end($s);
    }
}
