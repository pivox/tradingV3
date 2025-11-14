<?php

declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class MarketStructureSnapshot
{
    public function __construct(
        public readonly ?float $depthTopUsd = null,
        public readonly ?float $bookLiquidityScore = null,
        public readonly ?float $volatilityPct1m = null,
        public readonly ?float $volumeRatio = null,
        public readonly ?float $latencyRestMs = null,
        public readonly ?float $latencyWsMs = null,
    ) {
    }
}
