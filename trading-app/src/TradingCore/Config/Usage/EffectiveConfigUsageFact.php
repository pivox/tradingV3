<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Usage;

final readonly class EffectiveConfigUsageFact
{
    public function __construct(
        public string $source,
        public string $rowIdentity,
        public ?string $configHash,
        public ?string $effectiveConfigReference,
        public ?string $decisionId,
        public ?string $tradeId,
        public ?string $internalTradeId,
    ) {
    }
}
