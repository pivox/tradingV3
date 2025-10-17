<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Sizing;

final class SizingDecision
{
    public function __construct(
        public readonly int $contracts,
        public readonly float $stopLoss,
        public readonly float $takeProfit,
        public readonly float $qtyNotional,
        public readonly int $leverage,
    ) {
    }
}
