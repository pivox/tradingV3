<?php
namespace App\Indicator\Core\Momentum;

use App\Indicator\Core\IndicatorInterface;

 
final class StochRsi implements IndicatorInterface
{
    /**
     * Description textuelle du Stochastic RSI.
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return 'Stochastic RSI: oscillateur appliqué au RSI pour mesurer la position relative du RSI sur une fenêtre.';
        }
        return implode("\n", [
            'Stochastic RSI:',
            '- Étape 1: RSI (Wilder) sur rsiPeriod.',
            '- Étape 2: StochRSI_t = ((RSI_t - min(RSI, n)) / (max(RSI, n) - min(RSI, n))) * 100, avec n=stochPeriod.',
            '- %K = SMA(StochRSI, kSmoothing).',
            '- %D = SMA(%K, dSmoothing).',
        ]);
    }

    /**
     * @param float[] $closes
     * @return array{stoch_rsi: float[], k: float[], d: float[]}
     */
    public function calculateFull(array $closes, int $rsiPeriod = 14, int $stochPeriod = 14, int $kSmoothing = 3, int $dSmoothing = 3): array
    {
        // Prefer TRADER extension if available
        // trader_stochrsi signature: (real, timePeriod, fastK_Period, fastD_Period, fastD_MAType=SMA)
        if (function_exists('trader_stochrsi')) {
            $res = \trader_stochrsi($closes, $rsiPeriod, $stochPeriod, $kSmoothing);
            if (is_array($res) && isset($res[0], $res[1])) {
                $fastK = array_values(array_map('floatval', (array)$res[0]));
                $fastD = array_values(array_map('floatval', (array)$res[1]));
                // Align output to our structure: use fastK as stoch_rsi proxy, K=fastK, D=fastD
                return ['stoch_rsi' => $fastK, 'k' => $fastK, 'd' => $fastD];
            }
        }

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

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $closes */
        $closes      = $args[0] ?? [];
        $rsiPeriod   = isset($args[1]) ? (int)$args[1] : 14;
        $stochPeriod = isset($args[2]) ? (int)$args[2] : 14;
        $kS          = isset($args[3]) ? (int)$args[3] : 3;
        $dS          = isset($args[4]) ? (int)$args[4] : 3;
        return $this->calculate($closes, $rsiPeriod, $stochPeriod, $kS, $dS);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $closes */
        $closes      = $args[0] ?? [];
        $rsiPeriod   = isset($args[1]) ? (int)$args[1] : 14;
        $stochPeriod = isset($args[2]) ? (int)$args[2] : 14;
        $kS          = isset($args[3]) ? (int)$args[3] : 3;
        $dS          = isset($args[4]) ? (int)$args[4] : 3;
        return $this->calculateFull($closes, $rsiPeriod, $stochPeriod, $kS, $dS);
    }
}
