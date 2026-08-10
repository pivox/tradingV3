<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalProtectionTarget
{
    public function __construct(
        public string $id,
        public float $price,
        public float $riskMultiple,
        public string $liquidityRole,
    ) {
    }
}
