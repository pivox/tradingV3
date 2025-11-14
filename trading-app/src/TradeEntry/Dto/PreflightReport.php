<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class PreflightReport
{
    public function __construct(
        public readonly string $symbol,
        public readonly float $bestBid,
        public readonly float $bestAsk,
        public readonly int $pricePrecision,
        public readonly float $contractSize,
        public readonly int $minVolume,
        public readonly int $maxLeverage,
        public readonly int $minLeverage,
        public readonly float $availableUsdt,
        public readonly float $spreadPct = 0.0,
        public readonly ?string $modeNote = null,
        public readonly ?float $lastPrice = null,
        public readonly ?float $tickSize = null,
        public readonly ?float $markPrice = null,
        public readonly ?int $volPrecision = null,
        public readonly ?float $maxVolume = null,
        public readonly ?float $marketMaxVolume = null,
        /** @var array<string,float>|null */
        public readonly ?array $pivotLevels = null,
        public readonly ?float $depthTopUsd = null,
        public readonly ?float $bookLiquidityScore = null,
        public readonly ?float $volatilityPct1m = null,
        public readonly ?float $volumeRatio = null,
        public readonly ?float $latencyRestMs = null,
        public readonly ?float $latencyWsMs = null,
    ) {}
}
