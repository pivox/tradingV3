<?php
// src/Indicator/Momentum/Macd.php

namespace App\Indicator\Core\Momentum;

use App\Indicator\Core\IndicatorInterface;

/**
 * MACD (Gerald Appel) — MACD line = EMA(fast) - EMA(slow), signal = EMA(MACD, signalPeriod), hist = MACD - signal
 * - EMA seedé par SMA des 'period' premières valeurs.
 * - calculate() -> dernière triple (macd, signal, hist)
 * - calculateFull() -> séries alignées.
 */
 
final class Macd implements IndicatorInterface
{
    /**
     * Description textuelle de l'indicateur MACD.
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return "MACD (Appel): diff. EMA(fast) et EMA(slow), avec ligne de signal (EMA du MACD) et histogramme (MACD - signal).";
        }
        return implode("\n", [
            'MACD (Gerald Appel):',
            '- MACD = EMA_fast(closes) - EMA_slow(closes).',
            '- Signal = EMA(MACD, signalPeriod).',
            '- Histogramme = MACD - Signal.',
            '- EMA seedée par SMA(period), puis EMA_t = α*x_t + (1-α)*EMA_{t-1}, α = 2/(period+1).',
        ]);
    }

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
     * Variante nullable : retourne des null si insuffisant.
     * @return array{macd: ?float, signal: ?float, hist: ?float}
     */
    public function calculateNullable(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $full = $this->calculateFull($closes, $fast, $slow, $signal);
        if (empty($full['macd']) || empty($full['signal'])) {
            return ['macd' => null, 'signal' => null, 'hist' => null];
        }
        $macdVal   = (float) end($full['macd']);
        $signalVal = (float) end($full['signal']);
        $histVal   = $macdVal - $signalVal;

        return ['macd' => $macdVal, 'signal' => $signalVal, 'hist' => $histVal];
    }

    /**
     * Séries MACD complètes.
     * @param float[] $closes
     * @return array{macd: float[], signal: float[], hist: float[]}
     */
    public function calculateFull(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        // Prefer TRADER extension if available
        if (function_exists('trader_macd')) {
            $res = \trader_macd($closes, $fast, $slow, $signal);
            if (is_array($res) && isset($res[0], $res[1], $res[2])) {
                // trader_macd returns [macd, macdsignal, macdhist]
                $macdArr   = array_values(array_map('floatval', (array)$res[0]));
                $signalArr = array_values(array_map('floatval', (array)$res[1]));
                $histArr   = array_values(array_map('floatval', (array)$res[2]));
                return ['macd' => $macdArr, 'signal' => $signalArr, 'hist' => $histArr];
            }
        }

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

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $closes */
        $closes = $args[0] ?? [];
        $fast   = isset($args[1]) ? (int)$args[1] : 12;
        $slow   = isset($args[2]) ? (int)$args[2] : 26;
        $signal = isset($args[3]) ? (int)$args[3] : 9;
        return $this->calculate($closes, $fast, $slow, $signal);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $closes */
        $closes = $args[0] ?? [];
        $fast   = isset($args[1]) ? (int)$args[1] : 12;
        $slow   = isset($args[2]) ? (int)$args[2] : 26;
        $signal = isset($args[3]) ? (int)$args[3] : 9;
        return $this->calculateFull($closes, $fast, $slow, $signal);
    }
}
