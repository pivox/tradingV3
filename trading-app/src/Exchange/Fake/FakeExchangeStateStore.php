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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FakeExchangeStateStore
{
    private ?string $stateFile;

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

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/fake_exchange_state.dat')]
        ?string $stateFile = null,
    )
    {
        $this->stateFile = $stateFile;
        if (!$this->restore()) {
            $this->reset();
        }
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
        $this->persist();
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
        $this->persist();
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
        $this->persist();
    }

    public function removePosition(string $symbol, ExchangePositionSide $side): void
    {
        unset($this->positions[$this->positionKey($symbol, $side)]);
        $this->persist();
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
        $this->persist();
    }

    public function appendEvent(FakeExchangeEvent $event): void
    {
        $payload = $event->payload;
        if (!\array_key_exists('event_sequence', $event->payload)) {
            $payload = ['event_sequence' => \count($this->events) + 1] + $payload;
        }

        $orderId = $payload['order_id'] ?? null;
        if (!\array_key_exists('order_snapshot', $payload) && \is_scalar($orderId)) {
            $order = $this->getOrder((string)$orderId);
            if ($order instanceof ExchangeOrderDto) {
                $payload['order_snapshot'] = $this->orderSnapshot($order);
            }
        }
        $orderSnapshot = $payload['order_snapshot'] ?? null;
        if (
            !\array_key_exists('position_snapshot', $payload)
            && \is_array($orderSnapshot)
            && \in_array($event->type, ['position.opened', 'position.updated'], true)
            && \is_string($orderSnapshot['position_side'] ?? null)
        ) {
            try {
                $position = $this->getPosition($event->symbol, ExchangePositionSide::from($orderSnapshot['position_side']));
            } catch (\ValueError) {
                $position = null;
            }
            if ($position instanceof ExchangePositionDto) {
                $payload['position_snapshot'] = $this->positionSnapshot($position);
            }
        }

        $event = new FakeExchangeEvent($event->type, $event->symbol, $event->occurredAt, $payload);
        $this->events[] = $event;
        $this->persist();
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
        $this->persist();
    }

    public function consumeProtectionRejectionFlag(): bool
    {
        if (!$this->rejectNextProtectionOrder) {
            return false;
        }

        $this->rejectNextProtectionOrder = false;
        $this->persist();

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

    /**
     * @return array<string,mixed>
     */
    private function orderSnapshot(ExchangeOrderDto $order): array
    {
        return [
            'exchange' => $order->exchange->value,
            'market_type' => $order->marketType->value,
            'symbol' => $order->symbol,
            'exchange_order_id' => $order->exchangeOrderId,
            'client_order_id' => $order->clientOrderId,
            'side' => $order->side->value,
            'position_side' => $order->positionSide?->value,
            'order_type' => $order->orderType->value,
            'status' => $order->status->value,
            'quantity' => $order->quantity,
            'filled_quantity' => $order->filledQuantity,
            'remaining_quantity' => $order->remainingQuantity,
            'price' => $order->price,
            'average_price' => $order->averagePrice,
            'stop_price' => $order->stopPrice,
            'reduce_only' => $order->reduceOnly,
            'post_only' => $order->postOnly,
            'time_in_force' => $order->timeInForce?->value,
            'created_at' => $order->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $order->updatedAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $order->metadata,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function positionSnapshot(ExchangePositionDto $position): array
    {
        return [
            'exchange' => $position->exchange->value,
            'market_type' => $position->marketType->value,
            'symbol' => $position->symbol,
            'side' => $position->side->value,
            'size' => $position->size,
            'entry_price' => $position->entryPrice,
            'mark_price' => $position->markPrice,
            'unrealized_pnl' => $position->unrealizedPnl,
            'realized_pnl' => $position->realizedPnl,
            'margin' => $position->margin,
            'leverage' => $position->leverage,
            'opened_at' => $position->openedAt?->format(\DateTimeInterface::ATOM),
            'updated_at' => $position->updatedAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $position->metadata,
        ];
    }

    private function restore(): bool
    {
        if ($this->stateFile === null || !is_file($this->stateFile)) {
            return false;
        }

        $raw = file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            return false;
        }

        $state = @unserialize($raw, ['allowed_classes' => true]);
        if (!\is_array($state)) {
            return false;
        }

        $this->nextOrderSequence = \is_int($state['nextOrderSequence'] ?? null) ? $state['nextOrderSequence'] : 1;
        $this->orders = \is_array($state['orders'] ?? null) ? $state['orders'] : [];
        $this->clientOrderIndex = \is_array($state['clientOrderIndex'] ?? null) ? $state['clientOrderIndex'] : [];
        $this->positions = \is_array($state['positions'] ?? null) ? $state['positions'] : [];
        $this->balances = \is_array($state['balances'] ?? null) ? $state['balances'] : [];
        $this->orderBooks = \is_array($state['orderBooks'] ?? null) ? $state['orderBooks'] : [];
        $this->events = \is_array($state['events'] ?? null) ? $state['events'] : [];
        $this->rejectNextProtectionOrder = \is_bool($state['rejectNextProtectionOrder'] ?? null)
            ? $state['rejectNextProtectionOrder']
            : false;

        return true;
    }

    private function persist(): void
    {
        if ($this->stateFile === null) {
            return;
        }

        $directory = \dirname($this->stateFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($this->stateFile, serialize([
            'nextOrderSequence' => $this->nextOrderSequence,
            'orders' => $this->orders,
            'clientOrderIndex' => $this->clientOrderIndex,
            'positions' => $this->positions,
            'balances' => $this->balances,
            'orderBooks' => $this->orderBooks,
            'events' => $this->events,
            'rejectNextProtectionOrder' => $this->rejectNextProtectionOrder,
        ]), \LOCK_EX);
    }
}
