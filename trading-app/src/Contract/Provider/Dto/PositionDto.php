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
        // Mapping BitMart API vers DTO
        // BitMart utilise 'position_side' (long/short) au lieu de 'side'
        $side = $data['side'] ?? $data['position_side'] ?? null;
        if ($side === null) {
            throw new \InvalidArgumentException('Missing side/position_side in position data');
        }
        
        // BitMart utilise 'current_amount' au lieu de 'size'
        $size = $data['size'] ?? $data['current_amount'] ?? '0';
        
        // BitMart utilise 'entry_price' ou 'open_avg_price'
        $entryPrice = $data['entry_price'] ?? $data['open_avg_price'] ?? '0';
        
        // BitMart utilise 'mark_price'
        $markPrice = $data['mark_price'] ?? '0';
        
        // BitMart utilise 'unrealized_pnl'
        $unrealizedPnl = $data['unrealized_pnl'] ?? '0';
        
        // BitMart utilise 'realized_value' au lieu de 'realized_pnl'
        $realizedPnl = $data['realized_pnl'] ?? $data['realized_value'] ?? '0';
        
        // BitMart utilise 'initial_margin' ou 'position_cross'
        $margin = $data['margin'] ?? $data['initial_margin'] ?? $data['position_cross'] ?? '0';
        
        // BitMart utilise 'leverage' (string)
        $leverage = $data['leverage'] ?? '1';
        
        // BitMart utilise 'open_timestamp' (milliseconds) ou 'timestamp'
        $openedAtTimestamp = $data['open_timestamp'] ?? $data['timestamp'] ?? null;
        if ($openedAtTimestamp) {
            // Convertir millisecondes en secondes pour DateTimeImmutable
            $openedAt = new \DateTimeImmutable('@' . (int)($openedAtTimestamp / 1000));
        } else {
            $openedAt = new \DateTimeImmutable('@' . time());
        }
        
        $closedAt = null;
        if (isset($data['closed_at'])) {
            $closedAt = new \DateTimeImmutable($data['closed_at']);
        }
        
        return new self(
            symbol: $data['symbol'],
            side: PositionSide::from($side),
            size: BigDecimal::of($size),
            entryPrice: BigDecimal::of($entryPrice),
            markPrice: BigDecimal::of($markPrice),
            unrealizedPnl: BigDecimal::of($unrealizedPnl),
            realizedPnl: BigDecimal::of($realizedPnl),
            margin: BigDecimal::of($margin),
            leverage: BigDecimal::of($leverage),
            openedAt: $openedAt,
            closedAt: $closedAt,
            metadata: $data['metadata'] ?? []
        );
    }
}


