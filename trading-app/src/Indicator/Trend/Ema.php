<?php

namespace App\Indicator\Trend;

class Ema
{
    public function calculate(array $prices, int $period): float
    {
       // dd($prices, $period);
        if (!$prices || $period <= 1) return end($prices) ?: 0.0;
        $k = 2 / ($period + 1);
        $ema = $prices[0];

        foreach ($prices as $price) {
            $ema = $price * $k + $ema * (1 - $k);
        }

        return $ema;
    }
}
