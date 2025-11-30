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
        $n = count($prices);
        if ($n < 1) return [];
        if ($period <= 1) return array_values($prices);
        $k = 2 / ($period + 1);
        $ema = [];
        $cur = $prices[0];
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
}
