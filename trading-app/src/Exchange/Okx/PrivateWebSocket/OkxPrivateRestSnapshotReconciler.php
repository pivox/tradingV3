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
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangeOrderPartiallyFilled;
use App\Exchange\Event\ExchangeOrderUpdated;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;

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
        $positions = $this->unique(
            $snapshot->positions,
            fn (PositionSnapshotItem $item): string => $this->symbol($item->symbol) . ':' . $this->positionSide($item->side)->value,
        );
        $fills = $this->unique($snapshot->fills, fn (FillSnapshotItem $item): string => $this->required($item->tradeId));

        $events = [];
        foreach ($orders as $order) {
            $events[] = $this->orderEvent($order);
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
        $quantity = $this->positive($item->quantity);
        $filledQuantity = $this->nonNegative($item->filledQuantity);
        $remainingQuantity = $this->nonNegative($item->remainingQuantity);
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

        $order = new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $this->symbol($item->symbol),
            exchangeOrderId: $this->required($item->orderId),
            clientOrderId: null,
            side: $side,
            positionSide: null,
            orderType: $type,
            status: $status,
            quantity: $quantity,
            filledQuantity: $filledQuantity,
            remainingQuantity: $remainingQuantity,
            price: $this->nullablePositive($item->price),
            averagePrice: null,
            stopPrice: $this->nullablePositive($item->stopPrice),
            reduceOnly: false,
            postOnly: false,
            timeInForce: null,
            createdAt: $item->createdAt,
            updatedAt: null,
            metadata: self::sourcePayload(),
        );

        return $status === ExchangeOrderStatus::PARTIALLY_FILLED
            ? new ExchangeOrderPartiallyFilled($order, $item->createdAt, self::sourcePayload())
            : new ExchangeOrderUpdated($order, $item->createdAt, self::sourcePayload());
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
            fillId: $this->required($item->tradeId),
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

    private function positionSide(string $value): ExchangePositionSide
    {
        $side = ExchangePositionSide::tryFrom($value);
        if (!$side instanceof ExchangePositionSide) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $side;
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

    private function nonNegative(string $value): float
    {
        $number = $this->number($value);
        if ($number < 0.0) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $number;
    }

    private function nullablePositive(?string $value): ?float
    {
        return $value === null ? null : $this->positive($value);
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

    /** @return array{source: string} */
    private static function sourcePayload(): array
    {
        return ['source' => self::SOURCE];
    }
}
