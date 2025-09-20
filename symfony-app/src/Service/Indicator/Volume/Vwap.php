<?php
namespace App\Service\Indicator\Volume;

final class Vwap
{
    /**
     * VWAP cumulatif (pas de reset de session).
     * @param float[] $highs
     * @param float[] $lows
     * @param float[] $closes
     * @param float[] $volumes
     * @return float[] VWAP series
     */
    public function calculateFull(array $highs, array $lows, array $closes, array $volumes): array
    {
        $n = min(count($highs), count($lows), count($closes), count($volumes));
        $out = [];
        if ($n === 0) return $out;

        $cumPV = 0.0; $cumV = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $tp = ((float)$highs[$i] + (float)$lows[$i] + (float)$closes[$i]) / 3.0;
            $v  = max(0.0, (float)$volumes[$i]);
            $cumPV += $tp * $v;
            $cumV  += $v;
            $out[] = $cumV > 0.0 ? $cumPV / $cumV : 0.0;
        }
        return $out;
    }

    /**
     * @return float last VWAP
     */
    public function calculate(array $highs, array $lows, array $closes, array $volumes): float
    {
        $s = $this->calculateFull($highs, $lows, $closes, $volumes);
        return empty($s) ? 0.0 : (float) end($s);
    }
}
