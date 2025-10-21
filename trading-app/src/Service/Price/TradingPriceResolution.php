<?php

declare(strict_types=1);

namespace App\Service\Price;

final class TradingPriceResolution
{
    public function __construct(
        public readonly float $price,
        public readonly string $source,
        public readonly ?float $snapshotPrice,
        public readonly ?float $providerPrice,
        public readonly ?float $fallbackPrice,
        public readonly ?float $bestBid,
        public readonly ?float $bestAsk,
        public readonly ?float $relativeDiff,
        public readonly ?float $allowedDiff,
        public readonly bool $fallbackEngaged
    ) {
    }
}
