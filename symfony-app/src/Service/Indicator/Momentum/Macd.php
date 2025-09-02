<?php
// src/Service/Indicator/Momentum/Macd.php

namespace App\Service\Indicator\Momentum;

/**
 * MACD (Gerald Appel) — MACD line = EMA(fast) - EMA(slow), signal = EMA(MACD, signalPeriod), hist = MACD - signal
 * - EMA seedé par SMA des 'period' premières valeurs.
 * - calculate() -> dernière triple (macd, signal, hist)
 * - calculateFull() -> séries alignées.
 */
final class Macd
{
    /**
     * Dernières valeurs MACD/signal/hist.
     * @param float[] $closes
     * @return array{macd: float, signal: float, hist: float}
     */
    public function calculate(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $full = $this->calculateFull($closes, $fast, $slow, $signal);
        if (empty($full['macd']) || empty($full['signal'])) {
            return ['macd' => 0.0, 'signal' => 0.0, 'hist' => 0.0];
        }
        $macd   = (float) end($full['macd']);
        $signal = (float) end($full['signal']);
        $hist   = $macd - $signal;

        return ['macd' => $macd, 'signal' => $signal, 'hist' => $hist];
    }

    /**
     * Séries MACD complètes.
     * @param float[] $closes
     * @return array{macd: float[], signal: float[], hist: float[]}
     */
    public function calculateFull(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $n = count($closes);
        if ($n < max($fast, $slow) + $signal) {
            return ['macd' => [], 'signal' => [], 'hist' => []];
        }

        $emaFast = $this->emaSeries($closes, $fast);
        $emaSlow = $this->emaSeries($closes, $slow);
        $len = min(count($emaFast), count($emaSlow));
        $macd = [];

        // MACD line = EMA(fast) - EMA(slow) (alignement par le plus court)
        for ($i = 0; $i < $len; $i++) {
            $macd[] = $emaFast[$i] - $emaSlow[$i];
        }

        // Signal = EMA(macd, signalPeriod)
        $signalSeries = $this->emaSeries($macd, $signal);

        // pour aligner MACD et Signal, on coupe la tête du MACD
        $shift = count($macd) - count($signalSeries);
        if ($shift > 0) {
            $macd = array_slice($macd, $shift);
        }

        // Histogramme
        $hist = [];
        $m = count($macd);
        for ($i = 0; $i < $m; $i++) {
            $hist[] = $macd[$i] - $signalSeries[$i];
        }

        return [
            'macd'   => $macd,
            'signal' => $signalSeries,
            'hist'   => $hist,
        ];
    }

    /**
     * EMA série complète (seed = SMA des 'period' premières valeurs).
     * @param float[] $values
     * @return float[]
     */
    private function emaSeries(array $values, int $period): array
    {
        $n = count($values);
        if ($n < $period) return [];

        // seed par SMA
        $sum = 0.0;
        for ($i = 0; $i < $period; $i++) $sum += $values[$i];
        $ema = [];
        $ema[] = $sum / $period;

        // multiplicateur EMA
        $alpha = 2.0 / ($period + 1.0);

        // continuer à partir de $period
        for ($i = $period; $i < $n; $i++) {
            $ema[] = $alpha * $values[$i] + (1.0 - $alpha) * end($ema);
        }

        return $ema;
    }
}
