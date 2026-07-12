<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final readonly class HyperliquidExecutionState
{
    public function __construct(
        public string $symbol,
        public float $bestBid,
        public float $bestAsk,
        public \DateTimeImmutable $observedAt,
        public ?int $observedLeverage,
        public ?string $observedMarginMode = null,
        public bool $hasOpenPosition = false,
    ) {
    }
}
