<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangePositionSide;

final class FakeExchangeStateStore
{
    private int $nextOrderSequence = 1;

    /** @var array<string, ExchangeOrderDto> */
    private array $orders = [];

    /** @var array<string, string> */
    private array $clientOrderIndex = [];

    /** @var array<string, ExchangePositionDto> */
    private array $positions = [];

    /** @var array<string, ExchangeBalanceDto> */
    private array $balances = [];

    /** @var array<string, array{bid: float, ask: float}> */
    private array $orderBooks = [];

    /** @var FakeExchangeEvent[] */
    private array $events = [];

    private bool $rejectNextProtectionOrder = false;

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->nextOrderSequence = 1;
        $this->orders = [];
        $this->clientOrderIndex = [];
        $this->positions = [];
        $this->orderBooks = [];
        $this->events = [];
        $this->rejectNextProtectionOrder = false;
        $this->balances = [
            'USDT' => new ExchangeBalanceDto(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                currency: 'USDT',
                available: 100000.0,
                total: 100000.0,
                equity: 100000.0,
                unrealizedPnl: 0.0,
                metadata: ['source' => 'fake_exchange'],
            ),
        ];
    }

    public function nextOrderId(): string
    {
        return sprintf('fake-%06d', $this->nextOrderSequence++);
    }

    public function saveOrder(ExchangeOrderDto $order): void
    {
        $this->orders[$order->exchangeOrderId] = $order;
        if ($order->clientOrderId !== null && trim($order->clientOrderId) !== '') {
            $this->clientOrderIndex[$this->clientOrderKey($order->symbol, $order->clientOrderId)] = $order->exchangeOrderId;
        }
    }

    public function getOrder(string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->orders[$exchangeOrderId] ?? null;
    }

    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrderDto
    {
        $orderId = $this->clientOrderIndex[$this->clientOrderKey($symbol, $clientOrderId)] ?? null;

        return $orderId !== null ? $this->getOrder($orderId) : null;
    }

    public function findActiveOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrderDto
    {
        $order = $this->getOrderByClientOrderId($symbol, $clientOrderId);
        if (!$order instanceof ExchangeOrderDto) {
            return null;
        }

        return $this->isActiveStatus($order->status) ? $order : null;
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->orders,
            fn (ExchangeOrderDto $order): bool => $this->isActiveStatus($order->status)
                && ($symbol === null || $order->symbol === $symbol),
        ));
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOrders(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->orders,
            fn (ExchangeOrderDto $order): bool => $symbol === null || $order->symbol === $symbol,
        ));
    }

    public function savePosition(ExchangePositionDto $position): void
    {
        $this->positions[$this->positionKey($position->symbol, $position->side)] = $position;
    }

    public function removePosition(string $symbol, ExchangePositionSide $side): void
    {
        unset($this->positions[$this->positionKey($symbol, $side)]);
    }

    public function getPosition(string $symbol, ExchangePositionSide $side): ?ExchangePositionDto
    {
        return $this->positions[$this->positionKey($symbol, $side)] ?? null;
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->positions,
            fn (ExchangePositionDto $position): bool => $position->size > 0.0
                && ($symbol === null || $position->symbol === $symbol),
        ));
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return array_values($this->balances);
    }

    /**
     * @return array{bid: float, ask: float}
     */
    public function getOrderBookTop(string $symbol): array
    {
        $symbol = strtoupper($symbol);

        return $this->orderBooks[$symbol] ?? ['bid' => 24999.5, 'ask' => 25000.5];
    }

    public function setOrderBookTop(string $symbol, float $bid, float $ask): void
    {
        if ($bid <= 0.0 || $ask <= 0.0 || $bid >= $ask) {
            throw new \InvalidArgumentException('fake order book requires positive bid < ask');
        }

        $this->orderBooks[strtoupper($symbol)] = ['bid' => $bid, 'ask' => $ask];
    }

    public function appendEvent(FakeExchangeEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return FakeExchangeEvent[]
     */
    public function events(?string $type = null): array
    {
        if ($type === null) {
            return $this->events;
        }

        return array_values(array_filter(
            $this->events,
            fn (FakeExchangeEvent $event): bool => $event->type === $type,
        ));
    }

    public function rejectNextProtectionOrder(): void
    {
        $this->rejectNextProtectionOrder = true;
    }

    public function consumeProtectionRejectionFlag(): bool
    {
        if (!$this->rejectNextProtectionOrder) {
            return false;
        }

        $this->rejectNextProtectionOrder = false;

        return true;
    }

    public function openOrderCount(?string $symbol = null): int
    {
        return \count($this->getOpenOrders($symbol));
    }

    public function openPositionCount(?string $symbol = null): int
    {
        return \count($this->getOpenPositions($symbol));
    }

    private function clientOrderKey(string $symbol, string $clientOrderId): string
    {
        return strtoupper($symbol) . '::' . $clientOrderId;
    }

    private function positionKey(string $symbol, ExchangePositionSide $side): string
    {
        return strtoupper($symbol) . '::' . $side->value;
    }

    private function isActiveStatus(ExchangeOrderStatus $status): bool
    {
        return \in_array($status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }
}
