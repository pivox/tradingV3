<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalNetRDecision
{
    /** @param non-empty-list<CanonicalNetRTargetDecision> $targets */
    public function __construct(
        public array $targets,
        public float $minimumNetR,
        public int $fundingIntervals,
        public string $configHash,
        public string $costInputHash,
    ) {
    }
}
