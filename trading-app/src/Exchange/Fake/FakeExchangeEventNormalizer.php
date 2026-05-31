<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeEventNormalizerInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderCancelled;
use App\Exchange\Event\ExchangeOrderCreated;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangeOrderPartiallyFilled;
use App\Exchange\Event\ExchangeOrderRejected;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionOpened;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Event\ExchangeProtectionOrderCreated;
use App\Exchange\Event\ExchangeProtectionOrderRejected;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_event_normalizer')]
final readonly class FakeExchangeEventNormalizer implements ExchangeEventNormalizerInterface
{
    public function __construct(
        private FakeExchangeStateStore $stateStore,
    ) {
    }

    public function supports(mixed $event): bool
    {
        return $event instanceof FakeExchangeEvent;
    }

    /**
     * @return ExchangeEventInterface[]
     */
    public function normalize(mixed $event): array
    {
        if (!$event instanceof FakeExchangeEvent) {
            return [];
        }

        $order = $this->orderFromEvent($event);

        return match ($event->type) {
            'order.created' => $order instanceof ExchangeOrderDto
                ? [new ExchangeOrderCreated($order, $event->occurredAt, $event->payload)]
                : [],
            'order.filled' => $order instanceof ExchangeOrderDto
                ? [
                    new ExchangeOrderFilled($order, $event->occurredAt, $event->payload),
                    new ExchangeFillReceived($this->fillFromEvent($event, $order), $event->payload),
                ]
                : [],
            'order.partially_filled' => $order instanceof ExchangeOrderDto
                ? [
                    new ExchangeOrderPartiallyFilled($order, $event->occurredAt, $event->payload),
                    new ExchangeFillReceived($this->fillFromEvent($event, $order), $event->payload),
                ]
                : [],
            'order.cancelled', 'order.expired' => $order instanceof ExchangeOrderDto
                ? [new ExchangeOrderCancelled($order, $event->occurredAt, $event->payload)]
                : [],
            'order.rejected' => $order instanceof ExchangeOrderDto
                ? [new ExchangeOrderRejected($order, $event->occurredAt, $event->payload)]
                : [],
            'protection_order.created' => $order instanceof ExchangeOrderDto
                ? [new ExchangeProtectionOrderCreated($order, $event->occurredAt, $event->payload)]
                : [],
            'protection_order.rejected' => $order instanceof ExchangeOrderDto
                ? [new ExchangeProtectionOrderRejected($order, $event->occurredAt, $event->payload)]
                : [],
            'position.opened' => $this->positionEvent($event, $order, ExchangePositionOpened::class),
            'position.updated' => $this->positionEvent($event, $order, ExchangePositionUpdated::class),
            'position.closed' => $this->positionEvent($event, $order, ExchangePositionClosed::class),
            default => [],
        };
    }

    private function orderFromEvent(FakeExchangeEvent $event): ?ExchangeOrderDto
    {
        if (\is_array($event->payload['order_snapshot'] ?? null)) {
            return $this->orderFromSnapshot($event->payload['order_snapshot']);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function orderFromSnapshot(array $snapshot): ?ExchangeOrderDto
    {
        try {
            return new ExchangeOrderDto(
                exchange: Exchange::from((string)$snapshot['exchange']),
                marketType: MarketType::from((string)$snapshot['market_type']),
                symbol: (string)$snapshot['symbol'],
                exchangeOrderId: (string)$snapshot['exchange_order_id'],
                clientOrderId: isset($snapshot['client_order_id']) ? (string)$snapshot['client_order_id'] : null,
                side: ExchangeOrderSide::from((string)$snapshot['side']),
                positionSide: isset($snapshot['position_side']) && $snapshot['position_side'] !== null
                    ? ExchangePositionSide::from((string)$snapshot['position_side'])
                    : null,
                orderType: ExchangeOrderType::from((string)$snapshot['order_type']),
                status: ExchangeOrderStatus::from((string)$snapshot['status']),
                quantity: (float)$snapshot['quantity'],
                filledQuantity: (float)$snapshot['filled_quantity'],
                remainingQuantity: (float)$snapshot['remaining_quantity'],
                price: isset($snapshot['price']) && $snapshot['price'] !== null ? (float)$snapshot['price'] : null,
                averagePrice: isset($snapshot['average_price']) && $snapshot['average_price'] !== null ? (float)$snapshot['average_price'] : null,
                stopPrice: isset($snapshot['stop_price']) && $snapshot['stop_price'] !== null ? (float)$snapshot['stop_price'] : null,
                reduceOnly: (bool)$snapshot['reduce_only'],
                postOnly: (bool)$snapshot['post_only'],
                timeInForce: isset($snapshot['time_in_force']) && $snapshot['time_in_force'] !== null
                    ? ExchangeTimeInForce::from((string)$snapshot['time_in_force'])
                    : null,
                createdAt: new \DateTimeImmutable((string)$snapshot['created_at']),
                updatedAt: isset($snapshot['updated_at']) && $snapshot['updated_at'] !== null
                    ? new \DateTimeImmutable((string)$snapshot['updated_at'])
                    : null,
                metadata: \is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function fillFromEvent(FakeExchangeEvent $event, ExchangeOrderDto $order): ExchangeFillDto
    {
        $fillQuantity = isset($event->payload['fill_quantity']) && is_numeric($event->payload['fill_quantity'])
            ? (float)$event->payload['fill_quantity']
            : max(0.0, $order->filledQuantity);
        $fillPrice = isset($event->payload['fill_price']) && is_numeric($event->payload['fill_price'])
            ? (float)$event->payload['fill_price']
            : ($order->averagePrice ?? $order->price ?? 0.0);

        return new ExchangeFillDto(
            exchange: $order->exchange,
            marketType: $order->marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            fillId: $this->fillId($event, $order, $fillQuantity, $fillPrice),
            side: $order->side,
            positionSide: $order->positionSide,
            quantity: $fillQuantity,
            price: $fillPrice,
            fee: null,
            feeCurrency: null,
            filledAt: $event->occurredAt,
            metadata: ['source' => 'fake_exchange_ws', 'order_status' => $order->status->value],
        );
    }

    /**
     * @param class-string<ExchangePositionOpened|ExchangePositionUpdated|ExchangePositionClosed> $eventClass
     * @return ExchangeEventInterface[]
     */
    private function positionEvent(FakeExchangeEvent $event, ?ExchangeOrderDto $order, string $eventClass): array
    {
        $side = $order?->positionSide;
        if (!$side instanceof ExchangePositionSide) {
            return [];
        }

        $position = \is_array($event->payload['position_snapshot'] ?? null)
            ? $this->positionFromSnapshot($event->payload['position_snapshot'])
            : null;
        $size = isset($event->payload['size']) && is_numeric($event->payload['size'])
            ? (float)$event->payload['size']
            : ($position?->size ?? 0.0);

        return [new $eventClass(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $event->symbol,
            side: $side,
            size: $eventClass === ExchangePositionClosed::class ? 0.0 : $size,
            position: $eventClass === ExchangePositionClosed::class ? null : $position,
            occurredAt: $event->occurredAt,
            payload: $event->payload,
        )];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function positionFromSnapshot(array $snapshot): ?ExchangePositionDto
    {
        try {
            return new ExchangePositionDto(
                exchange: Exchange::from((string)$snapshot['exchange']),
                marketType: MarketType::from((string)$snapshot['market_type']),
                symbol: (string)$snapshot['symbol'],
                side: ExchangePositionSide::from((string)$snapshot['side']),
                size: (float)$snapshot['size'],
                entryPrice: (float)$snapshot['entry_price'],
                markPrice: isset($snapshot['mark_price']) && $snapshot['mark_price'] !== null ? (float)$snapshot['mark_price'] : null,
                unrealizedPnl: isset($snapshot['unrealized_pnl']) && $snapshot['unrealized_pnl'] !== null ? (float)$snapshot['unrealized_pnl'] : null,
                realizedPnl: isset($snapshot['realized_pnl']) && $snapshot['realized_pnl'] !== null ? (float)$snapshot['realized_pnl'] : null,
                margin: isset($snapshot['margin']) && $snapshot['margin'] !== null ? (float)$snapshot['margin'] : null,
                leverage: isset($snapshot['leverage']) && $snapshot['leverage'] !== null ? (float)$snapshot['leverage'] : null,
                openedAt: isset($snapshot['opened_at']) && $snapshot['opened_at'] !== null ? new \DateTimeImmutable((string)$snapshot['opened_at']) : null,
                updatedAt: isset($snapshot['updated_at']) && $snapshot['updated_at'] !== null ? new \DateTimeImmutable((string)$snapshot['updated_at']) : null,
                metadata: \is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function fillId(FakeExchangeEvent $event, ExchangeOrderDto $order, float $quantity, float $price): string
    {
        return 'fake-fill-' . substr(hash('sha256', implode(':', [
            (string)($event->payload['event_sequence'] ?? ''),
            $event->type,
            $order->exchangeOrderId,
            $event->occurredAt->format('U.u'),
            (string)$quantity,
            (string)$price,
        ])), 0, 32);
    }
}
