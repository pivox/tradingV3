<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\DTO;

final class OpenMarketResult
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $side,
        public readonly string $timeframe,
        public readonly ?string $orderId,
        public readonly float $entryMark,
        public readonly float $stopLoss,
        public readonly float $takeProfit,
        public readonly int $contracts,
        public readonly int $leverage,
        public readonly float $budgetUsed,
        public readonly float $riskAbsUsd,
        public readonly float $atr,
    ) {
    }
}
