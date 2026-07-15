<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

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
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangeOrderPartiallyFilled;
use App\Exchange\Event\ExchangeOrderUpdated;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Okx\OkxFillId;
use App\Exchange\Okx\OkxInstrumentResolver;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;

final readonly class OkxPrivateRestSnapshotReconciler
{
    private const SOURCE = 'okx_private_rest_snapshot';

    public function __construct(
        private ExchangeEventBus $eventBus,
        private ExchangeLocalProjectionStoreInterface $projectionStore,
    ) {
    }

    public function reconcile(OkxPrivateRestSnapshot $snapshot): int
    {
        if (!$snapshot->complete) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_incomplete');
        }

        $orders = $this->unique($snapshot->openOrders, fn (OrderSnapshotItem $item): string => $this->required($item->orderId));
        $this->assertUniqueClientOrderIds($orders);
        $positions = $this->unique(
            $snapshot->positions,
            fn (PositionSnapshotItem $item): string => $this->symbol($item->symbol) . ':' . $this->positionSide($item->side)->value,
        );
        $fills = $this->unique($snapshot->fills, fn (FillSnapshotItem $item): string => $this->fillId($item));

        $events = [];
        foreach ($orders as $order) {
            $events[] = $this->orderEvent($order);
        }
        foreach ($this->missingLocalOrders($orders) as $order) {
            $events[] = new ExchangeOrderUpdated(
                new ExchangeOrderDto(
                    $order->exchange, $order->marketType, $order->symbol, $order->exchangeOrderId, $order->clientOrderId,
                    $order->side, $order->positionSide, $order->orderType, ExchangeOrderStatus::UNKNOWN,
                    $order->quantity, $order->filledQuantity, $order->remainingQuantity, $order->price,
                    $order->averagePrice, $order->stopPrice, $order->reduceOnly, $order->postOnly,
                    $order->timeInForce, $order->createdAt, $snapshot->observedAt,
                    $order->metadata + ['quality_flag' => 'snapshot_order_missing'],
                ),
                $snapshot->observedAt,
                self::sourcePayload() + ['reason' => 'snapshot_order_missing'],
            );
        }
        foreach ($positions as $position) {
            $events[] = $this->positionEvent($position, $snapshot->observedAt);
        }
        foreach ($this->missingLocalPositions($positions) as $missingPosition) {
            $events[] = new ExchangePositionClosed(
                exchange: Exchange::OKX,
                marketType: MarketType::PERPETUAL,
                symbol: $missingPosition['symbol'],
                side: $missingPosition['side'],
                size: 0.0,
                position: null,
                occurredAt: $snapshot->observedAt,
                payload: self::sourcePayload() + ['reason' => 'missing_from_rest_position_snapshot'],
            );
        }
        foreach ($fills as $fill) {
            $events[] = $this->fillEvent($fill);
        }

        return $this->eventBus->publishMany($events);
    }

    private function orderEvent(OrderSnapshotItem $item): ExchangeEventInterface
    {
        $status = ExchangeOrderStatus::tryFrom($item->status);
        $side = ExchangeOrderSide::tryFrom($item->side);
        $type = $this->orderType($item->type);
        if (!$status instanceof ExchangeOrderStatus
            || !$side instanceof ExchangeOrderSide
            || !\in_array($status, [
                ExchangeOrderStatus::PENDING,
                ExchangeOrderStatus::OPEN,
                ExchangeOrderStatus::PARTIALLY_FILLED,
            ], true)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        $quantityDecimal = $this->decimal($item->quantity);
        $filledDecimal = $this->decimal($item->filledQuantity);
        $remainingDecimal = $this->decimal($item->remainingQuantity);
        if ($quantityDecimal->compareTo(BigDecimal::zero()) <= 0
            || $filledDecimal->compareTo(BigDecimal::zero()) < 0
            || $remainingDecimal->compareTo(BigDecimal::zero()) < 0
            || $filledDecimal->compareTo($quantityDecimal) > 0
            || $filledDecimal->plus($remainingDecimal)->compareTo($quantityDecimal) !== 0
            || ($status === ExchangeOrderStatus::PARTIALLY_FILLED
                && ($filledDecimal->compareTo(BigDecimal::zero()) <= 0
                    || $remainingDecimal->compareTo(BigDecimal::zero()) <= 0))) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }
        $quantity = $this->finiteFloat($quantityDecimal);
        $filledQuantity = $this->finiteFloat($filledDecimal);
        $remainingQuantity = $this->finiteFloat($remainingDecimal);

        $order = new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $this->symbol($item->symbol),
            exchangeOrderId: $this->required($item->orderId),
            clientOrderId: $this->nullableRequired($item->clientOrderId),
            side: $side,
            positionSide: $this->nullablePositionSide($item->positionSide),
            orderType: $type,
            status: $status,
            quantity: $quantity,
            filledQuantity: $filledQuantity,
            remainingQuantity: $remainingQuantity,
            price: $this->nullablePositive($item->price),
            averagePrice: $this->nullablePositive($item->averagePrice),
            stopPrice: $this->nullablePositive($item->stopPrice),
            reduceOnly: $item->reduceOnly,
            postOnly: $item->postOnly,
            timeInForce: $this->nullableTimeInForce($item->timeInForce),
            createdAt: $item->createdAt,
            updatedAt: $item->updatedAt,
            metadata: array_filter(
                self::sourcePayload() + [
                    'open_type' => $this->nullableRequired($item->openType),
                    'leverage' => $this->nullablePositiveString($item->leverage),
                    'quantity_decimal' => $item->quantity,
                    'filled_quantity_decimal' => $item->filledQuantity,
                    'remaining_quantity_decimal' => $item->remainingQuantity,
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
        );

        $occurredAt = $item->updatedAt ?? $item->createdAt;

        return $status === ExchangeOrderStatus::PARTIALLY_FILLED
            ? new ExchangeOrderPartiallyFilled($order, $occurredAt, self::sourcePayload())
            : new ExchangeOrderUpdated($order, $occurredAt, self::sourcePayload());
    }

    private function positionEvent(PositionSnapshotItem $item, \DateTimeImmutable $observedAt): ExchangePositionUpdated
    {
        $side = $this->positionSide($item->side);
        $position = new ExchangePositionDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $this->symbol($item->symbol),
            side: $side,
            size: $this->positive($item->size),
            entryPrice: $this->positive($item->entryPrice),
            markPrice: $this->positive($item->markPrice),
            unrealizedPnl: null,
            realizedPnl: null,
            margin: null,
            leverage: null,
            openedAt: $item->openedAt,
            updatedAt: $observedAt,
            metadata: self::sourcePayload(),
        );

        return new ExchangePositionUpdated(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $position->symbol,
            side: $side,
            size: $position->size,
            position: $position,
            occurredAt: $observedAt,
            payload: self::sourcePayload(),
        );
    }

    private function fillEvent(FillSnapshotItem $item): ExchangeFillReceived
    {
        if ($item->exchange !== Exchange::OKX->value || !$item->occurredAt instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }
        $side = ExchangeOrderSide::tryFrom($item->side);
        $positionSide = ExchangePositionSide::tryFrom($item->positionSide);
        if (!$side instanceof ExchangeOrderSide || !$positionSide instanceof ExchangePositionSide) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return new ExchangeFillReceived(new ExchangeFillDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $this->symbol($item->symbol),
            exchangeOrderId: $this->required($item->orderId),
            clientOrderId: $this->nullableRequired($item->clientOrderId),
            fillId: $this->fillId($item),
            side: $side,
            positionSide: $positionSide,
            quantity: $this->positive($item->size),
            price: $this->positive($item->price),
            fee: $this->nullableNumber($item->fee),
            feeCurrency: $this->nullableRequired($item->feeCurrency),
            filledAt: $item->occurredAt,
            metadata: self::sourcePayload(),
        ), self::sourcePayload());
    }

    /**
     * @param list<PositionSnapshotItem> $positions
     * @return array<int,array{symbol: string, side: ExchangePositionSide, size: float}>
     */
    private function missingLocalPositions(array $positions): array
    {
        $snapshotKeys = [];
        foreach ($positions as $position) {
            $snapshotKeys[$this->symbol($position->symbol) . ':' . $this->positionSide($position->side)->value] = true;
        }

        $missing = [];
        foreach ($this->projectionStore->openPositions(Exchange::OKX, MarketType::PERPETUAL) as $localPosition) {
            $symbol = $this->symbol($localPosition['symbol']);
            if (!is_finite($localPosition['size']) || $localPosition['size'] <= 0.0) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
            }
            if (!isset($snapshotKeys[$symbol . ':' . $localPosition['side']->value])) {
                $missing[] = $localPosition;
            }
        }

        return $missing;
    }

    /**
     * @param list<OrderSnapshotItem> $orders
     * @return list<ExchangeOrderDto>
     */
    private function missingLocalOrders(array $orders): array
    {
        $keys = [];
        foreach ($orders as $order) {
            $keys[$this->required($order->orderId)] = true;
            if ($order->clientOrderId !== null) $keys[$order->clientOrderId] = true;
        }
        return array_values(array_filter(
            $this->projectionStore->openOrders(Exchange::OKX, MarketType::PERPETUAL),
            fn (ExchangeOrderDto $order): bool => !isset($keys[$order->exchangeOrderId])
                && ($order->clientOrderId === null || !isset($keys[$order->clientOrderId])),
        ));
    }

    /**
     * @template T of object
     * @param list<T> $items
     * @param callable(T): string $key
     * @return list<T>
     */
    private function unique(array $items, callable $key): array
    {
        $unique = [];
        foreach ($items as $item) {
            $itemKey = $key($item);
            if (isset($unique[$itemKey])) {
                if (serialize($unique[$itemKey]) !== serialize($item)) {
                    throw new \InvalidArgumentException('okx_private_rest_snapshot_duplicate_conflict');
                }
                continue;
            }
            $unique[$itemKey] = $item;
        }

        return array_values($unique);
    }

    /**
     * @param list<OrderSnapshotItem> $orders
     */
    private function assertUniqueClientOrderIds(array $orders): void
    {
        $exchangeOrderIdsByClientOrderId = [];
        foreach ($orders as $order) {
            if ($order->clientOrderId === null) {
                continue;
            }

            $clientOrderId = $order->clientOrderId;
            $exchangeOrderId = $this->required($order->orderId);
            if (isset($exchangeOrderIdsByClientOrderId[$clientOrderId])
                && $exchangeOrderIdsByClientOrderId[$clientOrderId] !== $exchangeOrderId) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_duplicate_conflict');
            }

            $exchangeOrderIdsByClientOrderId[$clientOrderId] = $exchangeOrderId;
        }
    }

    private function positionSide(string $value): ExchangePositionSide
    {
        $side = ExchangePositionSide::tryFrom($value);
        if (!$side instanceof ExchangePositionSide) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $side;
    }

    private function nullablePositionSide(?string $value): ?ExchangePositionSide
    {
        return $value === null ? null : $this->positionSide($value);
    }

    private function nullableTimeInForce(?string $value): ?ExchangeTimeInForce
    {
        if ($value === null) {
            return null;
        }
        $timeInForce = ExchangeTimeInForce::tryFrom($value);
        if (!$timeInForce instanceof ExchangeTimeInForce) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $timeInForce;
    }

    private function fillId(FillSnapshotItem $item): string
    {
        $instrumentId = $this->required($item->instrumentId);
        $resolver = new OkxInstrumentResolver();
        if ($resolver->instId($instrumentId) !== $instrumentId
            || $resolver->symbol($instrumentId) !== $this->symbol($item->symbol)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        $fillId = OkxFillId::fromTradeId($instrumentId, $this->required($item->tradeId));
        if ($fillId === null) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $fillId;
    }

    private function orderType(string $value): ExchangeOrderType
    {
        if ($value === 'stop') {
            return ExchangeOrderType::TRIGGER;
        }

        $type = ExchangeOrderType::tryFrom($value);
        if (!$type instanceof ExchangeOrderType) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $type;
    }

    private function symbol(string $value): string
    {
        return strtoupper($this->required($value));
    }

    private function required(string $value): string
    {
        if ($value === '' || trim($value) !== $value) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $value;
    }

    private function nullableRequired(?string $value): ?string
    {
        return $value === null ? null : $this->required($value);
    }

    private function positive(string $value): float
    {
        $number = $this->number($value);
        if ($number <= 0.0) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $number;
    }

    private function nullablePositive(?string $value): ?float
    {
        return $value === null ? null : $this->positive($value);
    }

    private function nullablePositiveString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $this->positive($value);

        return $value;
    }

    private function nullableNumber(?string $value): ?float
    {
        return $value === null ? null : $this->number($value);
    }

    private function number(string $value): float
    {
        if ($value === '' || trim($value) !== $value || !is_numeric($value)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $number;
    }

    private function decimal(string $value): BigDecimal
    {
        if ($value === '' || trim($value) !== $value) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        try {
            return BigDecimal::of($value);
        } catch (NumberFormatException) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }
    }

    private function finiteFloat(BigDecimal $value): float
    {
        $number = $value->toFloat();
        if (!is_finite($number)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $number;
    }

    /** @return array{source: string} */
    private static function sourcePayload(): array
    {
        return ['source' => self::SOURCE];
    }
}
