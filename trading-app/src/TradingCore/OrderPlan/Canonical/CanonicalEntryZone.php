<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalEntryZone
{
    /** @param list<string> $inputHashes */
    public function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
        public string $symbol,
        public float $lowerPrice,
        public float $upperPrice,
        public float $entryPrice,
        public string $anchorSource,
        public string $anchorTimeframe,
        public string $atrTimeframe,
        public \DateTimeImmutable $observedAt,
        public \DateTimeImmutable $computedAt,
        public \DateTimeImmutable $expiresAt,
        public string $configHash,
        public array $inputHashes,
    ) {
    }

    public function contains(float $price): bool
    {
        return $price >= $this->lowerPrice && $price <= $this->upperPrice;
    }
}
