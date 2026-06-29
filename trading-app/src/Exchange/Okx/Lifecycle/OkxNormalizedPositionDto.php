<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Lifecycle;

use App\Exchange\Enum\ExchangePositionSide;

final readonly class OkxNormalizedPositionDto
{
    /**
     * @param array<string,mixed> $redactedPayload
     */
    public function __construct(
        public string $symbol,
        public ExchangePositionSide $side,
        public float $size,
        public float $entryPrice,
        public ?float $markPrice,
        public ?float $unrealizedPnl,
        public ?float $leverage,
        public ?\DateTimeImmutable $updatedAt,
        public array $redactedPayload,
    ) {
    }
}
