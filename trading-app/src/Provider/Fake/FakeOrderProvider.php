<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderInterface;

/**
 * Minimal fake order provider modelling an empty exchange.
 *
 * Order placement is a no-op that returns null (no order is created), and all
 * lookups return empty/neutral results without throwing. This keeps the FAKE
 * context safe for the orchestrator demo path (exchange=fake): the open-state
 * snapshot is always empty and no live order is ever submitted.
 */
final class FakeOrderProvider implements OrderProviderInterface
{
    /**
     * No-op placement: the fake exchange never accepts orders.
     *
     * @param array<string, mixed> $options
     */
    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = []
    ): ?OrderDto {
        return null;
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        // Nothing to cancel on the fake exchange; treat as a successful no-op.
        return true;
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        return null;
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        return [];
    }

    public function cancelAllOrders(string $symbol): bool
    {
        // No open orders to cancel; treat as a successful no-op.
        return true;
    }

    /**
     * Returns a neutral top-of-book quote (zero bid/ask) so callers never crash.
     * The fake exchange has no real market data.
     */
    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return new SymbolBidAskDto(
            symbol: $symbol,
            bid: 0.0,
            ask: 0.0,
            timestamp: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        // Accept any leverage request without side effects.
        return true;
    }

    public function getProviderName(): string
    {
        return 'Fake';
    }
}
