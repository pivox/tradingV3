<?php

declare(strict_types=1);

namespace App\Trading\Dto;

use App\Common\Enum\PositionSide;
use App\Contract\Provider\Dto\PositionDto as ProviderPositionDto;
use Brick\Math\BigDecimal;

/**
 * DTO pour les positions dans le contexte Trading/Storage
 */
final class PositionDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly PositionSide $side,
        public readonly BigDecimal $size,
        public readonly BigDecimal $entryPrice,
        public readonly BigDecimal $markPrice,
        public readonly BigDecimal $unrealizedPnl,
        public readonly BigDecimal $leverage,
        public readonly \DateTimeImmutable $openedAt,
        public readonly array $raw = []
    ) {}

    /**
     * Mappe depuis un ProviderPositionDto
     */
    public static function fromProviderDto(ProviderPositionDto $providerDto): self
    {
        return new self(
            symbol: $providerDto->symbol,
            side: $providerDto->side,
            size: $providerDto->size,
            entryPrice: $providerDto->entryPrice,
            markPrice: $providerDto->markPrice,
            unrealizedPnl: $providerDto->unrealizedPnl,
            leverage: $providerDto->leverage,
            openedAt: $providerDto->openedAt,
            raw: $providerDto->metadata
        );
    }
}

