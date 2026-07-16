<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeFillCost
{
    public function __construct(
        public string $liquidityRole,
        public float $spreadCostUsdt,
        public float $slippageCostUsdt,
        public string $modelVersion,
        public string $spreadModelVersion,
    ) {
    }
}
