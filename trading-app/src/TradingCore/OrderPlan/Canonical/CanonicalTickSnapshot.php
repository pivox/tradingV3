<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalTickSnapshot
{
    public function __construct(
        public string $exchange,
        public string $environment,
        public string $symbol,
        public float $tickSize,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
    }
}
