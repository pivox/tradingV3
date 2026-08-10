<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalMarketSnapshot
{
    public function __construct(
        public string $exchange,
        public string $environment,
        public string $symbol,
        public string $source,
        public float $candidatePrice,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
    }
}
