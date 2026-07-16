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
    /**
     * @var string[]
     */
    private const FALLBACK_POLICY_METADATA_KEYS = [
        FakeFallbackTakerPolicy::VERSION_KEY,
        FakeFallbackTakerPolicy::ENABLED_KEY,
        FakeFallbackTakerPolicy::ZONE_MIN_KEY,
        FakeFallbackTakerPolicy::ZONE_MAX_KEY,
        FakeFallbackTakerPolicy::MAX_SLIPPAGE_BPS_KEY,
    ];

    private FakeOrderValidator $orderValidator;

    private FakeInstrumentProviderInterface $instruments;

    private FakeFillCostModel $fillCostModel;

    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private FakeExchangeOrderBook $orderBook,
        private ClockInterface $clock,
        ?FakeOrderValidator $orderValidator = null,
        ?FakeInstrumentProviderInterface $instruments = null,
        ?FakeFillCostModel $fillCostModel = null,
    ) {
        $this->instruments = $instruments ?? new FakeInstrumentCatalog();
        $this->orderValidator = $orderValidator ?? new FakeOrderValidator($this->instruments);
        $this->fillCostModel = $fillCostModel ?? new FakeFillCostModel();
    }

    public function submit(PlaceOrderRequest $request): PlaceOrderResult
    {
        return $this->submitWithTrustedFallbackMetadata($request);
    }

    /**
     * @param array<string, bool|float|int|string|null> $trustedFallbackMetadata
     */
    private function submitWithTrustedFallbackMetadata(
        PlaceOrderRequest $request,
        array $trustedFallbackMetadata = [],
    ): PlaceOrderResult
    {
        $this->assertRequestContext($request);
        $this->assertRequestIntent($request, $trustedFallbackMetadata);
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
                $trustedFallbackMetadata,
                $validation->metadata,
                $validationMetadata,
            );
        }

        if ($request->postOnly && $this->wouldCross($request)) {
            return $this->rejectOrder($request, 'post_only_would_cross', $trustedFallbackMetadata);
        }

        $instrument = $this->instruments->find($request->symbol);
        if (!$instrument instanceof FakeInstrument) {
            throw new \LogicException('fake_instrument_metadata_unavailable');
        }

        if ($this->isStandaloneProtection($request) && $this->stateStore->consumeProtectionRejectionFlag()) {
            $order = $this->buildOrder(
                $request,
                ExchangeOrderStatus::REJECTED,
                array_replace(
                    $this->requestMetadata($request),
                    $trustedFallbackMetadata,
                    ['reason' => 'protection_rejected_by_scenario'],
                ),
            );
            $this->stateStore->saveOrder($order);
            $this->appendEvent('protection_order.rejected', $order, ['reason' => 'protection_rejected_by_scenario']);

            return $this->placeResult(false, $request, $order);
        }

        $order = $this->buildOrder(
            $request,
            ExchangeOrderStatus::OPEN,
            array_replace(
                $this->requestMetadata($request, $referencePrice, $instrument->contractSize),
                $trustedFallbackMetadata,
            ),
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

    public function fallbackTaker(string $exchangeOrderId): FakeFallbackTakerResult
    {
        return $this->stateStore->runAtomically(
            fn (): FakeFallbackTakerResult => $this->fallbackTakerFromCurrentState($exchangeOrderId),
        );
    }

    public function fillOrder(string $exchangeOrderId, ?float $quantity = null, ?float $price = null): ?ExchangeOrderDto
    {
        $order = $this->stateStore->getOrder($exchangeOrderId);
        if (!$order instanceof ExchangeOrderDto || !$this->isActiveStatus($order->status)) {
            return $order;
        }
        if ($this->isPersistedTrailingOrder($order)) {
            $this->assertPersistedActiveTrailingOrderValid($order);
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
        $quantityDecimal = $this->orderQuantityDecimal($order);
        $previousFilledDecimal = $this->orderFilledQuantityDecimal($order);
        $fillQuantityDecimal = self::canonicalFloat($fillQuantity);
        try {
            $newFilledDecimal = (string) BigDecimal::of($previousFilledDecimal)
                ->plus($fillQuantityDecimal)
                ->stripTrailingZeros();
            $newRemainingDecimal = (string) BigDecimal::of($quantityDecimal)
                ->minus($newFilledDecimal)
                ->stripTrailingZeros();
        } catch (MathException) {
            throw new \LogicException('fake_fill_quantity_decimal_invalid');
        }
        $newFilled = (float) $newFilledDecimal;
        $newRemaining = max(0.0, (float) $newRemainingDecimal);
        $averagePrice = $this->averagePrice($order, $fillQuantity, $executionPrice, $newFilled);
        $status = $this->fillStatus($newRemaining, $cancelReduceRemainder);
        $metadata = array_replace(
            $order->metadata,
            [
                'filled_quantity_decimal' => $newFilledDecimal,
                'remaining_quantity_decimal' => $newRemainingDecimal,
            ],
        );
        if ($status === ExchangeOrderStatus::FILLED && $this->isActiveTrailingOrder($order)) {
            $metadata = array_replace($metadata, [
                'trailing_state_status' => 'triggered',
                'trailing_trigger_price' => $executionPrice,
                'trailing_trigger_price_decimal' => self::canonicalFloat($executionPrice),
            ]);
        }
        $metadata = $cancelReduceRemainder
            ? array_replace($metadata, [
                'reason' => 'reduce_only_position_size_capped',
                'reduce_only_cancelled_remainder' => true,
            ])
            : $metadata;
        $contractSize = $this->marginContractSize($order->metadata);
        $fillCost = $this->fillCostModel->forFill(
            quantity: $fillQuantity,
            price: $executionPrice,
            contractSize: $contractSize,
            postOnly: $order->postOnly,
        );

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
        $this->applyPositionFill($updated, $fillQuantity, $executionPrice, $fillCost);
        $this->appendEvent(
            $status === ExchangeOrderStatus::FILLED ? 'order.filled' : 'order.partially_filled',
            $updated,
            [
                'fill_quantity' => $fillQuantity,
                'fill_price' => $executionPrice,
                'fill_fee' => $this->fillFee(
                    $fillQuantity,
                    $executionPrice,
                    $contractSize,
                ),
                'fee_currency' => 'USDT',
                'liquidity_role' => $fillCost->liquidityRole,
                'spread_cost_usdt' => $fillCost->spreadCostUsdt,
                'slippage_cost_usdt' => $fillCost->slippageCostUsdt,
                'cost_model_version' => $fillCost->modelVersion,
                'spread_model_version' => $fillCost->spreadModelVersion,
                'pnl_source' => 'fake_paper_fill_ledger_v1',
                'cost_completeness' => 'complete',
            ],
        );
        if ($status === ExchangeOrderStatus::FILLED && $this->isActiveTrailingOrder($updated)) {
            $this->appendEvent('trailing_stop.triggered', $updated, [
                'state_version' => FakeTp1TrailingPolicy::VERSION,
                'watermark' => $this->floatMetadata($updated->metadata, 'trailing_watermark'),
                'stop_price' => $updated->stopPrice,
                'fill_quantity' => $fillQuantity,
                'fill_price' => $executionPrice,
            ]);
        }

        if ($cancelReduceRemainder) {
            $this->appendEvent('order.cancelled', $updated, ['reason' => 'reduce_only_position_size_capped']);
        }

        if ($status === ExchangeOrderStatus::FILLED && !$updated->reduceOnly && !$this->isTriggerOrder($updated)) {
            $updated = $this->createAttachedProtectionOrders($updated);
        }

        return $updated;
    }

    private function fallbackTakerFromCurrentState(string $exchangeOrderId): FakeFallbackTakerResult
    {
        $parent = $this->stateStore->getOrder($exchangeOrderId);
        if (!$parent instanceof ExchangeOrderDto) {
            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: false,
                reason: 'fallback_parent_not_found',
                parentOrder: null,
                fallbackOrder: null,
            );
        }
        if ($parent->remainingQuantity <= 0.00000001) {
            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: false,
                reason: 'fallback_remainder_zero',
                parentOrder: $parent,
                fallbackOrder: null,
            );
        }
        $fallbackClientOrderId = $this->fallbackClientOrderId($parent);
        $existingFallback = $this->stateStore->getOrderByClientOrderId(
            $parent->symbol,
            $fallbackClientOrderId,
        );
        if ($existingFallback instanceof ExchangeOrderDto) {
            if (!$this->fallbackChildMatchesParent($parent, $existingFallback)) {
                throw new \LogicException('fake_fallback_client_order_id_conflict');
            }

            $completed = $existingFallback->status === ExchangeOrderStatus::FILLED;

            return new FakeFallbackTakerResult(
                executed: $completed,
                idempotentReplay: true,
                reason: $completed
                    ? 'fallback_completed'
                    : ($this->stringMetadata($existingFallback->metadata, 'reason') ?? 'fallback_order_rejected'),
                parentOrder: $parent,
                fallbackOrder: $existingFallback,
                slippageBps: $this->floatMetadata($parent->metadata, 'fallback_slippage_bps'),
            );
        }
        if (($parent->metadata['fallback_status'] ?? null) === 'rejected') {
            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: true,
                reason: $this->stringMetadata($parent->metadata, 'fallback_reason')
                    ?? 'fallback_rejected',
                parentOrder: $parent,
                fallbackOrder: null,
                slippageBps: $this->floatMetadata($parent->metadata, 'fallback_slippage_bps'),
            );
        }
        if ($parent->status === ExchangeOrderStatus::CANCELLED) {
            $parent = $this->protectFallbackParentExposure($parent);

            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: false,
                reason: 'fallback_parent_cancelled',
                parentOrder: $parent,
                fallbackOrder: null,
            );
        }
        $parentAlreadyExpired = $parent->status === ExchangeOrderStatus::EXPIRED;
        if (
            $parent->orderType !== ExchangeOrderType::LIMIT
            || !$parent->postOnly
            || !$parent->positionSide instanceof ExchangePositionSide
            || (!$this->isActiveStatus($parent->status) && !$parentAlreadyExpired)
            || $parent->price === null
        ) {
            throw new \LogicException('fake_fallback_parent_not_eligible');
        }

        $policy = FakeFallbackTakerPolicy::fromMetadata($parent->metadata);
        if (!$policy instanceof FakeFallbackTakerPolicy) {
            throw new \LogicException('fake_fallback_policy_unavailable');
        }
        if (!$policy->enabled) {
            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: false,
                reason: 'fallback_disabled',
                parentOrder: $parent,
                fallbackOrder: null,
            );
        }

        $fallbackLeverage = $this->positiveIntMetadata($parent->metadata, 'leverage') ?? 1;
        $top = $this->orderBook->top($parent->symbol);
        $executionPrice = $parent->side === ExchangeOrderSide::BUY ? $top->ask : $top->bid;
        $slippageBps = $this->fallbackSlippageBps($parent, $executionPrice);
        $fallbackTrigger = $parentAlreadyExpired ? 'expired' : 'end_of_zone';

        $parent = $this->withOrderStatus(
            $parent,
            ExchangeOrderStatus::EXPIRED,
            array_replace(
                $parent->metadata,
                $parentAlreadyExpired ? [] : ['reason' => 'fallback_taker_triggered'],
                [
                    'leverage' => $fallbackLeverage,
                    'fallback_status' => 'pending',
                    'fallback_slippage_bps' => $slippageBps,
                    'fallback_trigger' => $fallbackTrigger,
                ],
            ),
        );
        $this->stateStore->saveOrder($parent);
        if (!$parentAlreadyExpired) {
            $this->appendEvent('order.expired', $parent, ['reason' => 'fallback_taker_triggered']);
        }
        if ($executionPrice < $policy->zoneMin || $executionPrice > $policy->zoneMax) {
            $parent = $this->withOrderStatus($parent, $parent->status, array_replace(
                $parent->metadata,
                [
                    'fallback_status' => 'rejected',
                    'fallback_reason' => 'fallback_price_outside_zone',
                ],
            ));
            $this->stateStore->saveOrder($parent);
            $this->appendEvent('fallback_taker.rejected', $parent, [
                'reason' => 'fallback_price_outside_zone',
                'slippage_bps' => $slippageBps,
            ]);
            $parent = $this->protectFallbackParentExposure($parent);

            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: false,
                reason: 'fallback_price_outside_zone',
                parentOrder: $parent,
                fallbackOrder: null,
                slippageBps: $slippageBps,
            );
        }
        if ($slippageBps > $policy->maxSlippageBps) {
            $parent = $this->withOrderStatus($parent, $parent->status, array_replace(
                $parent->metadata,
                [
                    'fallback_status' => 'rejected',
                    'fallback_reason' => 'fallback_slippage_exceeded',
                ],
            ));
            $this->stateStore->saveOrder($parent);
            $this->appendEvent('fallback_taker.rejected', $parent, [
                'reason' => 'fallback_slippage_exceeded',
                'slippage_bps' => $slippageBps,
            ]);
            $parent = $this->protectFallbackParentExposure($parent);

            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: false,
                reason: 'fallback_slippage_exceeded',
                parentOrder: $parent,
                fallbackOrder: null,
                slippageBps: $slippageBps,
            );
        }

        $fallbackQuantityDecimal = $this->orderRemainingQuantityDecimal($parent);
        $fallbackQuantity = (float) $fallbackQuantityDecimal;
        $parentFilledQuantityDecimal = $this->orderFilledQuantityDecimal($parent);
        $protectionQuantityDecimal = $this->orderQuantityDecimal($parent);
        $fallbackResult = $this->submitWithTrustedFallbackMetadata(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: $parent->marketType,
            symbol: $parent->symbol,
            side: $parent->side,
            positionSide: $parent->positionSide,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $fallbackQuantity,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: $fallbackLeverage,
            marginMode: $this->fallbackMarginMode($parent),
            clientOrderId: $fallbackClientOrderId,
            attachedStopLossPrice: $this->floatMetadata($parent->metadata, 'attached_stop_loss_price'),
            attachedTakeProfitPrice: $this->floatMetadata($parent->metadata, 'attached_take_profit_price'),
            metadata: $this->lineageMetadata($parent->metadata)
                + $policy->toMetadata()
                + $this->tp1TrailingPolicyMetadata($parent->metadata),
            quantityDecimal: $fallbackQuantityDecimal,
            attachedStopLossPriceDecimal: $this->stringMetadata(
                $parent->metadata,
                'attached_stop_loss_price_decimal',
            ),
            attachedTakeProfitPriceDecimal: $this->stringMetadata(
                $parent->metadata,
                'attached_take_profit_price_decimal',
            ),
        ), [
            'fallback_parent_order_id' => $parent->exchangeOrderId,
            'fallback_parent_client_order_id' => $parent->clientOrderId,
            'fallback_parent_filled_quantity' => $parent->filledQuantity,
            'fallback_parent_filled_quantity_decimal' => $parentFilledQuantityDecimal,
            'fallback_remainder_quantity' => $fallbackQuantity,
            'fallback_remainder_quantity_decimal' => $fallbackQuantityDecimal,
            'fallback_protection_quantity' => $parent->filledQuantity + $fallbackQuantity,
            'fallback_protection_quantity_decimal' => $protectionQuantityDecimal,
            'fallback_slippage_bps' => $slippageBps,
            'fallback_trigger' => $fallbackTrigger,
        ]);
        $fallback = $fallbackResult->order;
        if (!$fallback instanceof ExchangeOrderDto) {
            throw new \LogicException('fake_fallback_order_failed');
        }
        if (!$fallbackResult->accepted) {
            $reason = $this->stringMetadata($fallback->metadata, 'reason') ?? 'fallback_order_rejected';
            $parent = $this->withOrderStatus($parent, $parent->status, array_replace(
                $parent->metadata,
                [
                    'fallback_status' => 'rejected',
                    'fallback_reason' => $reason,
                    'fallback_order_id' => $fallback->exchangeOrderId,
                    'fallback_client_order_id' => $fallback->clientOrderId,
                    'fallback_quantity' => $fallbackQuantity,
                ],
            ));
            $this->stateStore->saveOrder($parent);
            $this->appendEvent('fallback_taker.rejected', $parent, [
                'reason' => $reason,
                'fallback_order_id' => $fallback->exchangeOrderId,
                'fallback_quantity' => $fallbackQuantity,
                'slippage_bps' => $slippageBps,
            ]);
            $parent = $this->protectFallbackParentExposure($parent);

            return new FakeFallbackTakerResult(
                executed: false,
                idempotentReplay: false,
                reason: $reason,
                parentOrder: $parent,
                fallbackOrder: $fallback,
                slippageBps: $slippageBps,
            );
        }
        if ($fallback->status !== ExchangeOrderStatus::FILLED) {
            throw new \LogicException('fake_fallback_order_failed');
        }

        $parent = $this->withOrderStatus($parent, $parent->status, array_replace(
            $parent->metadata,
            [
                'fallback_status' => 'completed',
                'fallback_order_id' => $fallback->exchangeOrderId,
                'fallback_client_order_id' => $fallback->clientOrderId,
                'fallback_quantity' => $fallbackQuantity,
            ],
        ));
        $this->stateStore->saveOrder($parent);
        $this->appendEvent('fallback_taker.completed', $parent, [
            'fallback_order_id' => $fallback->exchangeOrderId,
            'fallback_quantity' => $fallbackQuantity,
            'slippage_bps' => $slippageBps,
        ]);

        return new FakeFallbackTakerResult(
            executed: true,
            idempotentReplay: false,
            reason: 'fallback_completed',
            parentOrder: $parent,
            fallbackOrder: $fallback,
            slippageBps: $slippageBps,
        );
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function matchOpenOrders(string $symbol): array
    {
        $this->ratchetTrailingStops($symbol);

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

    private function ratchetTrailingStops(string $symbol): void
    {
        $openOrders = $this->stateStore->getOpenOrders($symbol);
        foreach ($openOrders as $order) {
            if ($this->isPersistedTrailingOrder($order)) {
                $this->assertPersistedActiveTrailingOrderValid($order);
            }
        }

        $midPrice = $this->midPrice($symbol);
        foreach ($openOrders as $order) {
            if (!$this->isActiveTrailingOrder($order)) {
                continue;
            }

            $policy = FakeTp1TrailingPolicy::fromMetadata($order->metadata);
            $watermark = $this->floatMetadata($order->metadata, 'trailing_watermark');
            if (
                !$policy instanceof FakeTp1TrailingPolicy
                || !$order->positionSide instanceof ExchangePositionSide
                || $order->stopPrice === null
                || $order->stopPrice <= 0.0
                || $watermark === null
                || $watermark <= 0.0
                || $this->stringMetadata($order->metadata, 'trailing_state_version') !== FakeTp1TrailingPolicy::VERSION
                || $this->stringMetadata($order->metadata, 'trailing_state_status') !== 'active'
                || $this->stringMetadata($order->metadata, 'parent_order_id') === null
                || $this->stringMetadata($order->metadata, 'trailing_activation_order_id') === null
            ) {
                throw new \LogicException('fake_tp1_trailing_persisted_state_invalid');
            }

            $favorable = $order->positionSide === ExchangePositionSide::LONG
                ? $midPrice > $watermark + 0.00000001
                : $midPrice < $watermark - 0.00000001;
            if (!$favorable) {
                continue;
            }

            try {
                $midPriceDecimal = BigDecimal::of(self::canonicalFloat($midPrice));
                $rawStopPriceDecimal = $order->positionSide === ExchangePositionSide::LONG
                    ? $midPriceDecimal->minus($policy->trailingOffset)
                    : $midPriceDecimal->plus($policy->trailingOffset);
                $newStopPriceDecimal = $this->quantizeRuntimeTrailingStop(
                    $order->symbol,
                    $order->positionSide,
                    $rawStopPriceDecimal,
                );
            } catch (MathException) {
                throw new \LogicException('fake_tp1_trailing_ratchet_invalid');
            }
            $newStopPrice = (float) (string) $newStopPriceDecimal;
            $monotone = $order->positionSide === ExchangePositionSide::LONG
                ? $newStopPrice >= $order->stopPrice - 0.00000001
                : $newStopPrice <= $order->stopPrice + 0.00000001;
            if (!\is_finite($newStopPrice) || $newStopPrice <= 0.0 || !$monotone) {
                throw new \LogicException('fake_tp1_trailing_ratchet_invalid');
            }
            $validation = $this->derivedProtectionValidation(
                marketType: $order->marketType,
                symbol: $order->symbol,
                positionSide: $order->positionSide,
                marginMode: 'isolated',
                orderType: ExchangeOrderType::STOP_LOSS,
                quantityDecimal: $this->orderRemainingQuantityDecimal($order),
                stopPriceDecimal: (string) $newStopPriceDecimal,
            );
            if (!$validation->accepted) {
                throw new \LogicException('fake_tp1_trailing_ratchet_invalid');
            }

            $metadata = array_replace($order->metadata, [
                'trailing_watermark' => $midPrice,
                'trailing_watermark_decimal' => (string) $midPriceDecimal,
                'stop_price_decimal' => (string) $newStopPriceDecimal,
            ]);
            $updated = $this->withTrailingState($order, $newStopPrice, $metadata);
            $this->stateStore->saveOrder($updated);
            $this->appendEvent('trailing_stop.updated', $updated, [
                'state_version' => FakeTp1TrailingPolicy::VERSION,
                'previous_watermark' => $watermark,
                'watermark' => $midPrice,
                'previous_stop_price' => $order->stopPrice,
                'stop_price' => $newStopPrice,
            ]);
        }
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
            'filled_quantity_decimal' => '0',
            'remaining_quantity_decimal' => $request->exactQuantity(),
            'price_decimal' => $request->exactPrice(),
            'stop_price_decimal' => $request->exactStopPrice(),
            'attached_stop_loss_price_decimal' => $request->exactAttachedStopLossPrice(),
            'attached_take_profit_price_decimal' => $request->exactAttachedTakeProfitPrice(),
            'margin_reference_price' => $marginReferencePrice,
            'margin_reference_source' => $marginReferencePrice !== null ? 'top_of_book' : null,
            'margin_contract_size' => $marginContractSize,
        ], static fn (mixed $value): bool => $value !== null)
            + $this->lineageMetadata($request->metadata)
            + $this->fallbackPolicyMetadata($request->metadata)
            + $this->tp1TrailingPolicyMetadata($request->metadata);
    }

    /**
     * @param array<string, bool|float|int|string|null> $trustedFallbackMetadata
     * @param array<string,mixed> ...$metadata
     */
    private function rejectOrder(
        PlaceOrderRequest $request,
        string $reason,
        array $trustedFallbackMetadata = [],
        array ...$metadata,
    ): PlaceOrderResult {
        $metadata[] = ['reason' => $reason];
        $rejectionMetadata = array_replace(
            $this->requestMetadata($request),
            $trustedFallbackMetadata,
            ...$metadata,
        );
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
        $trailingPolicy = FakeTp1TrailingPolicy::fromMetadata($entryOrder->metadata);
        if ($stopLoss === null && $takeProfit === null) {
            return $entryOrder;
        }
        if ($trailingPolicy instanceof FakeTp1TrailingPolicy) {
            try {
                $this->assertTp1QuantityBelowProtectedExposure($entryOrder, $trailingPolicy);
            } catch (\LogicException $exception) {
                if ($exception->getMessage() !== 'fake_tp1_trailing_quantity_invalid') {
                    throw $exception;
                }

                $metadata = array_replace($entryOrder->metadata, [
                    'attached_protection_processed' => true,
                    'protection_status' => 'rejected',
                    'protection_reject_reason' => 'fake_tp1_trailing_quantity_invalid',
                ]);
                $updated = $this->withOrderStatus($entryOrder, $entryOrder->status, $metadata);
                $this->stateStore->saveOrder($updated);
                $this->appendEvent('protection_order.rejected', $updated, [
                    'reason' => 'fake_tp1_trailing_quantity_invalid',
                ]);

                return $this->compensateRejectedProtection($updated);
            }
        }

        $metadata = array_replace($entryOrder->metadata, ['attached_protection_processed' => true]);

        if ($this->stateStore->consumeProtectionRejectionFlag()) {
            $metadata['protection_status'] = 'rejected';
            $metadata['protection_reject_reason'] = 'protection_rejected_by_scenario';
            $updated = $this->withOrderStatus($entryOrder, $entryOrder->status, $metadata);
            $this->stateStore->saveOrder($updated);
            $this->appendEvent('protection_order.rejected', $updated, ['reason' => 'protection_rejected_by_scenario']);

            return $this->compensateRejectedProtection($updated);
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
                $trailingPolicy instanceof FakeTp1TrailingPolicy ? 'tp1' : 'tp',
                $trailingPolicy?->tp1Quantity,
            )->exchangeOrderId;
        }

        $updated = $this->withOrderStatus($entryOrder, $entryOrder->status, $metadata);
        $this->stateStore->saveOrder($updated);

        return $updated;
    }

    private function protectFallbackParentExposure(ExchangeOrderDto $parent): ExchangeOrderDto
    {
        if ($parent->filledQuantity <= 0.00000001) {
            return $parent;
        }

        return $this->createAttachedProtectionOrders($parent);
    }

    private function assertTp1QuantityBelowProtectedExposure(
        ExchangeOrderDto $entryOrder,
        FakeTp1TrailingPolicy $policy,
    ): void {
        try {
            $tp1Quantity = BigDecimal::of($policy->tp1Quantity);
            $protectedExposure = BigDecimal::of(self::canonicalFloat(
                $this->protectionQuantity($entryOrder),
            ));
        } catch (MathException) {
            throw new \LogicException('fake_tp1_trailing_quantity_invalid');
        }
        if ($tp1Quantity->isGreaterThanOrEqualTo($protectedExposure)) {
            throw new \LogicException('fake_tp1_trailing_quantity_invalid');
        }
    }

    private function compensateRejectedProtection(ExchangeOrderDto $entryOrder): ExchangeOrderDto
    {
        if (!$entryOrder->positionSide instanceof ExchangePositionSide) {
            throw new \LogicException('fake_protection_compensation_position_side_unavailable');
        }

        $position = $this->stateStore->getPosition($entryOrder->symbol, $entryOrder->positionSide);
        if (!$position instanceof ExchangePositionDto || $position->size <= 0.00000001) {
            throw new \LogicException('fake_protection_compensation_position_unavailable');
        }
        $positionSizeBeforeCompensation = $position->size;
        $compensationQuantity = $this->protectionQuantity($entryOrder);
        if ($compensationQuantity <= 0.00000001) {
            throw new \LogicException('fake_protection_compensation_quantity_unavailable');
        }
        if ($positionSizeBeforeCompensation + 0.00000001 < $compensationQuantity) {
            throw new \LogicException('fake_protection_compensation_quantity_exceeds_position');
        }
        $expectedPositionSizeAfterCompensation = max(
            0.0,
            $positionSizeBeforeCompensation - $compensationQuantity,
        );

        $clientOrderId = 'fake-comp-' . substr(hash(
            'sha256',
            $entryOrder->exchangeOrderId . ':' . ($entryOrder->clientOrderId ?? ''),
        ), 0, 32);
        $leverage = $this->floatMetadata($entryOrder->metadata, 'leverage');
        $marginMode = $this->stringMetadata($entryOrder->metadata, 'margin_mode');
        if (!\in_array($marginMode, ['isolated', 'cross'], true)) {
            $marginMode = 'isolated';
        }

        $result = $this->submit(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: $entryOrder->marketType,
            symbol: $entryOrder->symbol,
            side: $entryOrder->positionSide === ExchangePositionSide::LONG
                ? ExchangeOrderSide::SELL
                : ExchangeOrderSide::BUY,
            positionSide: $entryOrder->positionSide,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $compensationQuantity,
            price: null,
            stopPrice: null,
            reduceOnly: true,
            postOnly: false,
            leverage: $leverage !== null && $leverage > 0.0 ? (int) $leverage : null,
            marginMode: $marginMode,
            clientOrderId: $clientOrderId,
            metadata: $this->lineageMetadata($entryOrder->metadata),
        ));
        $compensation = $result->order;
        if (
            !$result->accepted
            || !$compensation instanceof ExchangeOrderDto
            || $compensation->status !== ExchangeOrderStatus::FILLED
            || !$compensation->reduceOnly
        ) {
            throw new \LogicException('fake_protection_compensation_order_failed');
        }

        $remainingPosition = $this->stateStore->getPosition($entryOrder->symbol, $entryOrder->positionSide);
        $positionSizeAfterCompensation = $remainingPosition?->size ?? 0.0;
        if (abs($positionSizeAfterCompensation - $expectedPositionSizeAfterCompensation) > 0.00000001) {
            throw new \LogicException('fake_protection_compensation_position_size_mismatch');
        }
        $positionFlatAfterCompensation = $positionSizeAfterCompensation <= 0.00000001;
        $remainingPositionProtected = $positionFlatAfterCompensation || $this->hasStopCoverage(
            $entryOrder->symbol,
            $entryOrder->positionSide,
            $positionSizeAfterCompensation,
        );
        if (!$remainingPositionProtected) {
            throw new \LogicException('fake_protection_compensation_remaining_position_unprotected');
        }

        $updated = $this->withOrderStatus($entryOrder, $entryOrder->status, array_replace(
            $entryOrder->metadata,
            [
                'fail_safe_action' => 'reduce_only_market_close',
                'compensation_status' => 'completed',
                'compensation_outcome' => $positionFlatAfterCompensation
                    ? 'position_closed'
                    : 'entry_exposure_closed',
                'compensation_order_id' => $compensation->exchangeOrderId,
                'compensation_client_order_id' => $compensation->clientOrderId,
                'compensation_quantity' => $compensationQuantity,
                'position_size_before_compensation' => $positionSizeBeforeCompensation,
                'position_size_after_compensation' => $positionSizeAfterCompensation,
                'failed_entry_exposure_closed' => true,
                'remaining_position_protected_after_compensation' => $remainingPositionProtected,
                'position_flat_after_compensation' => $positionFlatAfterCompensation,
            ],
        ));
        $this->stateStore->saveOrder($updated);

        return $updated;
    }

    private function hasStopCoverage(
        string $symbol,
        ExchangePositionSide $positionSide,
        float $requiredQuantity,
    ): bool {
        $exitSide = $positionSide === ExchangePositionSide::LONG
            ? ExchangeOrderSide::SELL
            : ExchangeOrderSide::BUY;
        $coveredQuantity = 0.0;
        foreach ($this->stateStore->getOpenOrders($symbol) as $order) {
            if (
                !\in_array($order->orderType, [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TRIGGER], true)
                || !$order->reduceOnly
                || $order->positionSide !== $positionSide
                || $order->side !== $exitSide
            ) {
                continue;
            }

            $coveredQuantity += $order->remainingQuantity;
            if ($coveredQuantity + 0.00000001 >= $requiredQuantity) {
                return true;
            }
        }

        return false;
    }

    private function createProtectionOrder(
        ExchangeOrderDto $entryOrder,
        ExchangeOrderType $type,
        float $stopPrice,
        string $suffix,
        ?string $quantityDecimal = null,
    ): ExchangeOrderDto {
        $side = $entryOrder->positionSide === ExchangePositionSide::SHORT
            ? ExchangeOrderSide::BUY
            : ExchangeOrderSide::SELL;
        $metadata = $this->lineageMetadata($entryOrder->metadata)
            + $this->tp1TrailingPolicyMetadata($entryOrder->metadata)
            + [
                'source' => 'fake_exchange',
                'parent_order_id' => $entryOrder->exchangeOrderId,
                'parent_client_order_id' => $entryOrder->clientOrderId,
                'protection_kind' => $suffix,
            ];
        if (\array_key_exists('margin_contract_size', $entryOrder->metadata)) {
            $metadata['margin_contract_size'] = $entryOrder->metadata['margin_contract_size'];
        }
        $protectionQuantity = $quantityDecimal !== null
            ? (float) $quantityDecimal
            : $this->protectionQuantity($entryOrder);
        $metadata['quantity_decimal'] = $quantityDecimal ?? self::canonicalFloat($protectionQuantity);
        $metadata['filled_quantity_decimal'] = '0';
        $metadata['remaining_quantity_decimal'] = $metadata['quantity_decimal'];
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
            quantity: $protectionQuantity,
            filledQuantity: 0.0,
            remainingQuantity: $protectionQuantity,
            price: null,
            averagePrice: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            timeInForce: null,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );
        $this->stateStore->saveOrder($order);
        $this->appendEvent('protection_order.created', $order, ['parent_order_id' => $entryOrder->exchangeOrderId]);

        return $order;
    }

    private function activateTrailingStop(
        ExchangeOrderDto $tp1Order,
        float $positionRemainingQuantity,
        float $watermark,
    ): ExchangeOrderDto {
        $policy = FakeTp1TrailingPolicy::fromMetadata($tp1Order->metadata);
        $parentOrderId = $this->stringMetadata($tp1Order->metadata, 'parent_order_id');
        if (!$policy instanceof FakeTp1TrailingPolicy || $parentOrderId === null) {
            throw new \LogicException('fake_tp1_trailing_activation_state_invalid');
        }
        if (!$tp1Order->positionSide instanceof ExchangePositionSide) {
            throw new \LogicException('fake_tp1_trailing_position_side_unavailable');
        }
        $parentOrder = $this->stateStore->getOrder($parentOrderId);
        if (
            !$parentOrder instanceof ExchangeOrderDto
            || $parentOrder->symbol !== $tp1Order->symbol
            || $parentOrder->marketType !== $tp1Order->marketType
            || $parentOrder->positionSide !== $tp1Order->positionSide
        ) {
            throw new \LogicException('fake_tp1_trailing_activation_state_invalid');
        }

        try {
            $protectedQuantityDecimalValue = BigDecimal::of(self::canonicalFloat(
                $this->protectionQuantity($parentOrder),
            ));
            $quantityDecimalValue = $protectedQuantityDecimalValue
                ->minus($this->orderFilledQuantityDecimal($tp1Order))
                ->stripTrailingZeros();
            $watermarkDecimalValue = BigDecimal::of(self::canonicalFloat($watermark));
            $rawStopPriceDecimalValue = $tp1Order->positionSide === ExchangePositionSide::LONG
                ? $watermarkDecimalValue->minus($policy->trailingOffset)
                : $watermarkDecimalValue->plus($policy->trailingOffset);
            $stopPriceDecimalValue = $this->quantizeRuntimeTrailingStop(
                $tp1Order->symbol,
                $tp1Order->positionSide,
                $rawStopPriceDecimalValue,
            );
        } catch (MathException) {
            throw new \LogicException('fake_tp1_trailing_stop_invalid');
        }
        $quantityDecimal = (string) $quantityDecimalValue;
        $remainingQuantity = (float) $quantityDecimal;
        $watermarkDecimal = (string) $watermarkDecimalValue;
        $stopPriceDecimal = (string) $stopPriceDecimalValue;
        $stopPrice = (float) $stopPriceDecimal;
        if (
            !\is_finite($stopPrice)
            || $stopPrice <= 0.0
            || !\is_finite($remainingQuantity)
            || $remainingQuantity <= 0.00000001
            || $remainingQuantity > $positionRemainingQuantity + 0.00000001
        ) {
            throw new \LogicException('fake_tp1_trailing_stop_invalid');
        }
        $validation = $this->derivedProtectionValidation(
            marketType: $tp1Order->marketType,
            symbol: $tp1Order->symbol,
            positionSide: $tp1Order->positionSide,
            marginMode: 'isolated',
            orderType: ExchangeOrderType::STOP_LOSS,
            quantityDecimal: $quantityDecimal,
            stopPriceDecimal: $stopPriceDecimal,
        );
        if (!$validation->accepted) {
            $reason = $validation->reason ?? 'order_validation_failed';
            if (str_starts_with($reason, 'quantity_') || $reason === 'notional_below_minimum') {
                throw new \LogicException('fake_tp1_trailing_quantity_invalid');
            }

            throw new \LogicException('fake_tp1_trailing_stop_invalid');
        }

        $clientOrderId = 'fake-trailing-' . substr(hash(
            'sha256',
            $parentOrderId . ':' . $tp1Order->exchangeOrderId,
        ), 0, 32);
        $existing = $this->stateStore->getOrderByClientOrderId($tp1Order->symbol, $clientOrderId);

        $initialStops = [];
        foreach ($this->stateStore->getOpenOrders($tp1Order->symbol) as $candidate) {
            if (
                $candidate->exchangeOrderId !== $existing?->exchangeOrderId
                &&
                $candidate->orderType === ExchangeOrderType::STOP_LOSS
                && $this->stringMetadata($candidate->metadata, 'parent_order_id') === $parentOrderId
            ) {
                $initialStops[] = $candidate;
            }
        }
        if ($existing instanceof ExchangeOrderDto) {
            if ($initialStops !== []) {
                throw new \LogicException('fake_tp1_trailing_existing_child_with_initial_stop_active');
            }
            if (!$this->trailingChildMatchesActivation(
                $existing,
                $tp1Order,
                $remainingQuantity,
                $watermark,
                $stopPrice,
                $quantityDecimal,
                $watermarkDecimal,
                $stopPriceDecimal,
                $policy,
            )) {
                throw new \LogicException('fake_tp1_trailing_client_order_id_conflict');
            }

            return $existing;
        }

        if ($initialStops === []) {
            throw new \LogicException('fake_tp1_trailing_initial_stop_unavailable');
        }
        foreach ($initialStops as $initialStop) {
            if (
                $initialStop->stopPrice === null
                || !\is_finite($initialStop->stopPrice)
                || !self::sameDecimal(
                    $this->orderRemainingQuantityDecimal($initialStop),
                    (string) $protectedQuantityDecimalValue,
                )
                || (
                    $tp1Order->positionSide === ExchangePositionSide::LONG
                    && $stopPrice < $initialStop->stopPrice
                )
                || (
                    $tp1Order->positionSide === ExchangePositionSide::SHORT
                    && $stopPrice > $initialStop->stopPrice
                )
            ) {
                throw new \LogicException('fake_tp1_trailing_stop_looser_than_initial_stop');
            }
        }
        foreach ($initialStops as $initialStop) {
            $this->cancelOpenOrder($initialStop, 'tp1_replaced_by_trailing');
        }

        $metadata = $this->lineageMetadata($tp1Order->metadata)
            + $policy->toMetadata()
            + [
                'source' => 'fake_exchange',
                'parent_order_id' => $parentOrderId,
                'parent_client_order_id' => $this->stringMetadata($tp1Order->metadata, 'parent_client_order_id'),
                'protection_kind' => 'trailing',
                'trailing_state_version' => FakeTp1TrailingPolicy::VERSION,
                'trailing_state_status' => 'active',
                'trailing_activation_order_id' => $tp1Order->exchangeOrderId,
                'trailing_watermark' => $watermark,
                'trailing_watermark_decimal' => $watermarkDecimal,
                'quantity_decimal' => $quantityDecimal,
                'filled_quantity_decimal' => '0',
                'remaining_quantity_decimal' => $quantityDecimal,
                'stop_price_decimal' => $stopPriceDecimal,
            ];
        if (\array_key_exists('margin_contract_size', $tp1Order->metadata)) {
            $metadata['margin_contract_size'] = $tp1Order->metadata['margin_contract_size'];
        }

        $trailing = new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: $tp1Order->marketType,
            symbol: $tp1Order->symbol,
            exchangeOrderId: $this->stateStore->nextOrderId(),
            clientOrderId: $clientOrderId,
            side: $tp1Order->side,
            positionSide: $tp1Order->positionSide,
            orderType: ExchangeOrderType::TRIGGER,
            status: ExchangeOrderStatus::OPEN,
            quantity: $remainingQuantity,
            filledQuantity: 0.0,
            remainingQuantity: $remainingQuantity,
            price: null,
            averagePrice: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            timeInForce: null,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );
        $this->stateStore->saveOrder($trailing);
        $this->appendEvent('protection_order.created', $trailing, [
            'parent_order_id' => $parentOrderId,
        ]);
        $this->appendEvent('trailing_stop.armed', $trailing, [
            'parent_order_id' => $parentOrderId,
            'activation_order_id' => $tp1Order->exchangeOrderId,
            'state_version' => FakeTp1TrailingPolicy::VERSION,
            'watermark' => $watermark,
            'stop_price' => $stopPrice,
            'remaining_quantity' => $remainingQuantity,
        ]);

        return $trailing;
    }

    private function quantizeRuntimeTrailingStop(
        string $symbol,
        ExchangePositionSide $positionSide,
        BigDecimal $rawStopPrice,
    ): BigDecimal {
        $instrument = $this->instruments->find(strtoupper($symbol));
        if (!$instrument instanceof FakeInstrument) {
            throw new \LogicException('fake_tp1_trailing_stop_invalid');
        }

        try {
            $tick = BigDecimal::of($instrument->priceTick);
            $remainder = $rawStopPrice->remainder($tick);
            if ($remainder->isZero()) {
                return $rawStopPrice;
            }

            return $positionSide === ExchangePositionSide::LONG
                ? $rawStopPrice->minus($remainder)
                : $rawStopPrice->plus($tick->minus($remainder));
        } catch (MathException) {
            throw new \LogicException('fake_tp1_trailing_stop_invalid');
        }
    }

    private function trailingChildMatchesActivation(
        ExchangeOrderDto $trailing,
        ExchangeOrderDto $tp1Order,
        float $remainingQuantity,
        float $watermark,
        float $stopPrice,
        string $quantityDecimal,
        string $watermarkDecimal,
        string $stopPriceDecimal,
        FakeTp1TrailingPolicy $policy,
    ): bool {
        $parentOrderId = $this->stringMetadata($tp1Order->metadata, 'parent_order_id');
        $parentClientOrderId = $this->stringMetadata($tp1Order->metadata, 'parent_client_order_id');
        if ($parentOrderId === null) {
            return false;
        }
        $clientOrderId = 'fake-trailing-' . substr(hash(
            'sha256',
            $parentOrderId . ':' . $tp1Order->exchangeOrderId,
        ), 0, 32);
        $expectedPolicy = $policy->toMetadata();

        return $trailing->exchange === $tp1Order->exchange
            && $trailing->symbol === $tp1Order->symbol
            && $trailing->marketType === $tp1Order->marketType
            && $trailing->clientOrderId === $clientOrderId
            && $trailing->side === $tp1Order->side
            && $trailing->positionSide === $tp1Order->positionSide
            && $trailing->orderType === ExchangeOrderType::TRIGGER
            && $trailing->status === ExchangeOrderStatus::OPEN
            && $trailing->reduceOnly
            && !$trailing->postOnly
            && $trailing->timeInForce === null
            && $trailing->quantity === $remainingQuantity
            && $trailing->filledQuantity === 0.0
            && $trailing->remainingQuantity === $remainingQuantity
            && $trailing->price === null
            && $trailing->averagePrice === null
            && $trailing->stopPrice === $stopPrice
            && $this->stringMetadata($trailing->metadata, 'source') === 'fake_exchange'
            && $this->stringMetadata($trailing->metadata, 'protection_kind') === 'trailing'
            && $this->stringMetadata($trailing->metadata, 'parent_order_id') === $parentOrderId
            && $this->stringMetadata($trailing->metadata, 'parent_client_order_id') === $parentClientOrderId
            && $this->lineageMetadata($trailing->metadata) === $this->lineageMetadata($tp1Order->metadata)
            && $this->exactMetadataValuesMatch($trailing->metadata, $expectedPolicy)
            && $this->stringMetadata($trailing->metadata, 'trailing_state_version') === FakeTp1TrailingPolicy::VERSION
            && $this->stringMetadata($trailing->metadata, 'trailing_state_status') === 'active'
            && $this->stringMetadata($trailing->metadata, 'trailing_activation_order_id') === $tp1Order->exchangeOrderId
            && ($trailing->metadata['trailing_watermark'] ?? null) === $watermark
            && ($trailing->metadata['quantity_decimal'] ?? null) === $quantityDecimal
            && ($trailing->metadata['filled_quantity_decimal'] ?? null) === '0'
            && ($trailing->metadata['remaining_quantity_decimal'] ?? null) === $quantityDecimal
            && ($trailing->metadata['stop_price_decimal'] ?? null) === $stopPriceDecimal
            && ($trailing->metadata['trailing_watermark_decimal'] ?? null) === $watermarkDecimal
            && $this->optionalExactMetadataValueMatches(
                $trailing->metadata,
                $tp1Order->metadata,
                'margin_contract_size',
            );
    }

    /**
     * @param array<string,mixed> $actual
     * @param array<string,bool|string> $expected
     */
    private function exactMetadataValuesMatch(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!\array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $expected
     */
    private function optionalExactMetadataValueMatches(array $actual, array $expected, string $key): bool
    {
        if (!\array_key_exists($key, $expected)) {
            return !\array_key_exists($key, $actual);
        }

        return \array_key_exists($key, $actual) && $actual[$key] === $expected[$key];
    }

    private function isTp1ProtectionOrder(ExchangeOrderDto $order): bool
    {
        return $order->orderType === ExchangeOrderType::TAKE_PROFIT
            && $this->stringMetadata($order->metadata, 'protection_kind') === 'tp1';
    }

    private function isActiveTrailingOrder(ExchangeOrderDto $order): bool
    {
        return $order->orderType === ExchangeOrderType::TRIGGER
            && $this->stringMetadata($order->metadata, 'protection_kind') === 'trailing';
    }

    private function isPersistedTrailingOrder(ExchangeOrderDto $order): bool
    {
        return $this->stringMetadata($order->metadata, 'protection_kind') === 'trailing'
            || \array_key_exists('trailing_state_version', $order->metadata)
            || \array_key_exists('trailing_state_status', $order->metadata)
            || \array_key_exists('trailing_activation_order_id', $order->metadata)
            || \array_key_exists('trailing_watermark', $order->metadata)
            || \array_key_exists('trailing_watermark_decimal', $order->metadata);
    }

    private function assertPersistedActiveTrailingOrderValid(ExchangeOrderDto $order): void
    {
        try {
            $policy = FakeTp1TrailingPolicy::fromMetadata($order->metadata);
        } catch (\InvalidArgumentException) {
            throw new \LogicException('fake_tp1_trailing_persisted_state_invalid');
        }

        $positionSide = $order->positionSide;
        $expectedSide = $positionSide === ExchangePositionSide::LONG
            ? ExchangeOrderSide::SELL
            : ExchangeOrderSide::BUY;
        $quantityDecimal = $order->metadata['quantity_decimal'] ?? null;
        $filledQuantityDecimal = $order->metadata['filled_quantity_decimal'] ?? null;
        $remainingQuantityDecimal = $order->metadata['remaining_quantity_decimal'] ?? null;
        $stopPriceDecimal = $order->metadata['stop_price_decimal'] ?? null;
        $watermark = $order->metadata['trailing_watermark'] ?? null;
        $watermarkDecimal = $order->metadata['trailing_watermark_decimal'] ?? null;
        if (
            !$policy instanceof FakeTp1TrailingPolicy
            || !$positionSide instanceof ExchangePositionSide
            || !$order->reduceOnly
            || $order->side !== $expectedSide
            || $order->orderType !== ExchangeOrderType::TRIGGER
            || $order->status !== ExchangeOrderStatus::OPEN
            || !\is_finite($order->quantity)
            || $order->quantity <= 0.0
            || $order->filledQuantity !== 0.0
            || !\is_finite($order->remainingQuantity)
            || $order->remainingQuantity <= 0.0
            || !\is_string($quantityDecimal)
            || !\is_string($filledQuantityDecimal)
            || !\is_string($remainingQuantityDecimal)
            || !\is_string($stopPriceDecimal)
            || $order->stopPrice === null
            || !\is_finite($order->stopPrice)
            || $order->stopPrice <= 0.0
            || !\is_scalar($watermark)
            || !is_numeric($watermark)
            || !\is_finite((float) $watermark)
            || (float) $watermark <= 0.0
            || !\is_string($watermarkDecimal)
            || $this->stringMetadata($order->metadata, 'protection_kind') !== 'trailing'
            || $this->stringMetadata($order->metadata, 'trailing_state_version') !== FakeTp1TrailingPolicy::VERSION
            || $this->stringMetadata($order->metadata, 'trailing_state_status') !== 'active'
            || $this->stringMetadata($order->metadata, 'parent_order_id') === null
            || $this->stringMetadata($order->metadata, 'trailing_activation_order_id') === null
            || !self::sameDecimal($quantityDecimal, self::canonicalFloat($order->quantity))
            || !self::sameDecimal($filledQuantityDecimal, '0')
            || !self::sameDecimal($remainingQuantityDecimal, self::canonicalFloat($order->remainingQuantity))
            || !self::sameDecimal($quantityDecimal, $remainingQuantityDecimal)
            || !self::sameDecimal(
                self::canonicalFloat($order->quantity),
                self::canonicalFloat($order->remainingQuantity),
            )
            || !self::sameDecimal($stopPriceDecimal, self::canonicalFloat($order->stopPrice))
            || !self::sameDecimal($watermarkDecimal, self::canonicalFloat((float) $watermark))
        ) {
            throw new \LogicException('fake_tp1_trailing_persisted_state_invalid');
        }

        $validation = $this->derivedProtectionValidation(
            marketType: $order->marketType,
            symbol: $order->symbol,
            positionSide: $positionSide,
            marginMode: 'isolated',
            orderType: ExchangeOrderType::STOP_LOSS,
            quantityDecimal: $remainingQuantityDecimal,
            stopPriceDecimal: $stopPriceDecimal,
        );
        if (!$validation->accepted) {
            throw new \LogicException('fake_tp1_trailing_persisted_state_invalid');
        }
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function withTrailingState(
        ExchangeOrderDto $order,
        float $stopPrice,
        array $metadata,
    ): ExchangeOrderDto {
        return new ExchangeOrderDto(
            exchange: $order->exchange,
            marketType: $order->marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            side: $order->side,
            positionSide: $order->positionSide,
            orderType: $order->orderType,
            status: $order->status,
            quantity: $order->quantity,
            filledQuantity: $order->filledQuantity,
            remainingQuantity: $order->remainingQuantity,
            price: $order->price,
            averagePrice: $order->averagePrice,
            stopPrice: $stopPrice,
            reduceOnly: $order->reduceOnly,
            postOnly: $order->postOnly,
            timeInForce: $order->timeInForce,
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            metadata: $metadata,
        );
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

    private function applyPositionFill(
        ExchangeOrderDto $order,
        float $fillQuantity,
        float $executionPrice,
        FakeFillCost $fillCost,
    ): void
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
                $fillCost,
            );
            try {
                $remainingSize = max(0.0, (float) (string) BigDecimal::of(self::canonicalFloat($existing->size))
                    ->minus(self::canonicalFloat($fillQuantity))
                    ->stripTrailingZeros());
            } catch (MathException) {
                throw new \LogicException('fake_position_remaining_quantity_invalid');
            }
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
            if ($this->isTp1ProtectionOrder($order)) {
                if ($order->status === ExchangeOrderStatus::FILLED) {
                    $this->activateTrailingStop($order, $remainingSize, $executionPrice);
                }
            } elseif ($this->isTriggerOrder($order)) {
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
                $fillCost,
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
        FakeFillCost $fillCost,
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
            'entry_spread_cost_usdt' => $this->metadataFloat($metadata, 'entry_spread_cost_usdt') + $fillCost->spreadCostUsdt,
            'entry_slippage_cost_usdt' => $this->metadataFloat($metadata, 'entry_slippage_cost_usdt') + $fillCost->slippageCostUsdt,
            'cost_model_version' => $fillCost->modelVersion,
            'spread_model_version' => $fillCost->spreadModelVersion,
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
        FakeFillCost $fillCost,
    ): array
    {
        return array_replace($metadata, [
            'exit_qty' => $this->metadataFloat($metadata, 'exit_qty') + $quantity,
            'exit_notional_usdt' => $this->metadataFloat($metadata, 'exit_notional_usdt') + ($quantity * $price * $contractSize),
            'exit_fee_usdt' => $this->metadataFloat($metadata, 'exit_fee_usdt') + $fee,
            'exit_spread_cost_usdt' => $this->metadataFloat($metadata, 'exit_spread_cost_usdt') + $fillCost->spreadCostUsdt,
            'exit_slippage_cost_usdt' => $this->metadataFloat($metadata, 'exit_slippage_cost_usdt') + $fillCost->slippageCostUsdt,
            'cost_model_version' => $fillCost->modelVersion,
            'spread_model_version' => $fillCost->spreadModelVersion,
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
        $spreadCost = $this->metadataFloat($closeLedger, 'entry_spread_cost_usdt')
            + $this->metadataFloat($closeLedger, 'exit_spread_cost_usdt');
        $slippageCost = $this->metadataFloat($closeLedger, 'entry_slippage_cost_usdt')
            + $this->metadataFloat($closeLedger, 'exit_slippage_cost_usdt');
        $entryOrderCount = max(1, (int) round($this->metadataFloat($closeLedger, 'entry_order_count')));
        $lineageSufficient = $entryOrderCount <= 1;
        $gross = $position->side === ExchangePositionSide::SHORT
            ? $entryNotional - $exitNotional
            : $exitNotional - $entryNotional;

        return $this->lineageMetadata($closeLedger) + [
            'gross_realized_pnl_usdt' => round($gross, 12),
            'recorded_pnl_usdt' => round($gross - $entryFee - $exitFee - $spreadCost - $slippageCost, 12),
            'entry_fee_usdt' => round($entryFee, 12),
            'exit_fee_usdt' => round($exitFee, 12),
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => round($spreadCost, 12),
            'slippage_cost_usdt' => round($slippageCost, 12),
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'cost_model_version' => $this->stringMetadata($closeLedger, 'cost_model_version'),
            'spread_model_version' => $this->stringMetadata($closeLedger, 'spread_model_version'),
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
     * @return array<string,mixed>
     */
    private function fallbackPolicyMetadata(array $metadata): array
    {
        $fallback = [];
        foreach (self::FALLBACK_POLICY_METADATA_KEYS as $key) {
            if (!\array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if ($value === null || \is_scalar($value)) {
                $fallback[$key] = $value;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function tp1TrailingPolicyMetadata(array $metadata): array
    {
        $policy = [];
        foreach (FakeTp1TrailingPolicy::METADATA_KEYS as $key) {
            if (!\array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if ($value === null || \is_scalar($value)) {
                $policy[$key] = $value;
            }
        }

        return $policy;
    }

    private function protectionQuantity(ExchangeOrderDto $entryOrder): float
    {
        $fallbackParentOrderId = $this->stringMetadata(
            $entryOrder->metadata,
            'fallback_parent_order_id',
        );
        if ($fallbackParentOrderId === null) {
            $quantity = $entryOrder->filledQuantity;
        } else {
            $parent = $this->stateStore->getOrder($fallbackParentOrderId);
            if (
                !$parent instanceof ExchangeOrderDto
                || $entryOrder->clientOrderId !== $this->fallbackClientOrderId($parent)
                || $entryOrder->symbol !== $parent->symbol
                || $entryOrder->marketType !== $parent->marketType
                || $entryOrder->side !== $parent->side
                || $entryOrder->positionSide !== $parent->positionSide
                || $entryOrder->orderType !== ExchangeOrderType::MARKET
                || $entryOrder->reduceOnly
                || $entryOrder->postOnly
                || !self::sameDecimal(
                    $this->decimalMetadata(
                        $entryOrder->metadata,
                        'fallback_parent_filled_quantity_decimal',
                    ) ?? '',
                    $this->orderFilledQuantityDecimal($parent),
                )
                || !self::sameDecimal(
                    $this->decimalMetadata(
                        $entryOrder->metadata,
                        'fallback_remainder_quantity_decimal',
                    ) ?? '',
                    $this->orderQuantityDecimal($entryOrder),
                )
                || !self::sameDecimal(
                    $this->decimalMetadata(
                        $entryOrder->metadata,
                        'fallback_protection_quantity_decimal',
                    ) ?? '',
                    $this->orderQuantityDecimal($parent),
                )
            ) {
                throw new \LogicException('fake_fallback_protection_lineage_invalid');
            }

            try {
                $quantity = (float) (string) BigDecimal::of($this->orderFilledQuantityDecimal($parent))
                    ->plus($this->orderFilledQuantityDecimal($entryOrder))
                    ->stripTrailingZeros();
            } catch (MathException) {
                throw new \LogicException('fake_fallback_protection_quantity_invalid');
            }
        }
        if (!\is_finite($quantity) || $quantity <= 0.00000001) {
            throw new \LogicException('fake_protection_quantity_unavailable');
        }

        return $quantity;
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

    private function orderQuantityDecimal(ExchangeOrderDto $order): string
    {
        return $this->decimalMetadata($order->metadata, 'quantity_decimal')
            ?? self::canonicalFloat($order->quantity);
    }

    private function orderFilledQuantityDecimal(ExchangeOrderDto $order): string
    {
        return $this->decimalMetadata($order->metadata, 'filled_quantity_decimal')
            ?? self::canonicalFloat($order->filledQuantity);
    }

    private function orderRemainingQuantityDecimal(ExchangeOrderDto $order): string
    {
        $persisted = $this->decimalMetadata($order->metadata, 'remaining_quantity_decimal');
        if ($persisted !== null) {
            return $persisted;
        }

        try {
            return (string) BigDecimal::of($this->orderQuantityDecimal($order))
                ->minus($this->orderFilledQuantityDecimal($order))
                ->stripTrailingZeros();
        } catch (MathException) {
            throw new \LogicException('fake_order_remaining_quantity_decimal_invalid');
        }
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function decimalMetadata(array $metadata, string $key): ?string
    {
        $value = $this->stringMetadata($metadata, $key);
        if ($value === null) {
            return null;
        }

        try {
            return (string) BigDecimal::of($value)->stripTrailingZeros();
        } catch (MathException) {
            return null;
        }
    }

    private function fallbackSlippageBps(ExchangeOrderDto $parent, float $executionPrice): float
    {
        if ($parent->price === null || $parent->price <= 0.0) {
            throw new \LogicException('fake_fallback_reference_price_unavailable');
        }

        $adverseDifference = $parent->side === ExchangeOrderSide::BUY
            ? max(0.0, $executionPrice - $parent->price)
            : max(0.0, $parent->price - $executionPrice);

        return round(
            (($adverseDifference / $parent->price) * 10_000.0)
            + FakeFillCostModel::TAKER_SLIPPAGE_BPS,
            12,
        );
    }

    private function fallbackClientOrderId(ExchangeOrderDto $parent): string
    {
        return 'fake-fallback-' . substr(hash(
            'sha256',
            $parent->exchangeOrderId . ':' . ($parent->clientOrderId ?? ''),
        ), 0, 32);
    }

    private function fallbackMarginMode(ExchangeOrderDto $parent): string
    {
        $marginMode = $this->stringMetadata($parent->metadata, 'margin_mode');

        return \in_array($marginMode, ['isolated', 'cross'], true) ? $marginMode : 'isolated';
    }

    private function fallbackChildMatchesParent(
        ExchangeOrderDto $parent,
        ExchangeOrderDto $fallback,
    ): bool {
        $parentFilledQuantity = $this->floatMetadata(
            $fallback->metadata,
            'fallback_parent_filled_quantity',
        );
        $remainderQuantity = $this->floatMetadata(
            $fallback->metadata,
            'fallback_remainder_quantity',
        );
        $protectionQuantity = $this->floatMetadata(
            $fallback->metadata,
            'fallback_protection_quantity',
        );
        $parentFilledQuantityDecimal = $this->decimalMetadata(
            $fallback->metadata,
            'fallback_parent_filled_quantity_decimal',
        );
        $remainderQuantityDecimal = $this->decimalMetadata(
            $fallback->metadata,
            'fallback_remainder_quantity_decimal',
        );
        $protectionQuantityDecimal = $this->decimalMetadata(
            $fallback->metadata,
            'fallback_protection_quantity_decimal',
        );
        $expectedParentFilledQuantityDecimal = $this->orderFilledQuantityDecimal($parent);
        $expectedRemainderQuantityDecimal = $this->orderRemainingQuantityDecimal($parent);
        $expectedProtectionQuantityDecimal = $this->orderQuantityDecimal($parent);
        if (
            $parentFilledQuantity === null
            || $remainderQuantity === null
            || $protectionQuantity === null
            || $parentFilledQuantityDecimal === null
            || $remainderQuantityDecimal === null
            || $protectionQuantityDecimal === null
            || $fallback->clientOrderId !== $this->fallbackClientOrderId($parent)
            || $fallback->symbol !== $parent->symbol
            || $fallback->marketType !== $parent->marketType
            || $fallback->side !== $parent->side
            || $fallback->positionSide !== $parent->positionSide
            || $fallback->orderType !== ExchangeOrderType::MARKET
            || $fallback->timeInForce !== ExchangeTimeInForce::GTC
            || $fallback->reduceOnly
            || $fallback->postOnly
            || $fallback->price !== null
            || $fallback->stopPrice !== null
            || abs($fallback->quantity - $parent->remainingQuantity) > 0.00000001
            || !self::sameDecimal(
                $this->orderQuantityDecimal($fallback),
                $expectedRemainderQuantityDecimal,
            )
            || $this->stringMetadata($fallback->metadata, 'fallback_parent_order_id')
                !== $parent->exchangeOrderId
            || $this->stringMetadata($fallback->metadata, 'fallback_parent_client_order_id')
                !== $parent->clientOrderId
            || abs($parentFilledQuantity - $parent->filledQuantity) > 0.00000001
            || abs($remainderQuantity - $parent->remainingQuantity) > 0.00000001
            || abs(
                $protectionQuantity - ($parent->filledQuantity + $parent->remainingQuantity),
            ) > 0.00000001
            || !self::sameDecimal(
                $parentFilledQuantityDecimal,
                $expectedParentFilledQuantityDecimal,
            )
            || !self::sameDecimal($remainderQuantityDecimal, $expectedRemainderQuantityDecimal)
            || !self::sameDecimal($protectionQuantityDecimal, $expectedProtectionQuantityDecimal)
            || $this->positiveIntMetadata($fallback->metadata, 'leverage')
                !== $this->positiveIntMetadata($parent->metadata, 'leverage')
            || $this->fallbackMarginMode($fallback) !== $this->fallbackMarginMode($parent)
            || !$this->sameOptionalDecimalMetadata(
                $fallback->metadata,
                $parent->metadata,
                'attached_stop_loss_price_decimal',
            )
            || !$this->sameOptionalDecimalMetadata(
                $fallback->metadata,
                $parent->metadata,
                'attached_take_profit_price_decimal',
            )
        ) {
            return false;
        }

        $parentPolicy = FakeFallbackTakerPolicy::fromMetadata($parent->metadata);
        $fallbackPolicy = FakeFallbackTakerPolicy::fromMetadata($fallback->metadata);
        if (!$parentPolicy instanceof FakeFallbackTakerPolicy || !$fallbackPolicy instanceof FakeFallbackTakerPolicy) {
            return false;
        }
        if (
            $parentPolicy->enabled !== $fallbackPolicy->enabled
            || $parentPolicy->zoneMin !== $fallbackPolicy->zoneMin
            || $parentPolicy->zoneMax !== $fallbackPolicy->zoneMax
            || $parentPolicy->maxSlippageBps !== $fallbackPolicy->maxSlippageBps
        ) {
            return false;
        }

        $terminalStatusMatches = match ($fallback->status) {
            ExchangeOrderStatus::FILLED => ($parent->metadata['fallback_status'] ?? null) === 'completed'
                && abs($fallback->filledQuantity - $fallback->quantity) <= 0.00000001,
            ExchangeOrderStatus::REJECTED => ($parent->metadata['fallback_status'] ?? null) === 'rejected'
                && $fallback->filledQuantity <= 0.00000001,
            default => false,
        };

        return $parent->status === ExchangeOrderStatus::EXPIRED
            && $terminalStatusMatches
            && $this->tp1TrailingPolicyMatchesRequest($fallback->metadata, $parent->metadata)
            && $this->stringMetadata($parent->metadata, 'fallback_order_id') === $fallback->exchangeOrderId
            && $this->stringMetadata($parent->metadata, 'fallback_client_order_id') === $fallback->clientOrderId
            && $this->stringMetadata($parent->metadata, 'fallback_trigger')
                === $this->stringMetadata($fallback->metadata, 'fallback_trigger');
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function sameOptionalDecimalMetadata(array $left, array $right, string $key): bool
    {
        $leftValue = $this->stringMetadata($left, $key);
        $rightValue = $this->stringMetadata($right, $key);
        if ($leftValue === null || $rightValue === null) {
            return $leftValue === $rightValue;
        }

        return self::sameDecimal($leftValue, $rightValue);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function positiveIntMetadata(array $metadata, string $key): ?int
    {
        $value = $metadata[$key] ?? null;
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
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

    /**
     * @param array<string,bool|float|int|string|null> $trustedMetadata
     */
    private function assertRequestIntent(
        PlaceOrderRequest $request,
        array $trustedMetadata = [],
    ): void
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

        $trailingPolicy = FakeTp1TrailingPolicy::fromMetadata($request->metadata);
        if (!$trailingPolicy instanceof FakeTp1TrailingPolicy) {
            return;
        }
        if (
            $reduceIntent
            || $request->attachedStopLossPrice === null
            || $request->attachedTakeProfitPrice === null
        ) {
            throw new \InvalidArgumentException('fake_tp1_trailing_protection_required');
        }

        try {
            $tp1Quantity = BigDecimal::of($trailingPolicy->tp1Quantity);
            $logicalQuantity = $this->stringMetadata(
                $trustedMetadata,
                'fallback_protection_quantity_decimal',
            ) ?? $request->exactQuantity() ?? '';
            $entryQuantity = BigDecimal::of($logicalQuantity);
            $tp1Price = BigDecimal::of($request->exactAttachedTakeProfitPrice() ?? '');
            $trailingQuantity = $entryQuantity->minus($tp1Quantity);
            $initialTrailingStop = $request->positionSide === ExchangePositionSide::LONG
                ? $tp1Price->minus($trailingPolicy->trailingOffset)
                : $tp1Price->plus($trailingPolicy->trailingOffset);
        } catch (MathException) {
            throw new \InvalidArgumentException('fake_tp1_trailing_quantity_invalid');
        }
        if ($tp1Quantity->isGreaterThanOrEqualTo($entryQuantity)) {
            throw new \InvalidArgumentException('fake_tp1_trailing_quantity_invalid');
        }

        $this->assertDerivedProtectionValid(
            $request,
            ExchangeOrderType::TAKE_PROFIT,
            (string) $tp1Quantity,
            (string) $tp1Price,
        );
        $this->assertDerivedProtectionValid(
            $request,
            ExchangeOrderType::STOP_LOSS,
            (string) $trailingQuantity,
            (string) $initialTrailingStop,
        );
    }

    private function assertDerivedProtectionValid(
        PlaceOrderRequest $entryRequest,
        ExchangeOrderType $orderType,
        string $quantityDecimal,
        string $stopPriceDecimal,
    ): void {
        $validation = $this->derivedProtectionValidation(
            marketType: $entryRequest->marketType,
            symbol: $entryRequest->symbol,
            positionSide: $entryRequest->positionSide,
            marginMode: $entryRequest->marginMode,
            orderType: $orderType,
            quantityDecimal: $quantityDecimal,
            stopPriceDecimal: $stopPriceDecimal,
        );
        if ($validation->accepted) {
            return;
        }

        $reason = $validation->reason ?? 'order_validation_failed';
        if (
            str_starts_with($reason, 'quantity_')
            || $reason === 'notional_below_minimum'
        ) {
            throw new \InvalidArgumentException('fake_tp1_trailing_quantity_invalid');
        }

        throw new \InvalidArgumentException('fake_tp1_trailing_stop_invalid');
    }

    private function derivedProtectionValidation(
        MarketType $marketType,
        string $symbol,
        ExchangePositionSide $positionSide,
        string $marginMode,
        ExchangeOrderType $orderType,
        string $quantityDecimal,
        string $stopPriceDecimal,
    ): FakeOrderValidationResult {
        $quantity = (float) $quantityDecimal;
        $stopPrice = (float) $stopPriceDecimal;
        if (
            !\is_finite($quantity)
            || $quantity <= 0.0
            || !\is_finite($stopPrice)
            || $stopPrice <= 0.0
        ) {
            return FakeOrderValidationResult::rejected('stop_price_not_quantized');
        }

        $side = $positionSide === ExchangePositionSide::LONG
            ? ExchangeOrderSide::SELL
            : ExchangeOrderSide::BUY;

        return $this->orderValidator->validate(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: $marketType,
            symbol: $symbol,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            leverage: null,
            marginMode: $marginMode,
            clientOrderId: 'fake-tp1-trailing-capability-validation',
            quantityDecimal: $quantityDecimal,
            stopPriceDecimal: $stopPriceDecimal,
        ), $stopPrice, 0.0);
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
        if (!$this->tp1TrailingPolicyMatchesRequest($order->metadata, $request->metadata)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $persistedMetadata
     * @param array<string,mixed> $requestMetadata
     */
    private function tp1TrailingPolicyMatchesRequest(
        array $persistedMetadata,
        array $requestMetadata,
    ): bool {
        $persisted = FakeTp1TrailingPolicy::fromMetadata($persistedMetadata);
        $requested = FakeTp1TrailingPolicy::fromMetadata($requestMetadata);
        if (!$persisted instanceof FakeTp1TrailingPolicy || !$requested instanceof FakeTp1TrailingPolicy) {
            return $persisted === null && $requested === null;
        }

        return self::sameDecimal($persisted->tp1Quantity, $requested->tp1Quantity)
            && self::sameDecimal($persisted->trailingOffset, $requested->trailingOffset);
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
