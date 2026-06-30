<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid\Lifecycle;

use App\Exchange\Enum\ExchangePositionSide;

final readonly class HyperliquidNormalizedPositionDto
{
    /**
     * @param list<string> $qualityFlags
     * @param array<string,mixed> $redactedPayload
     */
    public function __construct(
        public string $symbol,
        public ExchangePositionSide $side,
        public float $size,
        public float $entryPrice,
        public ?float $markPrice,
        public ?float $unrealizedPnl,
        public ?float $marginUsed,
        public ?float $leverage,
        public ?\DateTimeImmutable $updatedAt,
        public array $qualityFlags,
        public array $redactedPayload,
    ) {
    }
}
