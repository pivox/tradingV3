<?php

namespace App\Service\Trading;

final class SimpleQuantizer
{
    public function __construct(private readonly float $defaultTickSize = 0.1) {}

    public function quantizePrice(string $symbol, float $price, ?float $tickSize = null): float
    {
        $t = $tickSize ?: $this->defaultTickSize;
        if ($t <= 0) { return $price; }
        return floor($price / $t) * $t;
    }
}
