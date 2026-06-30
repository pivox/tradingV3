<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderInterface;

final class HyperliquidExecutionGateway implements OrderProviderInterface
{
    /**
     * @param array<string,mixed> $options
     */
    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = [],
    ): ?OrderDto {
        throw $this->notReady(__METHOD__);
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        throw $this->notReady(__METHOD__);
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrdersOrFail(?string $symbol = null): array
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return OrderDto[]
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        throw $this->notReady(__METHOD__);
    }

    public function cancelAllOrders(string $symbol): bool
    {
        throw $this->notReady(__METHOD__);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        throw $this->notReady(__METHOD__);
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        throw $this->notReady(__METHOD__);
    }

    public function healthCheck(): bool
    {
        return false;
    }

    public function getProviderName(): string
    {
        return 'Hyperliquid';
    }

    private function notReady(string $operation): HyperliquidProviderNotReadyException
    {
        return new HyperliquidProviderNotReadyException('hyperliquid_execution_not_ready', $operation);
    }
}
