<?php
declare(strict_types=1);

namespace App\TradeEntry\Pricing;

final class TickQuantizer
{
    public static function quantize(float $price, int $precision): float
    {
        $factor = 10 ** $precision;

        return floor($price * $factor) / $factor;
    }

    public static function quantizeUp(float $price, int $precision): float
    {
        $factor = 10 ** $precision;

        return ceil($price * $factor) / $factor;
    }

    public static function tick(int $precision): float
    {
        return 10 ** (-$precision);
    }
}
