<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

use App\TradeEntry\Types\Side;
use App\TradeEntry\Pricing\TickQuantizer;

final class StopLossCalculator
{
    public function __construct() {}

    public function fromAtr(float $entry, Side $side, float $atr, float $k, int $precision): float
    {
        $raw = $side === Side::Long ? ($entry - $k * $atr) : ($entry + $k * $atr);

        return TickQuantizer::quantize($raw, $precision);
    }

    public function fromRisk(float $entry, Side $side, float $riskUsdt, int $size, float $contractSize, int $precision): float
    {
        $dMax = $riskUsdt / max($contractSize * $size, 1e-12);
        $raw = $side === Side::Long ? ($entry - $dMax) : ($entry + $dMax);

        return TickQuantizer::quantize($raw, $precision);
    }

    public function conservative(Side $side, float $a, float $b): float
    {
        return $side === Side::Long ? min($a, $b) : max($a, $b);
    }
}
