<?php

declare(strict_types=1);

namespace App\Contract\Provider\Dto;

use App\Common\Enum\PositionSide;
use Brick\Math\BigDecimal;

/**
 * DTO pour les positions
 */
final class PositionDto extends BaseDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly PositionSide $side,
        public readonly BigDecimal $size,
        public readonly BigDecimal $entryPrice,
        public readonly BigDecimal $markPrice,
        public readonly BigDecimal $unrealizedPnl,
        public readonly BigDecimal $realizedPnl,
        public readonly BigDecimal $margin,
        public readonly BigDecimal $leverage,
        public readonly \DateTimeImmutable $openedAt,
        public readonly ?\DateTimeImmutable $closedAt = null,
        public readonly array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            side: PositionSide::from($data['side']),
            size: BigDecimal::of($data['size']),
            entryPrice: BigDecimal::of($data['entry_price']),
            markPrice: BigDecimal::of($data['mark_price']),
            unrealizedPnl: BigDecimal::of($data['unrealized_pnl'] ?? 0),
            realizedPnl: BigDecimal::of($data['realized_pnl'] ?? 0),
            margin: BigDecimal::of($data['margin']),
            leverage: BigDecimal::of($data['leverage']),
            openedAt: new \DateTimeImmutable($data['opened_at']),
            closedAt: isset($data['closed_at']) ? new \DateTimeImmutable($data['closed_at']) : null,
            metadata: $data['metadata'] ?? []
        );
    }
}


