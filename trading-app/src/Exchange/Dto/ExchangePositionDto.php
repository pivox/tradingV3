<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangePositionSide;

final readonly class ExchangePositionDto
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public string $symbol,
        public ExchangePositionSide $side,
        public float $size,
        public float $entryPrice,
        public ?float $markPrice,
        public ?float $unrealizedPnl,
        public ?float $realizedPnl,
        public ?float $margin,
        public ?float $leverage,
        public ?\DateTimeImmutable $openedAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        public array $metadata = [],
        public ?string $exchangePositionId = null,
        public ?string $exchangeOrderId = null,
        public ?string $clientOrderId = null,
        public ?string $exchangeFillId = null,
    ) {
    }

    public function canonicalEvidence(): \App\Trading\Lineage\Persistence\CanonicalPositionEvidence
    {
        return new \App\Trading\Lineage\Persistence\CanonicalPositionEvidence(
            $this->exchangePositionId,
            $this->exchangeOrderId,
            $this->clientOrderId,
            $this->exchangeFillId,
        );
    }
}
