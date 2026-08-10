<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalPriceObservation
{
    public function __construct(
        public string $exchange,
        public string $environment,
        public string $symbol,
        public string $marketType,
        public string $source,
        public string $timeframe,
        public float $value,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
    }
}
