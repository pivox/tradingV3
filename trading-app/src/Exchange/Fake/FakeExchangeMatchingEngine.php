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
use App\Exchange\Enum\ExchangeTimeInForce;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Psr\Clock\ClockInterface;

final readonly class FakeExchangeMatchingEngine
{
    private const FEE_RATE = 0.0005;
    /**
     * @var string[]
     */
    private const LINEAGE_METADATA_KEYS = [
        'internal_trade_id',
        'trade_id',
        'internal_position_id',
        'position_id',
        'exchange_position_id',
        'order_intent_id',
        'client_order_id',
        'run_id',
        'correlation_run_id',
        'orchestration_run_id',
        'orchestration_set_id',
        'orchestration_dashboard_id',
        'mtf_profile',
        'origin',
        'attempt_number',
        'decision_key',
    ];

    private FakeOrderValidator $orderValidator;

    private FakeInstrumentProviderInterface $instruments;

    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private FakeExchangeOrderBook $orderBook,
        private ClockInterface $clock,
        ?FakeOrderValidator $orderValidator = null,
        ?FakeInstrumentProviderInterface $instruments = null,
    ) {
        $this->instruments = $instruments ?? new FakeInstrumentCatalog();
        $this->orderValidator = $orderValidator ?? new FakeOrderValidator($this->instruments);
    }

    public function submit(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->assertRequestContext($request);
        $this->assertRequestIntent($request);
        $request = $this->withPersistedLeverageSetting($request);

        $existing = $this->stateStore->getOrderByClientOrderId($request->symbol, $request->clientOrderId);
        if ($existing instanceof ExchangeOrderDto) {
            if (!$this->orderMatchesRequest($existing, $request)) {
                return $this->intentMismatchReplayResult($request, $existing);
            }

            return new PlaceOrderResult(
                accepted: $this->isAcceptedReplayStatus($existing->status),
                symbol: $existing->symbol,
                clientOrderId: $request->clientOrderId,
                exchangeOrderId: $existing->exchangeOrderId,
                status: $existing->status,
                submittedAt: $this->clock->now(),
                order: $existing,
                metadata: array_replace($existing->metadata, ['idempotent_replay' => true]),
            );
        }

        $top = $this->orderBook->top($request->symbol);
        $referencePrice = $request->side === ExchangeOrderSide::BUY ? $top->ask : $top->bid;
        $validationMetadata = [];
        try {
            $availableMargin = $this->stateStore->availableMarginUsdt();
        } catch (\LogicException) {
            $availableMargin = NAN;
            $validationMetadata['quality_flags'] = ['margin_state_unavailable'];
        }
        $validation = $this->orderValidator->validate(
            $request,
            $referencePrice,
            $availableMargin,
        );
        if (!$validation->accepted) {
            return $this->rejectOrder(
                $request,
                $validation->reason ?? 'order_validation_failed',
                $validation->metadata,
                $validationMetadata,
            );
        }

        if ($request->postOnly && $this->wouldCross($request)) {
            return $this->rejectOrder($request, 'post_only_would_cross');
        }

        $instrument = $this->instruments->find($request->symbol);
        if (!$instrument instanceof FakeInstrument) {
            throw new \LogicException('fake_instrument_metadata_unavailable');
        }

        if ($this->isStandaloneProtection($request) && $this->stateStore->consumeProtectionRejectionFlag()) {
            $order = $this->buildOrder($request, ExchangeOrderStatus::REJECTED, array_replace($this->requestMetadata($request), [
                'reason' => 'protection_rejected_by_scenario',
            ]));
            $this->stateStore->saveOrder($order);
            $this->appendEvent('protection_order.rejected', $order, ['reason' => 'protection_rejected_by_scenario']);

            return $this->placeResult(false, $request, $order);
        }

        $order = $this->buildOrder(
            $request,
            ExchangeOrderStatus::OPEN,
            $this->requestMetadata($request, $referencePrice, $instrument->contractSize),
        );
        $this->stateStore->saveOrder($order);
        $this->appendEvent('order.created', $order);

        if ($this->isStandaloneProtection($request)) {
            $this->appendEvent('protection_order.created', $order);
        }

        if ($request->orderType === ExchangeOrderType::MARKET || $this->wouldCross($request)) {
            $order = $this->fillOrder($order->exchangeOrderId) ?? $order;
        } elseif ($this->shouldExpireIfNotFilledImmediately($request)) {
            $order = $this->withOrderStatus(
                $order,
                ExchangeOrderStatus::EXPIRED,
                array_replace($order->metadata, ['reason' => 'immediate_execution_not_available']),
            );
            $this->stateStore->saveOrder($order);
            $this->appendEvent('order.expired', $order, ['reason' => 'immediate_execution_not_available']);
        }

        return $this->placeResult(true, $request, $order);
    }

    public function cancel(CancelOrderRequest $request): CancelOrderResult
    {
        $this->assertCancelContext($request);
        $order = null;
        if ($request->exchangeOrderId !== null && trim($request->exchangeOrderId) !== '') {
            $order = $this->stateStore->getOrder($request->exchangeOrderId);
            if ($order instanceof ExchangeOrderDto && $order->symbol !== strtoupper($request->symbol)) {
                return $this->cancelNotActive($request);
            }
        }
        if (!$order instanceof ExchangeOrderDto && $request->clientOrderId !== null && trim($request->clientOrderId) !== '') {
            $order = $this->stateStore->getOrderByClientOrderId($request->symbol, $request->clientOrderId);
        }

        if ($order instanceof ExchangeOrderDto && $order->status === ExchangeOrderStatus::CANCELLED) {
            return new CancelOrderResult(
                cancelled: true,
                symbol: $order->symbol,
                exchangeOrderId: $order->exchangeOrderId,
                clientOrderId: $order->clientOrderId,
                status: ExchangeOrderStatus::CANCELLED,
                metadata: ['idempotent_replay' => true],
            );
        }

        if (!$order instanceof ExchangeOrderDto || !$this->isActiveStatus($order->status)) {
            return $this->cancelNotActive($request);
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

        $requestedFillQuantity = min($quantity ?? $order->remainingQuantity, $order->remainingQuantity);
        $fillQuantity = $requestedFillQuantity;
        $cancelReduceRemainder = false;
        if ($order->reduceOnly) {
            $position = $order->positionSide !== null
                ? $this->stateStore->getPosition($order->symbol, $order->positionSide)
                : null;
            if (!$position instanceof ExchangePositionDto || $position->size <= 0.00000001) {
                return $this->cancelOpenOrder($order, 'no_position_to_reduce');
            }

            $fillQuantity = min($requestedFillQuantity, $position->size);
            $cancelReduceRemainder = $fillQuantity < $requestedFillQuantity - 0.00000001;
        }

        if ($fillQuantity <= 0.0) {
            return $order;
        }

        $executionPrice = $price ?? $this->executionPrice($order);
        $newFilled = $order->filledQuantity + $fillQuantity;
        $newRemaining = max(0.0, $order->quantity - $newFilled);
        $averagePrice = $this->averagePrice($order, $fillQuantity, $executionPrice, $newFilled);
        $status = $this->fillStatus($newRemaining, $cancelReduceRemainder);
        $metadata = $cancelReduceRemainder
            ? array_replace($order->metadata, [
                'reason' => 'reduce_only_position_size_capped',
                'reduce_only_cancelled_remainder' => true,
            ])
            : $order->metadata;

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
            metadata: $metadata,
        );

        $this->stateStore->saveOrder($updated);
        $this->applyPositionFill($updated, $fillQuantity, $executionPrice);
        $this->appendEvent(
            $status === ExchangeOrderStatus::FILLED ? 'order.filled' : 'order.partially_filled',
            $updated,
            [
                'fill_quantity' => $fillQuantity,
                'fill_price' => $executionPrice,
                'fill_fee' => $this->fillFee(
                    $fillQuantity,
                    $executionPrice,
                    $this->marginContractSize($updated->metadata),
                ),
                'fee_currency' => 'USDT',
                'pnl_source' => 'fake_paper_fill_ledger_v1',
                'cost_completeness' => 'complete',
            ],
        );

        if ($cancelReduceRemainder) {
            $this->appendEvent('order.cancelled', $updated, ['reason' => 'reduce_only_position_size_capped']);
        }

        if ($status === ExchangeOrderStatus::FILLED && !$updated->reduceOnly && !$this->isTriggerOrder($updated)) {
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
            if ($order->orderType === ExchangeOrderType::LIMIT) {
                if ($order->price === null || $order->postOnly || !$this->limitOrderCrossesBook($order)) {
                    continue;
                }
            } elseif (!$this->triggerOrderCrossesBook($order)) {
                continue;
            }

            $updated = $this->fillOrder($order->exchangeOrderId);
            if ($updated instanceof ExchangeOrderDto) {
                $filled[] = $updated;
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
            marketType: $request->marketType,
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
    private function requestMetadata(
        PlaceOrderRequest $request,
        ?float $marginReferencePrice = null,
        ?string $marginContractSize = null,
    ): array
    {
        return array_filter([
            'source' => 'fake_exchange',
            'leverage' => $request->leverage,
            'margin_mode' => $request->marginMode,
            'client_order_id' => $request->clientOrderId,
            'attached_stop_loss_price' => $request->attachedStopLossPrice,
            'attached_take_profit_price' => $request->attachedTakeProfitPrice,
            'quantity_decimal' => $request->exactQuantity(),
            'price_decimal' => $request->exactPrice(),
            'stop_price_decimal' => $request->exactStopPrice(),
            'attached_stop_loss_price_decimal' => $request->exactAttachedStopLossPrice(),
            'attached_take_profit_price_decimal' => $request->exactAttachedTakeProfitPrice(),
            'margin_reference_price' => $marginReferencePrice,
            'margin_reference_source' => $marginReferencePrice !== null ? 'top_of_book' : null,
            'margin_contract_size' => $marginContractSize,
        ], static fn (mixed $value): bool => $value !== null) + $this->lineageMetadata($request->metadata);
    }

    /**
     * @param array<string,mixed> ...$metadata
     */
    private function rejectOrder(PlaceOrderRequest $request, string $reason, array ...$metadata): PlaceOrderResult
    {
        $metadata[] = ['reason' => $reason];
        $rejectionMetadata = array_replace($this->requestMetadata($request), ...$metadata);
        $order = $this->buildOrder($request, ExchangeOrderStatus::REJECTED, $rejectionMetadata);
        $this->stateStore->saveOrder($order);
        $this->appendEvent('order.rejected', $order, array_replace(...$metadata));

        return $this->placeResult(false, $request, $order);
    }

    private function intentMismatchReplayResult(PlaceOrderRequest $request, ExchangeOrderDto $existing): PlaceOrderResult
    {
        return new PlaceOrderResult(
            accepted: false,
            symbol: $existing->symbol,
            clientOrderId: $request->clientOrderId,
            exchangeOrderId: $existing->exchangeOrderId,
            status: ExchangeOrderStatus::REJECTED,
            submittedAt: $this->clock->now(),
            order: $existing,
            metadata: array_replace($existing->metadata, [
                'reason' => 'duplicate_client_order_id_intent_mismatch',
                'idempotent_replay' => false,
            ]),
        );
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
            metadata: $this->lineageMetadata($entryOrder->metadata) + [
                'source' => 'fake_exchange',
                'parent_order_id' => $entryOrder->exchangeOrderId,
                'parent_client_order_id' => $entryOrder->clientOrderId,
                'protection_kind' => $suffix,
            ],
        );
        $this->stateStore->saveOrder($order);
        $this->appendEvent('protection_order.created', $order, ['parent_order_id' => $entryOrder->exchangeOrderId]);

        return $order;
    }

    /**
     * @param array<string,mixed> $metadata
     */
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

    private function cancelOpenOrder(ExchangeOrderDto $order, string $reason): ExchangeOrderDto
    {
        $cancelled = $this->withOrderStatus(
            $order,
            ExchangeOrderStatus::CANCELLED,
            array_replace($order->metadata, ['reason' => $reason]),
        );
        $this->stateStore->saveOrder($cancelled);
        $this->appendEvent('order.cancelled', $cancelled, ['reason' => $reason]);

        return $cancelled;
    }

    private function cancelNotActive(CancelOrderRequest $request): CancelOrderResult
    {
        return new CancelOrderResult(
            cancelled: false,
            symbol: strtoupper($request->symbol),
            exchangeOrderId: $request->exchangeOrderId,
            clientOrderId: $request->clientOrderId,
            status: ExchangeOrderStatus::UNKNOWN,
            metadata: ['reason' => 'order_not_active'],
        );
    }

    private function applyPositionFill(ExchangeOrderDto $order, float $fillQuantity, float $executionPrice): void
    {
        if ($order->positionSide === null) {
            return;
        }

        $contractSize = $this->marginContractSize($order->metadata);
        $fillFee = $this->fillFee($fillQuantity, $executionPrice, $contractSize);
        $existing = $this->stateStore->getPosition($order->symbol, $order->positionSide);
        $existingMetadata = $existing?->metadata ?? ['source' => 'fake_exchange'];
        if ($order->reduceOnly) {
            if (!$existing instanceof ExchangePositionDto) {
                return;
            }

            $exitLedger = $this->appendExitLedger(
                $existing->metadata,
                $fillQuantity,
                $executionPrice,
                $contractSize,
                $fillFee,
            );
            $remainingSize = max(0.0, $existing->size - $fillQuantity);
            if ($remainingSize <= 0.00000001) {
                $this->stateStore->removePosition($order->symbol, $order->positionSide);
                $this->stateStore->appendEvent(new FakeExchangeEvent(
                    'position.closed',
                    $order->symbol,
                    $this->clock->now(),
                    ['order_id' => $order->exchangeOrderId] + $this->certifiedClosePayload($existing, $exitLedger),
                ));
                $this->cancelSiblingProtectionOrders($order);

                return;
            }

            $this->stateStore->savePosition($this->positionWithSize($existing, $remainingSize, $exitLedger));
            $this->stateStore->appendEvent(new FakeExchangeEvent(
                'position.updated',
                $order->symbol,
                $this->clock->now(),
                ['order_id' => $order->exchangeOrderId, 'size' => $remainingSize],
            ));
            if ($this->isTriggerOrder($order)) {
                $this->cancelSiblingProtectionOrders($order);
            }

            return;
        }

        $previousSize = $existing?->size ?? 0.0;
        $newSize = $previousSize + $fillQuantity;
        $entryPrice = $previousSize > 0.0 && $existing instanceof ExchangePositionDto
            ? (($existing->entryPrice * $previousSize) + ($executionPrice * $fillQuantity)) / $newSize
            : $executionPrice;
        $leverage = $this->floatMetadata($order->metadata, 'leverage') ?? 1.0;
        $existingMargin = $existing?->margin;
        if ($existing instanceof ExchangePositionDto && ($existingMargin === null || !\is_finite($existingMargin) || $existingMargin < 0.0)) {
            throw new \LogicException('fake_position_margin_unavailable');
        }
        $existingMargin ??= 0.0;
        $newMargin = $existingMargin + (($fillQuantity * $executionPrice * $contractSize) / max($leverage, 1.0));
        $effectiveLeverage = ($newSize * $entryPrice * $contractSize) / $newMargin;
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
            margin: $newMargin,
            leverage: $effectiveLeverage,
            openedAt: $existing?->openedAt ?? $this->clock->now(),
            updatedAt: $this->clock->now(),
            metadata: $this->appendEntryLedger(
                array_replace(
                    $this->lineageMetadata($order->metadata),
                    $existingMetadata,
                ),
                $order->exchangeOrderId,
                $fillQuantity,
                $executionPrice,
                $contractSize,
                $fillFee,
            ),
        );
        $this->stateStore->savePosition($position);
        $this->stateStore->appendEvent(new FakeExchangeEvent(
            $previousSize > 0.0 ? 'position.updated' : 'position.opened',
            $order->symbol,
            $this->clock->now(),
            ['order_id' => $order->exchangeOrderId, 'size' => $newSize],
        ));
    }

    private function cancelSiblingProtectionOrders(ExchangeOrderDto $filledProtectionOrder): void
    {
        $parentOrderId = $this->stringMetadata($filledProtectionOrder->metadata, 'parent_order_id');
        if ($parentOrderId === null) {
            return;
        }

        foreach ($this->stateStore->getOpenOrders($filledProtectionOrder->symbol) as $candidate) {
            if (
                $candidate->exchangeOrderId === $filledProtectionOrder->exchangeOrderId
                || !$this->isTriggerOrder($candidate)
                || $this->stringMetadata($candidate->metadata, 'parent_order_id') !== $parentOrderId
            ) {
                continue;
            }

            $this->cancelOpenOrder($candidate, 'sibling_protection_filled');
        }
    }

    /**
     * @param array<string,mixed>|null $metadata
     */
    private function positionWithSize(ExchangePositionDto $position, float $size, ?array $metadata = null): ExchangePositionDto
    {
        if ($position->margin === null || !\is_finite($position->margin) || $position->margin < 0.0 || $position->size <= 0.0) {
            throw new \LogicException('fake_position_margin_unavailable');
        }

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
            margin: $position->margin * ($size / $position->size),
            leverage: $position->leverage,
            openedAt: $position->openedAt,
            updatedAt: $this->clock->now(),
            metadata: $metadata ?? $position->metadata,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function appendEntryLedger(
        array $metadata,
        string $orderId,
        float $quantity,
        float $price,
        float $contractSize,
        float $fee,
    ): array
    {
        $entryOrderIds = $this->entryOrderIds($metadata, $orderId);

        return array_replace($metadata, [
            'source' => 'fake_exchange',
            'last_order_id' => $orderId,
            'entry_order_ids' => $entryOrderIds,
            'entry_order_count' => \count($entryOrderIds),
            'entry_qty' => $this->metadataFloat($metadata, 'entry_qty') + $quantity,
            'entry_notional_usdt' => $this->metadataFloat($metadata, 'entry_notional_usdt') + ($quantity * $price * $contractSize),
            'entry_fee_usdt' => $this->metadataFloat($metadata, 'entry_fee_usdt') + $fee,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
        ]);
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function appendExitLedger(
        array $metadata,
        float $quantity,
        float $price,
        float $contractSize,
        float $fee,
    ): array
    {
        return array_replace($metadata, [
            'exit_qty' => $this->metadataFloat($metadata, 'exit_qty') + $quantity,
            'exit_notional_usdt' => $this->metadataFloat($metadata, 'exit_notional_usdt') + ($quantity * $price * $contractSize),
            'exit_fee_usdt' => $this->metadataFloat($metadata, 'exit_fee_usdt') + $fee,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
        ]);
    }

    /**
     * @param array<string,mixed> $closeLedger
     * @return array<string,mixed>
     */
    private function certifiedClosePayload(ExchangePositionDto $position, array $closeLedger): array
    {
        $entryQty = $this->metadataFloat($closeLedger, 'entry_qty');
        $exitQty = $this->metadataFloat($closeLedger, 'exit_qty');
        $entryNotional = $this->metadataFloat($closeLedger, 'entry_notional_usdt');
        $exitNotional = $this->metadataFloat($closeLedger, 'exit_notional_usdt');
        $entryFee = $this->metadataFloat($closeLedger, 'entry_fee_usdt');
        $exitFee = $this->metadataFloat($closeLedger, 'exit_fee_usdt');
        $entryOrderCount = max(1, (int) round($this->metadataFloat($closeLedger, 'entry_order_count')));
        $lineageSufficient = $entryOrderCount <= 1;
        $gross = $position->side === ExchangePositionSide::SHORT
            ? $entryNotional - $exitNotional
            : $exitNotional - $entryNotional;

        return $this->lineageMetadata($closeLedger) + [
            'gross_realized_pnl_usdt' => round($gross, 12),
            'recorded_pnl_usdt' => round($gross - $entryFee - $exitFee, 12),
            'entry_fee_usdt' => round($entryFee, 12),
            'exit_fee_usdt' => round($exitFee, 12),
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => round($entryQty, 12),
            'exit_qty' => round($exitQty, 12),
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => abs($entryQty - $exitQty) <= 0.00000001,
            'lineage_sufficient' => $lineageSufficient,
            'identifier_conflict' => false,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
            'cost_completeness' => $lineageSufficient ? 'complete' : 'partial',
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return list<string>
     */
    private function entryOrderIds(array $metadata, string $orderId): array
    {
        $ids = [];
        $existing = $metadata['entry_order_ids'] ?? [];
        if (\is_array($existing)) {
            foreach ($existing as $existingId) {
                if (\is_scalar($existingId) && trim((string) $existingId) !== '') {
                    $ids[] = (string) $existingId;
                }
            }
        }

        $lastOrderId = $this->stringMetadata($metadata, 'last_order_id');
        if ($lastOrderId !== null) {
            $ids[] = $lastOrderId;
        }

        $ids[] = $orderId;

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function lineageMetadata(array $metadata): array
    {
        $lineage = [];
        foreach (self::LINEAGE_METADATA_KEYS as $key) {
            if (!\array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if ($value === null || \is_scalar($value)) {
                $lineage[$key] = $value;
            }
        }

        return $lineage;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function metadataFloat(array $metadata, string $key): float
    {
        $value = $metadata[$key] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function marginContractSize(array $metadata): float
    {
        $value = $metadata['margin_contract_size'] ?? 1.0;
        if (!\is_numeric($value) || !\is_finite((float) $value) || (float) $value <= 0.0) {
            throw new \LogicException('fake_order_margin_contract_size_unavailable');
        }

        return (float) $value;
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

    /**
     * @param array<string,mixed> $payload
     */
    private function appendEvent(string $type, ExchangeOrderDto $order, array $payload = []): void
    {
        $this->stateStore->appendEvent(new FakeExchangeEvent(
            $type,
            $order->symbol,
            $this->clock->now(),
            ['order_id' => $order->exchangeOrderId] + $payload,
        ));
    }

    private function fillFee(float $quantity, float $price, float $contractSize): float
    {
        return round($quantity * $price * $contractSize * self::FEE_RATE, 12);
    }

    private function withPersistedLeverageSetting(PlaceOrderRequest $request): PlaceOrderRequest
    {
        if ($request->leverage !== null) {
            return $request;
        }

        $setting = $this->stateStore->getLeverageSetting($request->symbol);
        if ($setting === null) {
            return $request;
        }

        return new PlaceOrderRequest(
            exchange: $request->exchange,
            marketType: $request->marketType,
            symbol: $request->symbol,
            side: $request->side,
            positionSide: $request->positionSide,
            orderType: $request->orderType,
            timeInForce: $request->timeInForce,
            quantity: $request->quantity,
            price: $request->price,
            stopPrice: $request->stopPrice,
            reduceOnly: $request->reduceOnly,
            postOnly: $request->postOnly,
            leverage: $setting['leverage'],
            marginMode: $setting['margin_mode'],
            clientOrderId: $request->clientOrderId,
            attachedStopLossPrice: $request->attachedStopLossPrice,
            attachedTakeProfitPrice: $request->attachedTakeProfitPrice,
            metadata: $request->metadata,
            quantityDecimal: $request->quantityDecimal,
            priceDecimal: $request->priceDecimal,
            stopPriceDecimal: $request->stopPriceDecimal,
            attachedStopLossPriceDecimal: $request->attachedStopLossPriceDecimal,
            attachedTakeProfitPriceDecimal: $request->attachedTakeProfitPriceDecimal,
        );
    }

    private function assertRequestContext(PlaceOrderRequest $request): void
    {
        if ($request->exchange !== Exchange::FAKE) {
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

        if ($this->isStandaloneProtection($request) && $request->stopPrice === null) {
            throw new \InvalidArgumentException('trigger orders require a stop price');
        }

        if ($request->reduceOnly && ($request->attachedStopLossPrice !== null || $request->attachedTakeProfitPrice !== null)) {
            throw new \InvalidArgumentException('attached SL/TP is only supported for entry orders');
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

    private function triggerOrderCrossesBook(ExchangeOrderDto $order): bool
    {
        if (!$this->isTriggerOrder($order) || $order->stopPrice === null) {
            return false;
        }

        $midPrice = $this->midPrice($order->symbol);

        return match ($order->orderType) {
            ExchangeOrderType::STOP_LOSS => $order->positionSide === ExchangePositionSide::SHORT
                ? $midPrice >= $order->stopPrice
                : $midPrice <= $order->stopPrice,
            ExchangeOrderType::TAKE_PROFIT => $order->positionSide === ExchangePositionSide::SHORT
                ? $midPrice <= $order->stopPrice
                : $midPrice >= $order->stopPrice,
            ExchangeOrderType::TRIGGER => $order->side === ExchangeOrderSide::BUY
                ? $midPrice >= $order->stopPrice
                : $midPrice <= $order->stopPrice,
            default => false,
        };
    }

    private function executionPrice(ExchangeOrderDto $order): float
    {
        if (
            $order->orderType === ExchangeOrderType::LIMIT
            && !$order->postOnly
            && $this->limitOrderCrossesBook($order)
        ) {
            $top = $this->orderBook->top($order->symbol);

            return $order->side === ExchangeOrderSide::BUY ? $top->ask : $top->bid;
        }

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

    private function isTriggerOrder(ExchangeOrderDto $order): bool
    {
        return \in_array($order->orderType, [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER,
        ], true);
    }

    private function shouldExpireIfNotFilledImmediately(PlaceOrderRequest $request): bool
    {
        return $request->orderType === ExchangeOrderType::LIMIT
            && \in_array($request->timeInForce, [ExchangeTimeInForce::IOC, ExchangeTimeInForce::FOK], true);
    }

    private function fillStatus(float $remainingQuantity, bool $cancelReduceRemainder): ExchangeOrderStatus
    {
        if ($cancelReduceRemainder) {
            return ExchangeOrderStatus::CANCELLED;
        }

        return $remainingQuantity <= 0.00000001
            ? ExchangeOrderStatus::FILLED
            : ExchangeOrderStatus::PARTIALLY_FILLED;
    }

    private function isActiveStatus(ExchangeOrderStatus $status): bool
    {
        return \in_array($status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }

    private function isAcceptedReplayStatus(ExchangeOrderStatus $status): bool
    {
        return !\in_array($status, [
            ExchangeOrderStatus::REJECTED,
            ExchangeOrderStatus::UNKNOWN,
        ], true);
    }

    private function orderMatchesRequest(ExchangeOrderDto $order, PlaceOrderRequest $request): bool
    {
        if ($order->marketType !== $request->marketType) {
            return false;
        }
        if ($order->side !== $request->side || $order->positionSide !== $request->positionSide) {
            return false;
        }
        if ($order->orderType !== $request->orderType) {
            return false;
        }
        if (!$this->exactDecimalMatches(
            $order->metadata,
            'quantity_decimal',
            $order->quantity,
            $request->exactQuantity(),
        )) {
            return false;
        }
        if ($request->orderType !== ExchangeOrderType::MARKET && !$this->exactDecimalMatches(
            $order->metadata,
            'price_decimal',
            $order->price,
            $request->exactPrice(),
        )) {
            return false;
        }
        if (!$this->exactDecimalMatches(
            $order->metadata,
            'stop_price_decimal',
            $order->stopPrice,
            $request->exactStopPrice(),
        )) {
            return false;
        }
        if ($order->reduceOnly !== ($request->reduceOnly || $this->isStandaloneProtection($request))) {
            return false;
        }
        if ($order->postOnly !== $request->postOnly || $order->timeInForce !== $request->timeInForce) {
            return false;
        }
        if (!$this->exactMetadataDecimalMatches(
            $order->metadata,
            'attached_stop_loss_price_decimal',
            'attached_stop_loss_price',
            $request->exactAttachedStopLossPrice(),
        )) {
            return false;
        }
        if (!$this->exactMetadataDecimalMatches(
            $order->metadata,
            'attached_take_profit_price_decimal',
            'attached_take_profit_price',
            $request->exactAttachedTakeProfitPrice(),
        )) {
            return false;
        }
        if (\array_key_exists('leverage', $order->metadata) && !$this->metadataIntMatches($order->metadata, 'leverage', $request->leverage)) {
            return false;
        }
        $storedMarginMode = $order->metadata['margin_mode'] ?? null;
        if ($storedMarginMode !== null && $storedMarginMode !== '' && $storedMarginMode !== $request->marginMode) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function exactDecimalMatches(
        array $metadata,
        string $decimalKey,
        ?float $floatValue,
        ?string $expected,
    ): bool
    {
        if ($expected === null || $floatValue === null) {
            return $expected === null && $floatValue === null;
        }

        $actual = $metadata[$decimalKey] ?? null;
        if (!\is_string($actual)) {
            $actual = self::canonicalFloat($floatValue);
        }

        return self::sameDecimal($actual, $expected);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function exactMetadataDecimalMatches(
        array $metadata,
        string $decimalKey,
        string $floatKey,
        ?string $expected,
    ): bool
    {
        $actual = $metadata[$decimalKey] ?? null;
        if ($expected === null) {
            return $actual === null && !\array_key_exists($floatKey, $metadata);
        }
        if (!\is_string($actual)) {
            $floatValue = $metadata[$floatKey] ?? null;
            if (!is_numeric($floatValue)) {
                return false;
            }
            $actual = self::canonicalFloat((float) $floatValue);
        }

        return self::sameDecimal($actual, $expected);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function metadataIntMatches(array $metadata, string $key, ?int $expected): bool
    {
        $actual = $metadata[$key] ?? null;
        if ($expected === null) {
            return $actual === null || $actual === '';
        }

        return \is_int($actual) ? $actual === $expected : ctype_digit((string) $actual) && (int) $actual === $expected;
    }

    private static function canonicalFloat(float $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sameDecimal(string $actual, string $expected): bool
    {
        try {
            return BigDecimal::of($actual)->isEqualTo(BigDecimal::of($expected));
        } catch (MathException) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function floatMetadata(array $metadata, string $key): ?float
    {
        $value = $metadata[$key] ?? null;

        return \is_scalar($value) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function stringMetadata(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return \is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
