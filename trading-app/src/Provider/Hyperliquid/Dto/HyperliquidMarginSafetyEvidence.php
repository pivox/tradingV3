<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid\Dto;

final readonly class HyperliquidMarginSafetyEvidence
{
    /** @param list<HyperliquidMarginTierEvidence> $tiers */
    public function __construct(
        public string $symbol,
        public string $coin,
        public int $marginTableId,
        public int $universeMaxLeverage,
        public array $tiers,
        public string $accountAddress,
        public string $observedUser,
        public string $observedCoin,
        public string $observedMarginMode,
        public int $observedLeverage,
        public \DateTimeImmutable $observedAt,
    ) {
    }
}
