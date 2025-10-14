<?php
namespace App\Indicator\Volatility;

final class Donchian
{
    /**
     * Donchian Channel sur N périodes.
     * upper = plus haut sur N, lower = plus bas sur N, middle = (upper+lower)/2
     *
     * @param float[] $highs
     * @param float[] $lows
     * @return array{upper: float[], lower: float[], middle: float[]}
     */
    public function calculateFull(array $highs, array $lows, int $period = 20): array
    {
        $n = min(count($highs), count($lows));
        $upper = $lower = $middle = [];

        if ($n === 0 || $period < 1) {
            return ['upper' => [], 'lower' => [], 'middle' => []];
        }

        for ($i = 0; $i < $n; $i++) {
            $start = max(0, $i - $period + 1);
            $len   = $i - $start + 1;

            $windowHighs = array_slice($highs, $start, $len);
            $windowLows  = array_slice($lows,  $start, $len);

            $u = max($windowHighs);
            $l = min($windowLows);
            $m = ($u + $l) / 2.0;

            $upper[]  = $u;
            $lower[]  = $l;
            $middle[] = $m;
        }

        return ['upper' => $upper, 'lower' => $lower, 'middle' => $middle];
    }

    /**
     * Retourne la dernière valeur du canal.
     * @return array{upper: float, lower: float, middle: float}
     */
    public function calculate(array $highs, array $lows, int $period = 20): array
    {
        $full = $this->calculateFull($highs, $lows, $period);

        return [
            'upper'  => empty($full['upper'])  ? 0.0 : (float) end($full['upper']),
            'lower'  => empty($full['lower'])  ? 0.0 : (float) end($full['lower']),
            'middle' => empty($full['middle']) ? 0.0 : (float) end($full['middle']),
        ];
    }
}
