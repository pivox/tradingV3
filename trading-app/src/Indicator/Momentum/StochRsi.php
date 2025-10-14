<?php
namespace App\Indicator\Momentum;

final class StochRsi
{
    /**
     * @param float[] $closes
     * @return array{stoch_rsi: float[], k: float[], d: float[]}
     */
    public function calculateFull(array $closes, int $rsiPeriod = 14, int $stochPeriod = 14, int $kSmoothing = 3, int $dSmoothing = 3): array
    {
        $n = count($closes);
        $stochRsi = [];
        if ($n === 0 || $rsiPeriod < 1 || $stochPeriod < 1) {
            return ['stoch_rsi' => [], 'k' => [], 'd' => []];
        }

        // RSI (Wilder)
        $rsi = $this->rsiSeries($closes, $rsiPeriod);
        $m = count($rsi);
        if ($m === 0) {
            return ['stoch_rsi' => [], 'k' => [], 'd' => []];
        }

        // Stochastique appliqué au RSI
        for ($i = 0; $i < $m; $i++) {
            $start = max(0, $i - $stochPeriod + 1);
            $slice = array_slice($rsi, $start, $i - $start + 1);
            $min = min($slice);
            $max = max($slice);
            $stochRsi[] = ($max - $min) > 0 ? ( ($rsi[$i] - $min) / ($max - $min) ) * 100.0 : 0.0;
        }

        // %K = SMA(stochRsi, kSmoothing), %D = SMA(%K, dSmoothing)
        $k = $this->smaSeries($stochRsi, $kSmoothing);
        $d = $this->smaSeries($k, $dSmoothing);

        return ['stoch_rsi' => $stochRsi, 'k' => $k, 'd' => $d];
    }

    /**
     * @param float[] $closes
     * @return array{stoch_rsi: float, k: float, d: float}
     */
    public function calculate(array $closes, int $rsiPeriod = 14, int $stochPeriod = 14, int $kSmoothing = 3, int $dSmoothing = 3): array
    {
        $full = $this->calculateFull($closes, $rsiPeriod, $stochPeriod, $kSmoothing, $dSmoothing);
        return [
            'stoch_rsi' => empty($full['stoch_rsi']) ? 0.0 : (float) end($full['stoch_rsi']),
            'k'         => empty($full['k']) ? 0.0 : (float) end($full['k']),
            'd'         => empty($full['d']) ? 0.0 : (float) end($full['d']),
        ];
    }

    /** @param float[] $values */
    private function smaSeries(array $values, int $period): array
    {
        $out = [];
        $n = count($values);
        if ($n === 0 || $period < 1) return $out;

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            if ($i >= $period) { $sum -= $values[$i - $period]; }
            $out[] = $i + 1 >= $period ? $sum / $period : $values[$i]; // simple fallback at warmup
        }
        return $out;
    }

    /** Wilder RSI series */
    private function rsiSeries(array $closes, int $period): array
    {
        $n = count($closes);
        if ($n < 2) return [];

        $gains = []; $losses = [];
        for ($i = 1; $i < $n; $i++) {
            $diff = (float)$closes[$i] - (float)$closes[$i-1];
            $gains[]  = max(0.0,  $diff);
            $losses[] = max(0.0, -$diff);
        }

        $rsi = [];
        $len = count($gains);
        if ($len < $period) return [];

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;
        $rs  = $avgLoss == 0.0 ? INF : $avgGain / $avgLoss;
        $rsi[] = 100 - (100 / (1 + $rs));

        for ($i = $period; $i < $len; $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
            $rs = $avgLoss == 0.0 ? INF : $avgGain / $avgLoss;
            $rsi[] = 100 - (100 / (1 + $rs));
        }

        return $rsi;
    }
}
