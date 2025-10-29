<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

use App\TradeEntry\Types\Side;
use App\TradeEntry\Pricing\TickQuantizer;

final class TakeProfitCalculator
{
    public function __construct() {}

    public function fromRMultiple(float $entry, float $stop, Side $side, float $rMultiple, int $precision): float
    {
        $distance = abs($entry - $stop);
        $tp = $side === Side::Long ? ($entry + $rMultiple * $distance) : ($entry - $rMultiple * $distance);

        return TickQuantizer::quantize($tp, $precision);
    }
}
