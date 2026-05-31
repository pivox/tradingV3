<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use Psr\Clock\ClockInterface;

final readonly class FakeExchangeMatchingEngine
{
    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private FakeExchangeOrderBook $orderBook,
        private ClockInterface $clock,
    ) {
    }

    public function submit(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->assertRequestContext($request);
        $this->assertRequestIntent($request);

        $existing = $this->stateStore->findActiveOrderByClientOrderId($request->symbol, $request->clientOrderId);
        if ($existing instanceof ExchangeOrderDto) {
            return new PlaceOrderResult(
                accepted: true,
                symbol: $existing->symbol,
                clientOrderId: $request->clientOrderId,
                exchangeOrderId: $existing->exchangeOrderId,
                status: $existing->status,
                submittedAt: $this->clock->now(),
                order: $existing,
                metadata: ['idempotent_replay' => true],
            );
        }

        if ($request->postOnly && $this->wouldCross($request)) {
            $order = $this->buildOrder($request, ExchangeOrderStatus::REJECTED, [
                'reason' => 'post_only_would_cross',
            ]);
            $this->stateStore->saveOrder($order);
            $this->appendEvent('order.rejected', $order, ['reason' => 'post_only_would_cross']);

            return $this->placeResult(false, $request, $order);
        }

        if ($this->isStandaloneProtection($request) && $this->stateStore->consumeProtectionRejectionFlag()) {
            $order = $this->buildOrder($request, ExchangeOrderStatus::REJECTED, [
                'reason' => 'protection_rejected_by_scenario',
            ]);
            $this->stateStore->saveOrder($order);
            $this->appendEvent('protection_order.rejected', $order, ['reason' => 'protection_rejected_by_scenario']);

            return $this->placeResult(false, $request, $order);
        }

        $order = $this->buildOrder($request, ExchangeOrderStatus::OPEN, $this->requestMetadata($request));
        $this->stateStore->saveOrder($order);
        $this->appendEvent('order.created', $order);

        if ($this->isStandaloneProtection($request)) {
            $this->appendEvent('protection_order.created', $order);
        }

        if ($request->orderType === ExchangeOrderType::MARKET || $this->wouldCross($request)) {
            $order = $this->fillOrder($order->exchangeOrderId) ?? $order;
        }

        return $this->placeResult(true, $request, $order);
    }

    public function cancel(CancelOrderRequest $request): CancelOrderResult
    {
        $this->assertCancelContext($request);
        $order = null;
        if ($request->exchangeOrderId !== null && trim($request->exchangeOrderId) !== '') {
            $order = $this->stateStore->getOrder($request->exchangeOrderId);
        }
        if (!$order instanceof ExchangeOrderDto && $request->clientOrderId !== null && trim($request->clientOrderId) !== '') {
            $order = $this->stateStore->getOrderByClientOrderId($request->symbol, $request->clientOrderId);
        }

        if (!$order instanceof ExchangeOrderDto || !$this->isActiveStatus($order->status)) {
            return new CancelOrderResult(
                cancelled: false,
                symbol: strtoupper($request->symbol),
                exchangeOrderId: $request->exchangeOrderId,
                clientOrderId: $request->clientOrderId,
                status: ExchangeOrderStatus::UNKNOWN,
                metadata: ['reason' => 'order_not_active'],
            );
        }

        $cancelled = $this->withOrderStatus($order, ExchangeOrderStatus::CANCELLED, $order->metadata);
        $this->stateStore->saveOrder($cancelled);
        $this->appendEvent('order.cancelled', $cancelled);

        return new CancelOrderResult(
            cancelled: true,
            symbol: $cancelled->symbol,
            exchangeOrderId: $cancelled->exchangeOrderId,
            clientOrderId: $cancelled->clientOrderId,
            status: ExchangeOrderStatus::CANCELLED,
        );
    }

    public function fillOrder(string $exchangeOrderId, ?float $quantity = null, ?float $price = null): ?ExchangeOrderDto
    {
        $order = $this->stateStore->getOrder($exchangeOrderId);
        if (!$order instanceof ExchangeOrderDto || !$this->isActiveStatus($order->status)) {
            return $order;
        }

        $fillQuantity = $quantity ?? $order->remainingQuantity;
        $fillQuantity = min($fillQuantity, $order->remainingQuantity);
        if ($fillQuantity <= 0.0) {
            return $order;
        }

        $executionPrice = $price ?? $this->executionPrice($order);
        $newFilled = $order->filledQuantity + $fillQuantity;
        $newRemaining = max(0.0, $order->quantity - $newFilled);
        $averagePrice = $this->averagePrice($order, $fillQuantity, $executionPrice, $newFilled);
        $status = $newRemaining <= 0.00000001
            ? ExchangeOrderStatus::FILLED
            : ExchangeOrderStatus::PARTIALLY_FILLED;

        $updated = new ExchangeOrderDto(
            exchange: $order->exchange,
            marketType: $order->marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            side: $order->side,
            positionSide: $order->positionSide,
            orderType: $order->orderType,
            status: $status,
            quantity: $order->quantity,
            filledQuantity: $newFilled,
            remainingQuantity: $newRemaining,
            price: $order->price,
            averagePrice: $averagePrice,
            stopPrice: $order->stopPrice,
            reduceOnly: $order->reduceOnly,
            postOnly: $order->postOnly,
            timeInForce: $order->timeInForce,
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            metadata: $order->metadata,
        );

        $this->stateStore->saveOrder($updated);
        $this->applyPositionFill($updated, $fillQuantity, $executionPrice);
        $this->appendEvent(
            $status === ExchangeOrderStatus::FILLED ? 'order.filled' : 'order.partially_filled',
            $updated,
            ['fill_quantity' => $fillQuantity, 'fill_price' => $executionPrice],
        );

        if ($status === ExchangeOrderStatus::FILLED) {
            $updated = $this->createAttachedProtectionOrders($updated);
        }

        return $updated;
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function matchOpenOrders(string $symbol): array
    {
        $filled = [];
        foreach ($this->stateStore->getOpenOrders($symbol) as $order) {
            if ($order->orderType !== ExchangeOrderType::LIMIT || $order->price === null || $order->postOnly) {
                continue;
            }

            if ($this->limitOrderCrossesBook($order)) {
                $updated = $this->fillOrder($order->exchangeOrderId);
                if ($updated instanceof ExchangeOrderDto) {
                    $filled[] = $updated;
                }
            }
        }

        return $filled;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function buildOrder(PlaceOrderRequest $request, ExchangeOrderStatus $status, array $metadata = []): ExchangeOrderDto
    {
        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: strtoupper($request->symbol),
            exchangeOrderId: $this->stateStore->nextOrderId(),
            clientOrderId: $request->clientOrderId,
            side: $request->side,
            positionSide: $request->positionSide,
            orderType: $request->orderType,
            status: $status,
            quantity: $request->quantity,
            filledQuantity: 0.0,
            remainingQuantity: $request->quantity,
            price: $request->price,
            averagePrice: null,
            stopPrice: $request->stopPrice,
            reduceOnly: $request->reduceOnly || $this->isStandaloneProtection($request),
            postOnly: $request->postOnly,
            timeInForce: $request->timeInForce,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function requestMetadata(PlaceOrderRequest $request): array
    {
        return array_filter([
            'source' => 'fake_exchange',
            'leverage' => $request->leverage,
            'margin_mode' => $request->marginMode,
            'attached_stop_loss_price' => $request->attachedStopLossPrice,
            'attached_take_profit_price' => $request->attachedTakeProfitPrice,
        ], static fn (mixed $value): bool => $value !== null) + $request->metadata;
    }

    private function createAttachedProtectionOrders(ExchangeOrderDto $entryOrder): ExchangeOrderDto
    {
        if (($entryOrder->metadata['attached_protection_processed'] ?? false) === true) {
            return $entryOrder;
        }

        $stopLoss = $this->floatMetadata($entryOrder->metadata, 'attached_stop_loss_price');
        $takeProfit = $this->floatMetadata($entryOrder->metadata, 'attached_take_profit_price');
        if ($stopLoss === null && $takeProfit === null) {
            return $entryOrder;
        }

        $metadata = array_replace($entryOrder->metadata, ['attached_protection_processed' => true]);

        if ($this->stateStore->consumeProtectionRejectionFlag()) {
            $metadata['protection_status'] = 'rejected';
            $metadata['protection_reject_reason'] = 'protection_rejected_by_scenario';
            $updated = $this->withOrderStatus($entryOrder, $entryOrder->status, $metadata);
            $this->stateStore->saveOrder($updated);
            $this->appendEvent('protection_order.rejected', $entryOrder, ['reason' => 'protection_rejected_by_scenario']);

            return $updated;
        }

        $metadata['protection_status'] = 'accepted';
        $metadata['protection_order_ids'] = [];

        if ($stopLoss !== null) {
            $metadata['protection_order_ids'][] = $this->createProtectionOrder(
                $entryOrder,
                ExchangeOrderType::STOP_LOSS,
                $stopLoss,
                'sl',
            )->exchangeOrderId;
        }
        if ($takeProfit !== null) {
            $metadata['protection_order_ids'][] = $this->createProtectionOrder(
                $entryOrder,
                ExchangeOrderType::TAKE_PROFIT,
                $takeProfit,
                'tp',
            )->exchangeOrderId;
        }

        $updated = $this->withOrderStatus($entryOrder, $entryOrder->status, $metadata);
        $this->stateStore->saveOrder($updated);

        return $updated;
    }

    private function createProtectionOrder(
        ExchangeOrderDto $entryOrder,
        ExchangeOrderType $type,
        float $stopPrice,
        string $suffix,
    ): ExchangeOrderDto {
        $side = $entryOrder->positionSide === ExchangePositionSide::SHORT
            ? ExchangeOrderSide::BUY
            : ExchangeOrderSide::SELL;
        $order = new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $entryOrder->symbol,
            exchangeOrderId: $this->stateStore->nextOrderId(),
            clientOrderId: $entryOrder->clientOrderId !== null ? $entryOrder->clientOrderId . ':' . $suffix : null,
            side: $side,
            positionSide: $entryOrder->positionSide,
            orderType: $type,
            status: ExchangeOrderStatus::OPEN,
            quantity: $entryOrder->filledQuantity,
            filledQuantity: 0.0,
            remainingQuantity: $entryOrder->filledQuantity,
            price: null,
            averagePrice: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            timeInForce: null,
            createdAt: $this->clock->now(),
            metadata: [
                'source' => 'fake_exchange',
                'parent_order_id' => $entryOrder->exchangeOrderId,
                'protection_kind' => $suffix,
            ],
        );
        $this->stateStore->saveOrder($order);
        $this->appendEvent('protection_order.created', $order, ['parent_order_id' => $entryOrder->exchangeOrderId]);

        return $order;
    }

    private function withOrderStatus(ExchangeOrderDto $order, ExchangeOrderStatus $status, array $metadata): ExchangeOrderDto
    {
        return new ExchangeOrderDto(
            exchange: $order->exchange,
            marketType: $order->marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            side: $order->side,
            positionSide: $order->positionSide,
            orderType: $order->orderType,
            status: $status,
            quantity: $order->quantity,
            filledQuantity: $order->filledQuantity,
            remainingQuantity: $order->remainingQuantity,
            price: $order->price,
            averagePrice: $order->averagePrice,
            stopPrice: $order->stopPrice,
            reduceOnly: $order->reduceOnly,
            postOnly: $order->postOnly,
            timeInForce: $order->timeInForce,
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            metadata: $metadata,
        );
    }

    private function applyPositionFill(ExchangeOrderDto $order, float $fillQuantity, float $executionPrice): void
    {
        if ($order->positionSide === null) {
            return;
        }

        $existing = $this->stateStore->getPosition($order->symbol, $order->positionSide);
        if ($order->reduceOnly) {
            if (!$existing instanceof ExchangePositionDto) {
                return;
            }

            $remainingSize = max(0.0, $existing->size - $fillQuantity);
            if ($remainingSize <= 0.00000001) {
                $this->stateStore->removePosition($order->symbol, $order->positionSide);
                $this->stateStore->appendEvent(new FakeExchangeEvent(
                    'position.closed',
                    $order->symbol,
                    $this->clock->now(),
                    ['order_id' => $order->exchangeOrderId],
                ));

                return;
            }

            $this->stateStore->savePosition($this->positionWithSize($existing, $remainingSize));
            $this->stateStore->appendEvent(new FakeExchangeEvent(
                'position.updated',
                $order->symbol,
                $this->clock->now(),
                ['order_id' => $order->exchangeOrderId, 'size' => $remainingSize],
            ));

            return;
        }

        $previousSize = $existing?->size ?? 0.0;
        $newSize = $previousSize + $fillQuantity;
        $entryPrice = $previousSize > 0.0 && $existing instanceof ExchangePositionDto
            ? (($existing->entryPrice * $previousSize) + ($executionPrice * $fillQuantity)) / $newSize
            : $executionPrice;
        $leverage = $this->floatMetadata($order->metadata, 'leverage') ?? 1.0;
        $markPrice = $this->midPrice($order->symbol);
        $position = new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $order->symbol,
            side: $order->positionSide,
            size: $newSize,
            entryPrice: $entryPrice,
            markPrice: $markPrice,
            unrealizedPnl: 0.0,
            realizedPnl: 0.0,
            margin: ($newSize * $entryPrice) / max($leverage, 1.0),
            leverage: $leverage,
            openedAt: $existing?->openedAt ?? $this->clock->now(),
            updatedAt: $this->clock->now(),
            metadata: ['source' => 'fake_exchange', 'last_order_id' => $order->exchangeOrderId],
        );
        $this->stateStore->savePosition($position);
        $this->stateStore->appendEvent(new FakeExchangeEvent(
            $previousSize > 0.0 ? 'position.updated' : 'position.opened',
            $order->symbol,
            $this->clock->now(),
            ['order_id' => $order->exchangeOrderId, 'size' => $newSize],
        ));
    }

    private function positionWithSize(ExchangePositionDto $position, float $size): ExchangePositionDto
    {
        return new ExchangePositionDto(
            exchange: $position->exchange,
            marketType: $position->marketType,
            symbol: $position->symbol,
            side: $position->side,
            size: $size,
            entryPrice: $position->entryPrice,
            markPrice: $position->markPrice,
            unrealizedPnl: $position->unrealizedPnl,
            realizedPnl: $position->realizedPnl,
            margin: $position->margin,
            leverage: $position->leverage,
            openedAt: $position->openedAt,
            updatedAt: $this->clock->now(),
            metadata: $position->metadata,
        );
    }

    private function placeResult(bool $accepted, PlaceOrderRequest $request, ExchangeOrderDto $order): PlaceOrderResult
    {
        return new PlaceOrderResult(
            accepted: $accepted,
            symbol: strtoupper($request->symbol),
            clientOrderId: $request->clientOrderId,
            exchangeOrderId: $order->exchangeOrderId,
            status: $order->status,
            submittedAt: $this->clock->now(),
            order: $order,
            metadata: $accepted ? [] : $order->metadata,
        );
    }

    private function appendEvent(string $type, ExchangeOrderDto $order, array $payload = []): void
    {
        $this->stateStore->appendEvent(new FakeExchangeEvent(
            $type,
            $order->symbol,
            $this->clock->now(),
            ['order_id' => $order->exchangeOrderId] + $payload,
        ));
    }

    private function assertRequestContext(PlaceOrderRequest $request): void
    {
        if ($request->exchange !== Exchange::FAKE || $request->marketType !== MarketType::PERPETUAL) {
            throw new \InvalidArgumentException(sprintf(
                'Fake exchange adapter cannot handle "%s::%s"',
                $request->exchange->value,
                $request->marketType->value,
            ));
        }
    }

    private function assertCancelContext(CancelOrderRequest $request): void
    {
        if ($request->exchange !== Exchange::FAKE || $request->marketType !== MarketType::PERPETUAL) {
            throw new \InvalidArgumentException(sprintf(
                'Fake exchange adapter cannot handle "%s::%s"',
                $request->exchange->value,
                $request->marketType->value,
            ));
        }
    }

    private function assertRequestIntent(PlaceOrderRequest $request): void
    {
        if ($request->postOnly && $request->orderType !== ExchangeOrderType::LIMIT) {
            throw new \InvalidArgumentException('postOnly is only supported for limit orders');
        }

        $reduceIntent = $request->reduceOnly || $this->isStandaloneProtection($request);
        $expectedSide = match ([$reduceIntent, $request->positionSide]) {
            [false, ExchangePositionSide::LONG] => ExchangeOrderSide::BUY,
            [false, ExchangePositionSide::SHORT] => ExchangeOrderSide::SELL,
            [true, ExchangePositionSide::LONG] => ExchangeOrderSide::SELL,
            [true, ExchangePositionSide::SHORT] => ExchangeOrderSide::BUY,
        };

        if ($request->side !== $expectedSide) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid order side "%s" for %s %s position intent',
                $request->side->value,
                $reduceIntent ? 'reduce-only' : 'entry',
                $request->positionSide->value,
            ));
        }
    }

    private function wouldCross(PlaceOrderRequest $request): bool
    {
        if ($request->orderType !== ExchangeOrderType::LIMIT || $request->price === null) {
            return false;
        }

        $top = $this->orderBook->top($request->symbol);

        return $request->side === ExchangeOrderSide::BUY
            ? $request->price >= $top->ask
            : $request->price <= $top->bid;
    }

    private function limitOrderCrossesBook(ExchangeOrderDto $order): bool
    {
        if ($order->price === null) {
            return false;
        }

        $top = $this->orderBook->top($order->symbol);

        return $order->side === ExchangeOrderSide::BUY
            ? $order->price >= $top->ask
            : $order->price <= $top->bid;
    }

    private function executionPrice(ExchangeOrderDto $order): float
    {
        if ($order->price !== null) {
            return $order->price;
        }

        $top = $this->orderBook->top($order->symbol);

        return $order->side === ExchangeOrderSide::BUY ? $top->ask : $top->bid;
    }

    private function averagePrice(ExchangeOrderDto $order, float $fillQuantity, float $executionPrice, float $newFilled): float
    {
        if ($newFilled <= 0.0) {
            return $executionPrice;
        }

        $previousFilled = $order->filledQuantity;
        $previousAverage = $order->averagePrice ?? $executionPrice;

        return (($previousAverage * $previousFilled) + ($executionPrice * $fillQuantity)) / $newFilled;
    }

    private function midPrice(string $symbol): float
    {
        $top = $this->orderBook->top($symbol);

        return ($top->bid + $top->ask) / 2.0;
    }

    private function isStandaloneProtection(PlaceOrderRequest $request): bool
    {
        return \in_array($request->orderType, [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER,
        ], true);
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
     * @param array<string,mixed> $metadata
     */
    private function floatMetadata(array $metadata, string $key): ?float
    {
        $value = $metadata[$key] ?? null;

        return \is_scalar($value) && is_numeric($value) ? (float) $value : null;
    }
}
