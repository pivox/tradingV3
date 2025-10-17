<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Market;

final class MarketSnapshot
{
    /**
     * @param array<int,array<string,mixed>> $ohlc
     */
    public function __construct(
        public readonly string $symbol,
        public readonly float $markPrice,
        public readonly float $atr,
        public readonly float $stopDistance,
        public readonly float $stopPct,
        public readonly float $tickSize,
        public readonly float $qtyStep,
        public readonly float $contractSize,
        public readonly int $minVolume,
        public readonly int $maxVolume,
        public readonly ?int $marketMaxVolume,
        public readonly int $maxLeverage,
        public readonly array $ohlc,
        public readonly array $contractRaw,
    ) {
    }
}
