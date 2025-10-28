<?php
namespace App\Indicator\Core\Volatility;

use App\Indicator\Core\IndicatorInterface;

/**
 * Choppiness Index (CHOP)
 * CHOP = 100 * log10( sum(TR, n) / (max(high,n) - min(low,n)) ) / log10(n)
 */
 
final class Choppiness implements IndicatorInterface
{
    /**
     * Description textuelle du Choppiness Index (CHOP).
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return 'Choppiness Index (CHOP): quantifie le caractère trend/choppy en normalisant la somme des TR.';
        }
        return implode("\n", [
            'CHOP:',
            '- TR_t = max(high_t-low_t, |high_t-close_{t-1}|, |low_t-close_{t-1}|).',
            '- CHOP = 100 * log10( sum(TR, n) / (max(high,n) - min(low,n)) ) / log10(n).',
        ]);
    }

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

        $logN = log10(max(2.0, (float)$period));
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

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $highs */
        $highs = $args[0] ?? [];
        $lows  = $args[1] ?? [];
        $closes= $args[2] ?? [];
        $period= isset($args[3]) ? (int)$args[3] : 14;
        return $this->calculate($highs, $lows, $closes, $period);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $highs */
        $highs = $args[0] ?? [];
        $lows  = $args[1] ?? [];
        $closes= $args[2] ?? [];
        $period= isset($args[3]) ? (int)$args[3] : 14;
        return $this->calculateFull($highs, $lows, $closes, $period);
    }
}
