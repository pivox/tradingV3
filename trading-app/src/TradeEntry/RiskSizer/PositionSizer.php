<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

final class PositionSizer
{
    public function __construct() {}

    public function fromRiskAndDistance(float $riskUsdt, float $distance, float $contractSize, int $minVolume): int
    {
        $sizeFloat = $riskUsdt / max($distance * $contractSize, 1e-12);
        $size = (int)floor($sizeFloat);
        if ($size < $minVolume) {
            throw new \RuntimeException("Taille calculée {$size} < min_volume {$minVolume}");
        }

        return $size;
    }
}
