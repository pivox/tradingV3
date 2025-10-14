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
