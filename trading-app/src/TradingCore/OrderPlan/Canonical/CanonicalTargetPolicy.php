<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalTargetPolicy
{
    public function __construct(
        public string $id,
        public float $riskMultiple,
        public string $liquidityRole,
    ) {
    }
}
