<?php
namespace App\Service\Indicator\Volume;

final class Obv
{
    /**
     * @param float[] $closes
     * @param float[] $volumes
     * @return float[] OBV series (cumulative)
     */
    public function calculateFull(array $closes, array $volumes): array
    {
        $n = min(count($closes), count($volumes));
        $out = [];
        if ($n === 0) return $out;

        $obv = 0.0;
        $out[] = $obv;
        for ($i = 1; $i < $n; $i++) {
            $v = (float)$volumes[$i];
            if ($closes[$i] > $closes[$i-1]) {
                $obv += $v;
            } elseif ($closes[$i] < $closes[$i-1]) {
                $obv -= $v;
            }
            $out[] = $obv;
        }
        return $out;
    }

    /**
     * @return float last OBV
     */
    public function calculate(array $closes, array $volumes): float
    {
        $s = $this->calculateFull($closes, $volumes);
        return empty($s) ? 0.0 : (float) end($s);
    }
}
