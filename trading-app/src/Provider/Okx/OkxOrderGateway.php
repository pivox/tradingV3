<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderInterface;

final class OkxOrderGateway implements OrderProviderInterface
{
    /**
     * @param array<string, mixed> $options
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
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        throw $this->readNotImplemented(__METHOD__);
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        throw $this->readNotImplemented(__METHOD__);
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrdersOrFail(?string $symbol = null): array
    {
        throw $this->readNotImplemented(__METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        throw $this->readNotImplemented(__METHOD__);
    }

    public function cancelAllOrders(string $symbol): bool
    {
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        throw $this->readNotImplemented(__METHOD__);
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function getProviderName(): string
    {
        return 'OKX';
    }

    private function readNotImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_order_read_not_implemented', $operation);
    }

    private function writeNotImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_order_write_not_implemented', $operation);
    }
}
