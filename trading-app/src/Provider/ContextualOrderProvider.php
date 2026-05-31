<?php

declare(strict_types=1);

namespace App\Provider;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\ContextualOrderProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderDecoratorInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Provider\Context\ExchangeContext;

final readonly class ContextualOrderProvider implements OrderProviderDecoratorInterface
{
    public function __construct(
        private OrderProviderInterface $inner,
        private ExchangeContext $context,
    ) {
    }

    public function innerOrderProvider(): OrderProviderInterface
    {
        return $this->inner;
    }

    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = [],
    ): ?OrderDto {
        return $this->inner->placeOrder(
            symbol: $symbol,
            side: $side,
            type: $type,
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            options: $this->withContextOptions($options),
        );
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        if ($this->inner instanceof ContextualOrderProviderInterface) {
            return $this->inner->cancelOrder($symbol, $orderId, $this->context);
        }

        return $this->inner->cancelOrder($symbol, $orderId);
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        if ($this->inner instanceof ContextualOrderProviderInterface) {
            return $this->inner->getOrder($symbol, $orderId, $this->context);
        }

        return $this->inner->getOrder($symbol, $orderId);
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        if ($this->inner instanceof ContextualOrderProviderInterface) {
            return $this->inner->getOpenOrders($symbol, $this->context);
        }

        return $this->inner->getOpenOrders($symbol);
    }

    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        if ($this->inner instanceof ContextualOrderProviderInterface) {
            return $this->inner->getOrderHistory($symbol, $limit, $this->context);
        }

        return $this->inner->getOrderHistory($symbol, $limit);
    }

    public function cancelAllOrders(string $symbol): bool
    {
        return $this->inner->cancelAllOrders($symbol);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        return $this->inner->submitLeverage($symbol, $leverage, $openType);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function withContextOptions(array $options): array
    {
        $options['exchange'] ??= $this->context->exchange->value;
        $options['market_type'] ??= $this->context->marketType->value;

        return $options;
    }
}
