<?php

namespace App\Indicator\Core\Trend;

use App\Indicator\Core\IndicatorInterface;


class Ema implements IndicatorInterface
{
    /**
     * Description textuelle de l'EMA.
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return "EMA: moyenne mobile exponentielle, pondérant davantage les prix récents.";
        }
        return implode("\n", [
            'EMA:',
            '- α = 2/(period+1).',
            '- EMA_t = α * price_t + (1-α) * EMA_{t-1}.',
            '- Amorçage usuel par SMA des period premières valeurs.',
        ]);
    }

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $prices */
        $prices = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 14;
        return $this->calculate($prices, $period);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $prices */
        $prices = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 14;
        if (function_exists('trader_ema')) {
            $arr = \trader_ema($prices, $period);
            return is_array($arr) ? array_values(array_map('floatval', $arr)) : [];
        }

        return $this->calculateSeriesPhp($prices, $period);
    }

    /**
     * @param float[] $prices
     * @return list<float>
     */
    public function calculateSeriesPhp(array $prices, int $period): array
    {
        $n = count($prices);
        if ($n < 1) return [];
        if ($period <= 1) return array_values(array_map('floatval', $prices));
        $k = 2 / ($period + 1);
        $ema = [];
        $cur = (float) $prices[0];
        $ema[] = $cur;
        for ($i = 1; $i < $n; $i++) {
            $cur = $prices[$i] * $k + $cur * (1 - $k);
            $ema[] = $cur;
        }
        return $ema;
    }

    public function calculate(array $prices, int $period): float
    {
        if (function_exists('trader_ema')) {
            $arr = \trader_ema($prices, $period);
            if (is_array($arr) && !empty($arr)) {
                return (float) end($arr);
            }
        }
        if (!$prices || $period <= 1) return end($prices) ?: 0.0;
        $k = 2 / ($period + 1);
        $ema = $prices[0];

        foreach ($prices as $price) {
            $ema = $price * $k + $ema * (1 - $k);
        }

        return $ema;
    }

    /** @param float[] $prices */
    public function calculatePhp(array $prices, int $period): ?float
    {
        if ($prices === [] || $period <= 0) {
            return null;
        }
        if ($period <= 1) {
            return (float) end($prices);
        }

        $multiplier = 2.0 / ($period + 1.0);
        $ema = $prices[0];
        foreach ($prices as $price) {
            $ema = ($price * $multiplier) + ($ema * (1.0 - $multiplier));
        }

        return $ema;
    }
}
