<?php

declare(strict_types=1);

namespace App\Contract\Provider\Dto;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Common\Enum\OrderStatus;
use Brick\Math\BigDecimal;

/**
 * DTO pour les ordres
 */
final class OrderDto extends BaseDto
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $symbol,
        public readonly OrderSide $side,
        public readonly OrderType $type,
        public readonly OrderStatus $status,
        public readonly BigDecimal $quantity,
        public readonly ?BigDecimal $price,
        public readonly ?BigDecimal $stopPrice,
        public readonly BigDecimal $filledQuantity,
        public readonly BigDecimal $remainingQuantity,
        public readonly ?BigDecimal $averagePrice,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?\DateTimeImmutable $filledAt = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Point d’entrée unique : route vers Bitmart ou vers le format canonique.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (self::looksLikeBitmartFuturesPayload($data)) {
            return self::fromBitmartFuturesArray($data);
        }

        return self::fromCanonicalArray($data);
    }

    /**
     * Logique « générique » déjà utilisée ailleurs (autres providers, fallback placeOrder).
     *
     * @param array<string,mixed> $data
     */
    private static function fromCanonicalArray(array $data): self
    {
        return new self(
            orderId: (string) $data['order_id'],
            symbol: (string) $data['symbol'],
            side: OrderSide::from((string) $data['side']),
            type: OrderType::from((string) $data['type']),
            status: OrderStatus::from((string) $data['status']),
            quantity: BigDecimal::of((string) $data['quantity']),
            price: isset($data['price']) && $data['price'] !== null
                ? BigDecimal::of((string) $data['price'])
                : null,
            stopPrice: isset($data['stop_price']) && $data['stop_price'] !== null
                ? BigDecimal::of((string) $data['stop_price'])
                : null,
            filledQuantity: BigDecimal::of((string) ($data['filled_quantity'] ?? '0')),
            remainingQuantity: BigDecimal::of((string) ($data['remaining_quantity'] ?? $data['quantity'])),
            averagePrice: isset($data['average_price']) && $data['average_price'] !== null
                ? BigDecimal::of((string) $data['average_price'])
                : null,
            createdAt: new \DateTimeImmutable((string) $data['created_at']),
            updatedAt: isset($data['updated_at']) && $data['updated_at'] !== null
                ? new \DateTimeImmutable((string) $data['updated_at'])
                : null,
            filledAt: isset($data['filled_at']) && $data['filled_at'] !== null
                ? new \DateTimeImmutable((string) $data['filled_at'])
                : null,
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Heuristique pour reconnaître un payload Bitmart Futures V2
     * (order-detail, get-open-orders, order-history).
     *
     * @param array<string,mixed> $data
     */
    private static function looksLikeBitmartFuturesPayload(array $data): bool
    {
        // Bitmart futures met souvent account=futures / copy_trading
        if (isset($data['account']) && \in_array($data['account'], ['futures', 'copy_trading'], true)) {
            return true;
        }

        // position_mode est typique des réponses futures
        if (isset($data['position_mode'])) {
            return true;
        }

        // Bitmart utilise "state" alors que ton format canonique utilise "status"
        if (isset($data['state']) && !isset($data['status'])) {
            return true;
        }

        // size + deal_size : signature assez spécifique Bitmart
        if (isset($data['size']) && isset($data['deal_size'])) {
            return true;
        }

        return false;
    }

    /**
     * Mapping spécifique Bitmart Futures V2 → DTO.
     *
     * @param array<string,mixed> $data
     */
    private static function fromBitmartFuturesArray(array $data): self
    {
        $side   = self::mapBitmartSide($data['side'] ?? null);
        $type   = self::mapBitmartType($data['type'] ?? null);
        $status = self::mapBitmartStatus($data['state'] ?? null);

        $size     = (string) ($data['size'] ?? '0');
        $dealSize = (string) ($data['deal_size'] ?? '0');

        $quantity  = BigDecimal::of($size);
        $filled    = BigDecimal::of($dealSize);
        $remaining = $quantity->minus($filled);

        // Timestamps en millisecondes Unix
        $createdAt = isset($data['create_time'])
            ? (new \DateTimeImmutable('@' . (int) \floor(((int) $data['create_time']) / 1000)))
                ->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable();

        $updatedAt = isset($data['update_time'])
            ? (new \DateTimeImmutable('@' . (int) \floor(((int) $data['update_time']) / 1000)))
                ->setTimezone(new \DateTimeZone('UTC'))
            : null;

        $price = isset($data['price']) && $data['price'] !== ''
            ? BigDecimal::of((string) $data['price'])
            : null;

        $stopPrice = isset($data['trigger_price']) && $data['trigger_price'] !== ''
            ? BigDecimal::of((string) $data['trigger_price'])
            : null;

        $averagePrice = isset($data['deal_avg_price'])
        && $data['deal_avg_price'] !== ''
        && $data['deal_avg_price'] !== '0'
            ? BigDecimal::of((string) $data['deal_avg_price'])
            : null;

        return new self(
            orderId: (string) $data['order_id'],
            symbol: (string) $data['symbol'],
            side: $side,
            type: $type,
            status: $status,
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            filledQuantity: $filled,
            remainingQuantity: $remaining,
            averagePrice: $averagePrice,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            filledAt: null,
            // On garde le payload brut pour debug
            metadata: $data,
        );
    }

    /**
     * side Bitmart Futures (int) → OrderSide
     */
    private static function mapBitmartSide(int|string|null $side): OrderSide
    {
        $code = (int) $side;

        // À ajuster si ta sémantique BUY/SELL est différente
        return match ($code) {
            1, 4 => OrderSide::BUY,   // open_long, close_short
            2, 3 => OrderSide::SELL,  // open_short, close_long
            default => OrderSide::BUY,
        };
    }

    /**
     * type Bitmart (limit, market, planorder, ...) → OrderType
     */
    private static function mapBitmartType(?string $type): OrderType
    {
        $raw = (string) $type;

        // On tente direct si ton enum connaît déjà ces valeurs
        try {
            return OrderType::from($raw);
        } catch (\Throwable) {
            $normalized = \strtolower($raw);

            $fallback = match ($normalized) {
                'limit'    => 'limit',
                'market'   => 'market',
                'planorder' => 'limit', // planorder -> exécuté en limit
                default    => 'limit',
            };

            try {
                return OrderType::from($fallback);
            } catch (\Throwable) {
                // Filet de sécurité : premier case de l'enum
                return OrderType::cases()[0];
            }
        }
    }

    /**
     * state Bitmart (int) → OrderStatus
     */
    private static function mapBitmartStatus(int|string|null $state): OrderStatus
    {
        $code = (int) $state;

        // À ajuster si ton enum a d'autres valeurs
        $value = match ($code) {
            1 => 'pending',            // new
            2 => 'partially_filled',
            3 => 'filled',
            4 => 'canceled',
            5 => 'rejected',           // ou 'failed' selon ton enum
            default => 'pending',
        };

        try {
            return OrderStatus::from($value);
        } catch (\Throwable) {
            try {
                return OrderStatus::from('pending');
            } catch (\Throwable) {
                // Dernier recours : premier case de l'enum
                return OrderStatus::cases()[0];
            }
        }
    }
}
