<?php

declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Common\Enum\Exchange;
use App\Config\TradeEntryConfigResolver;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\TradeEntry\Policy\OrderModePolicyInterface;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExchangeExecutionService
{
    public function __construct(
        private readonly ExchangeAdapterRegistryInterface $adapters,
        private readonly ProtectionEnforcer $protectionEnforcer,
        private readonly IdempotencyPolicy $idempotency,
        private readonly OrderModePolicyInterface $orderModePolicy,
        private readonly TradeEntryConfigResolver $tradeEntryConfigResolver,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {
    }

    public function execute(
        OrderPlanModel $plan,
        ?string $decisionKey = null,
        ?string $mode = null,
        ?string $executionTf = null,
        ?string $clientOrderId = null,
        ?int $orderIntentId = null,
        bool $planPrepared = false,
    ): ExecutionResult
    {
        if (!$planPrepared) {
            $plan = $this->preparePlan($plan, $mode, $executionTf, $decisionKey);
        }

        if ($plan->size < 1) {
            return $this->skipBelowMinimum($plan, $decisionKey, 'size_below_min', 'size', $plan->size, 1, $clientOrderId);
        }
        if ($plan->leverage < 1) {
            return $this->skipBelowMinimum($plan, $decisionKey, 'leverage_below_min', 'leverage', $plan->leverage, 1, $clientOrderId);
        }

        $context = ExchangeContext::resolve($plan->exchangeContext);
        $adapter = $this->adapters->get($context->exchange, $context->marketType);
        $clientOrderId ??= $this->idempotency->newClientOrderId($decisionKey);
        $capabilities = $adapter->capabilities();
        if ($this->shouldRejectUnprotectableBitmartMarket($context, $plan, $capabilities)) {
            $this->positionsLogger->error('exchange_execution.entry_rejected_unprotectable_market', [
                'symbol' => $plan->symbol,
                'exchange' => $context->exchange->value,
                'market_type' => $context->marketType->value,
                'order_type' => $plan->orderType,
                'client_order_id' => $clientOrderId,
                'decision_key' => $decisionKey,
                'reason' => 'bitmart_market_entry_without_protection_path',
            ]);

            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: null,
                status: ExecutionResult::STATUS_ERROR,
                raw: [
                    'reason' => 'bitmart_market_entry_without_protection_path',
                    'exchange' => $context->exchange->value,
                    'market_type' => $context->marketType->value,
                    'order_type' => $plan->orderType,
                    'supports_trigger_orders' => $capabilities->supportsTriggerOrders,
                    'supports_attached_stop_loss_on_entry' => $capabilities->supportsAttachedStopLossOnEntry,
                ],
            );
        }
        $attachedStopLossRequested = $capabilities->supportsAttachedStopLossOnEntry
            && $plan->stop > 0.0
            && !($context->exchange === Exchange::BITMART && $plan->orderType === 'market');
        $attachedTakeProfitRequested = $attachedStopLossRequested
            && $capabilities->supportsAttachedTakeProfitOnEntry
            && $plan->takeProfit > 0.0;

        $this->positionsLogger->info('exchange_execution.entry_submitted', [
            'symbol' => $plan->symbol,
            'exchange' => $context->exchange->value,
            'market_type' => $context->marketType->value,
            'order_type' => $plan->orderType,
            'side' => $plan->side->value,
            'client_order_id' => $clientOrderId,
            'attached_stop_loss_requested' => $attachedStopLossRequested,
            'attached_take_profit_requested' => $attachedTakeProfitRequested,
            'decision_key' => $decisionKey,
        ]);

        $leverageSet = null;
        if ($plan->leverage > 0 && ($capabilities->requiresSeparateLeverageSubmit || $capabilities->supportsPerSymbolLeverage)) {
            $leverageSet = $adapter->setLeverage($plan->symbol, $plan->leverage, $plan->openType);
        }

        try {
            $entryResult = $adapter->placeOrder($this->entryRequest(
                plan: $plan,
                context: $context,
                clientOrderId: $clientOrderId,
                attachStopLoss: $attachedStopLossRequested,
                attachTakeProfit: $attachedTakeProfitRequested,
                decisionKey: $decisionKey,
                orderIntentId: $orderIntentId,
            ));
        } catch (\Throwable $e) {
            $this->positionsLogger->error('exchange_execution.entry_submit_failed', [
                'symbol' => $plan->symbol,
                'client_order_id' => $clientOrderId,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);

            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: null,
                status: ExecutionResult::STATUS_ERROR,
                raw: [
                    'reason' => 'entry_submit_failed',
                    'error' => $e->getMessage(),
                    'leverage_submit_success' => $leverageSet,
                ],
            );
        }

        if (!$entryResult->accepted || $entryResult->status === ExchangeOrderStatus::REJECTED) {
            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: $entryResult->exchangeOrderId,
                status: ExecutionResult::STATUS_ERROR,
                raw: [
                    'reason' => 'entry_rejected_exchange',
                    'order' => $this->placeOrderResultPayload($entryResult),
                    'leverage_submit_success' => $leverageSet,
                ],
            );
        }

        if (!$this->entryFilled($entryResult) && $this->entryActive($entryResult)) {
            $cancel = $this->cancelEntryRemainder($adapter, $plan, $entryResult, $decisionKey);
            if (($cancel['filled_after_cancel'] ?? false) === true) {
                $protection = $this->protectionEnforcer->emergencyCloseAfterEntryRisk(
                    adapter: $adapter,
                    plan: $plan,
                    entryClientOrderId: $clientOrderId,
                    decisionKey: $decisionKey,
                    reason: 'entry_filled_during_cancel_race',
                    extra: ['cancel' => $cancel],
                );

                return $this->executionResultFromProtection(
                    $clientOrderId,
                    $entryResult,
                    ($cancel['cancelled'] ?? false) === true
                        ? $protection
                        : $this->criticalResidualEntryRisk($protection, $plan, $entryResult, $decisionKey),
                    $leverageSet,
                );
            }
            $status = ($cancel['cancelled'] ?? false) === true
                ? ExecutionResult::STATUS_ERROR
                : ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION;

            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: $entryResult->exchangeOrderId,
                status: $status,
                raw: [
                    'reason' => ($cancel['cancelled'] ?? false) === true
                        ? 'entry_pending_cancelled_without_fill'
                        : 'entry_pending_cancel_failed',
                    'order' => $this->placeOrderResultPayload($entryResult),
                    'cancel' => $cancel,
                    'leverage_submit_success' => $leverageSet,
                ],
            );
        }

        if (!$this->entryFilled($entryResult)) {
            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: $entryResult->exchangeOrderId,
                status: ExecutionResult::STATUS_ERROR,
                raw: [
                    'reason' => 'entry_closed_without_fill',
                    'order' => $this->placeOrderResultPayload($entryResult),
                    'leverage_submit_success' => $leverageSet,
                ],
            );
        }

        $this->positionsLogger->info('exchange_execution.entry_filled', [
            'symbol' => $plan->symbol,
            'exchange_order_id' => $entryResult->exchangeOrderId,
            'client_order_id' => $clientOrderId,
            'status' => $entryResult->status->value,
            'decision_key' => $decisionKey,
        ]);

        if ($entryResult->status === ExchangeOrderStatus::PARTIALLY_FILLED && $this->entryHasResidual($entryResult)) {
            $cancel = $this->cancelEntryRemainder($adapter, $plan, $entryResult, $decisionKey);
            if (($cancel['cancelled'] ?? false) !== true) {
                $protection = $this->protectionEnforcer->emergencyCloseAfterEntryRisk(
                    adapter: $adapter,
                    plan: $plan,
                    entryClientOrderId: $clientOrderId,
                    decisionKey: $decisionKey,
                    reason: 'partial_entry_remainder_cancel_failed',
                    extra: ['cancel' => $cancel],
                );

                return $this->executionResultFromProtection(
                    $clientOrderId,
                    $entryResult,
                    $this->criticalResidualEntryRisk($protection, $plan, $entryResult, $decisionKey),
                    $leverageSet,
                );
            }
        }

        $protection = $this->protectionEnforcer->enforceAfterEntryFill(
            adapter: $adapter,
            plan: $plan,
            entryResult: $entryResult,
            entryClientOrderId: $clientOrderId,
            attachedProtectionRequested: $attachedStopLossRequested,
            decisionKey: $decisionKey,
        );

        return $this->executionResultFromProtection($clientOrderId, $entryResult, $protection, $leverageSet);
    }

    public function preparePlan(
        OrderPlanModel $plan,
        ?string $mode = null,
        ?string $executionTf = null,
        ?string $decisionKey = null,
    ): OrderPlanModel {
        $this->orderModePolicy->enforce($plan);

        return $this->applyTimeframeMultiplier($plan, $mode, $executionTf, $decisionKey);
    }

    private function executionResultFromProtection(
        string $clientOrderId,
        PlaceOrderResult $entryResult,
        ProtectionEnforcementResult $protection,
        ?bool $leverageSet,
    ): ExecutionResult {
        return new ExecutionResult(
            clientOrderId: $clientOrderId,
            exchangeOrderId: $entryResult->exchangeOrderId,
            status: $protection->status,
            raw: [
                'leverage_submit_success' => $leverageSet,
                'order' => $this->placeOrderResultPayload($entryResult),
                'protection' => [
                    'protected' => $protection->protected,
                    'protection_order_id' => $protection->protectionOrderId,
                    'emergency_order_id' => $protection->emergencyOrderId,
                ] + $protection->metadata,
            ],
        );
    }

    private function entryRequest(
        OrderPlanModel $plan,
        ExchangeContext $context,
        string $clientOrderId,
        bool $attachStopLoss,
        bool $attachTakeProfit,
        ?string $decisionKey,
        ?int $orderIntentId,
    ): PlaceOrderRequest {
        $orderType = $plan->orderType === 'market' ? ExchangeOrderType::MARKET : ExchangeOrderType::LIMIT;

        return new PlaceOrderRequest(
            exchange: $context->exchange,
            marketType: $context->marketType,
            symbol: $plan->symbol,
            side: $this->entryOrderSide($plan->side),
            positionSide: $this->positionSide($plan->side),
            orderType: $orderType,
            timeInForce: $this->timeInForce($plan),
            quantity: (float) $plan->size,
            price: $orderType === ExchangeOrderType::LIMIT ? $plan->entry : null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: $plan->orderType !== 'market' && $plan->orderMode === 4,
            leverage: $plan->leverage,
            marginMode: $plan->openType,
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachStopLoss ? $plan->stop : null,
            attachedTakeProfitPrice: $attachTakeProfit ? $plan->takeProfit : null,
            metadata: [
                'decision_key' => $decisionKey,
                'order_intent_id' => $orderIntentId,
                'source' => 'exchange_execution_service',
            ],
        );
    }

    private function shouldRejectUnprotectableBitmartMarket(
        ExchangeContext $context,
        OrderPlanModel $plan,
        ExchangeCapabilities $capabilities,
    ): bool {
        return $context->exchange === Exchange::BITMART
            && $plan->orderType === 'market'
            && $plan->stop > 0.0
            && !$capabilities->supportsTriggerOrders;
    }

    private function skipBelowMinimum(
        OrderPlanModel $plan,
        ?string $decisionKey,
        string $reason,
        string $field,
        int $value,
        int $minRequired,
        ?string $clientOrderId = null,
    ): ExecutionResult {
        $clientOrderId ??= $this->idempotency->newClientOrderId($decisionKey);
        $this->positionsLogger->warning('exchange_execution.' . $reason, [
            'symbol' => $plan->symbol,
            $field => $value,
            'min_required' => $minRequired,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
        ]);

        return new ExecutionResult(
            clientOrderId: $clientOrderId,
            exchangeOrderId: null,
            status: ExecutionResult::STATUS_SKIPPED,
            raw: [
                'reason' => $reason,
                $field => $value,
                'min_required' => $minRequired,
            ],
        );
    }

    private function entryFilled(PlaceOrderResult $result): bool
    {
        if (\in_array($result->status, [
            ExchangeOrderStatus::FILLED,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true)) {
            return true;
        }

        return ($result->order?->filledQuantity ?? 0.0) > 0.00000001;
    }

    private function entryActive(PlaceOrderResult $result): bool
    {
        return \in_array($result->status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
        ], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function cancelEntryRemainder(
        \App\Exchange\Contract\ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        PlaceOrderResult $entryResult,
        ?string $decisionKey,
    ): array {
        if ($entryResult->exchangeOrderId === null) {
            return ['cancelled' => false, 'reason' => 'entry_exchange_order_id_missing'];
        }

        try {
            $cancel = $adapter->cancelOrder(new CancelOrderRequest(
                exchange: $adapter->exchange(),
                marketType: $adapter->marketType(),
                symbol: $plan->symbol,
                exchangeOrderId: $entryResult->exchangeOrderId,
                clientOrderId: $entryResult->clientOrderId,
            ));
        } catch (\Throwable $e) {
            $this->positionsLogger->critical('exchange_execution.entry_cancel_exception', [
                'symbol' => $plan->symbol,
                'exchange_order_id' => $entryResult->exchangeOrderId,
                'decision_key' => $decisionKey,
                'error' => $e->getMessage(),
            ]);

            return ['cancelled' => false, 'reason' => 'entry_cancel_exception', 'error' => $e->getMessage()];
        }

        try {
            $cancelState = $this->entryCancelState($adapter, $plan, $entryResult, $cancel);
        } catch (\Throwable $e) {
            $this->positionsLogger->critical('exchange_execution.entry_cancel_verification_exception', [
                'symbol' => $plan->symbol,
                'exchange_order_id' => $entryResult->exchangeOrderId,
                'cancelled' => $cancel->cancelled,
                'cancel_status' => $cancel->status->value,
                'decision_key' => $decisionKey,
                'error' => $e->getMessage(),
            ]);

            return [
                'cancelled' => false,
                'reason' => 'entry_cancel_verification_exception',
                'cancel_status' => $cancel->status->value,
                'cancel_exchange_order_id' => $cancel->exchangeOrderId,
                'cancel_metadata' => $cancel->metadata,
                'error' => $e->getMessage(),
            ];
        }
        $cancelled = $cancelState['remainder_inactive'];
        $this->positionsLogger->info($cancelled ? 'exchange_execution.entry_remainder_cancelled' : 'exchange_execution.entry_remainder_cancel_failed', [
            'symbol' => $plan->symbol,
            'exchange_order_id' => $entryResult->exchangeOrderId,
            'cancelled' => $cancel->cancelled,
            'cancel_status' => $cancel->status->value,
            'decision_key' => $decisionKey,
            'filled_after_cancel' => $cancelState['filled_after_cancel'],
        ]);

        return [
            'cancelled' => $cancelled,
            'filled_after_cancel' => $cancelState['filled_after_cancel'],
            'post_cancel_order_status' => $cancelState['order_status'],
            'post_cancel_filled_quantity' => $cancelState['filled_quantity'],
            'post_cancel_remaining_quantity' => $cancelState['remaining_quantity'],
            'post_cancel_position_size' => $cancelState['position_size'],
            'cancel_status' => $cancel->status->value,
            'cancel_exchange_order_id' => $cancel->exchangeOrderId,
            'cancel_metadata' => $cancel->metadata,
        ];
    }

    /**
     * @return array{remainder_inactive: bool, filled_after_cancel: bool, order_status: ?string, filled_quantity: ?float, remaining_quantity: ?float, position_size: ?float}
     */
    private function entryCancelState(
        \App\Exchange\Contract\ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        PlaceOrderResult $entryResult,
        CancelOrderResult $cancel,
    ): array {
        $order = null;
        if ($entryResult->exchangeOrderId === null) {
            return [
                'remainder_inactive' => $cancel->cancelled,
                'filled_after_cancel' => $this->positionSize($adapter, $plan) > 0.00000001,
                'order_status' => null,
                'filled_quantity' => null,
                'remaining_quantity' => null,
                'position_size' => $this->positionSize($adapter, $plan),
            ];
        }

        $order = $adapter->getOrder($plan->symbol, $entryResult->exchangeOrderId);
        $positionSize = $this->positionSize($adapter, $plan);
        if ($order === null) {
            return [
                'remainder_inactive' => $cancel->cancelled,
                'filled_after_cancel' => $positionSize > 0.00000001,
                'order_status' => null,
                'filled_quantity' => null,
                'remaining_quantity' => null,
                'position_size' => $positionSize,
            ];
        }

        $remainderInactive = !$this->activeOrderStatus($order->status)
            || $order->remainingQuantity <= 0.00000001;

        return [
            'remainder_inactive' => $remainderInactive,
            'filled_after_cancel' => $order->filledQuantity > 0.00000001 || $positionSize > 0.00000001,
            'order_status' => $order->status->value,
            'filled_quantity' => $order->filledQuantity,
            'remaining_quantity' => $order->remainingQuantity,
            'position_size' => $positionSize,
        ];
    }

    private function positionSize(
        \App\Exchange\Contract\ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
    ): float {
        foreach ($adapter->getOpenPositions($plan->symbol) as $position) {
            if ($position instanceof ExchangePositionDto && $position->side === $this->positionSide($plan->side)) {
                return max(0.0, $position->size);
            }
        }

        return 0.0;
    }

    private function activeOrderStatus(ExchangeOrderStatus $status): bool
    {
        return \in_array($status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }

    private function criticalResidualEntryRisk(
        ProtectionEnforcementResult $protection,
        OrderPlanModel $plan,
        PlaceOrderResult $entryResult,
        ?string $decisionKey,
    ): ProtectionEnforcementResult {
        if ($protection->status === ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION) {
            return $protection;
        }

        $this->positionsLogger->critical('exchange_execution.entry_remainder_cancel_unconfirmed', [
            'symbol' => $plan->symbol,
            'exchange_order_id' => $entryResult->exchangeOrderId,
            'decision_key' => $decisionKey,
            'reason' => 'entry_remainder_cancel_unconfirmed',
        ]);

        return new ProtectionEnforcementResult(
            status: ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION,
            protected: false,
            protectionOrderId: $protection->protectionOrderId,
            emergencyOrderId: $protection->emergencyOrderId,
            metadata: [
                'residual_entry_risk' => true,
                'critical_reason' => 'entry_remainder_cancel_unconfirmed',
            ] + $protection->metadata,
        );
    }

    private function entryHasResidual(PlaceOrderResult $entryResult): bool
    {
        return $entryResult->order === null || $entryResult->order->remainingQuantity > 0.00000001;
    }

    /**
     * @return array<string,mixed>
     */
    private function placeOrderResultPayload(PlaceOrderResult $result): array
    {
        return [
            'accepted' => $result->accepted,
            'symbol' => $result->symbol,
            'client_order_id' => $result->clientOrderId,
            'exchange_order_id' => $result->exchangeOrderId,
            'status' => $result->status->value,
            'metadata' => $result->metadata,
            'order' => $result->order !== null ? [
                'status' => $result->order->status->value,
                'filled_quantity' => $result->order->filledQuantity,
                'remaining_quantity' => $result->order->remainingQuantity,
                'average_price' => $result->order->averagePrice,
                'metadata' => $result->order->metadata,
            ] : null,
        ];
    }

    private function timeInForce(OrderPlanModel $plan): ExchangeTimeInForce
    {
        if ($plan->orderType === 'market') {
            return ExchangeTimeInForce::IOC;
        }

        return match ($plan->orderMode) {
            2 => ExchangeTimeInForce::FOK,
            3 => ExchangeTimeInForce::IOC,
            default => ExchangeTimeInForce::GTC,
        };
    }

    private function positionSide(Side $side): ExchangePositionSide
    {
        return $side === Side::Long ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
    }

    private function entryOrderSide(Side $side): ExchangeOrderSide
    {
        return $side === Side::Long ? ExchangeOrderSide::BUY : ExchangeOrderSide::SELL;
    }

    private function applyTimeframeMultiplier(
        OrderPlanModel $plan,
        ?string $mode,
        ?string $executionTf,
        ?string $decisionKey,
    ): OrderPlanModel {
        $tfKey = '5m';
        if (is_string($executionTf) && trim($executionTf) !== '') {
            $tfKey = strtolower(trim($executionTf));
        }

        try {
            $config = $this->tradeEntryConfigResolver->resolve($mode);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('exchange_execution.timeframe_multiplier.resolve_failed', [
                'mode' => $mode,
                'execution_tf' => $tfKey,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);

            return $plan;
        }

        $defaults = $config->getDefaults();
        $leverageCfg = $config->getLeverage();
        $multipliers = $leverageCfg['timeframe_multipliers'] ?? [];
        if (!\is_array($multipliers)) {
            $multipliers = [];
        }

        $tfMultiplier = (float)($multipliers[$tfKey] ?? 1.0);
        if (!\is_finite($tfMultiplier) || $tfMultiplier <= 0.0) {
            $tfMultiplier = 1.0;
        }

        $effectiveMultiplier = $tfMultiplier;
        $maxLossPct = $this->normalizedPositivePct($leverageCfg['max_loss_pct'] ?? null);
        $maxLossUsdt = null;
        $maxSizeAllowed = null;
        if ($maxLossPct !== null) {
            $capital = (float)($defaults['initial_margin_usdt'] ?? 0.0);
            $riskPerContract = abs($plan->entry - $plan->stop) * $plan->contractSize;
            if ($capital > 0.0 && $riskPerContract > 0.0) {
                $maxLossUsdt = $capital * $maxLossPct;
                $maxSizeAllowed = (int)floor($maxLossUsdt / $riskPerContract);
                if ($maxSizeAllowed > 0) {
                    $maxMultiplier = $maxSizeAllowed / max(1.0, (float)$plan->size);
                    $effectiveMultiplier = \is_finite($maxMultiplier) && $maxMultiplier > 0.0
                        ? min($effectiveMultiplier, $maxMultiplier)
                        : 0.0;
                } else {
                    $effectiveMultiplier = 0.0;
                }
            }
        }

        $scaledSize = max(0, (int)floor($plan->size * $effectiveMultiplier));
        $scaledLeverageRaw = $plan->leverage * $effectiveMultiplier;
        $roundMode = strtolower((string)($leverageCfg['rounding']['mode'] ?? 'ceil'));
        $scaledLeverage = match ($roundMode) {
            'floor' => (int)floor($scaledLeverageRaw),
            'round' => (int)round($scaledLeverageRaw),
            default => (int)ceil($scaledLeverageRaw),
        };

        $scaledLeverage = max(0, $scaledLeverage);
        if ($scaledLeverage < 1 && $scaledSize > 0) {
            $scaledLeverage = 1;
        }

        $floorCfg = isset($leverageCfg['floor']) ? (float)$leverageCfg['floor'] : null;
        if ($floorCfg !== null && \is_finite($floorCfg) && $floorCfg > 0.0) {
            $scaledLeverage = max($scaledLeverage, (int)ceil($floorCfg));
        }

        if ($tfMultiplier === 1.0) {
            $exchangeCapCfg = isset($leverageCfg['exchange_cap']) ? (float)$leverageCfg['exchange_cap'] : null;
            if ($exchangeCapCfg !== null && \is_finite($exchangeCapCfg) && $exchangeCapCfg > 0.0) {
                $scaledLeverage = min($scaledLeverage, (int)floor($exchangeCapCfg));
            }
        }

        $multiplierCapped = abs($effectiveMultiplier - $tfMultiplier) > 1e-9;
        if ($scaledSize === $plan->size && $scaledLeverage === $plan->leverage && !$multiplierCapped) {
            return $plan;
        }

        $this->positionsLogger->debug('exchange_execution.timeframe_multiplier_applied', [
            'symbol' => $plan->symbol,
            'execution_tf' => $tfKey,
            'tf_multiplier' => $tfMultiplier,
            'effective_multiplier' => $effectiveMultiplier,
            'max_loss_pct' => $maxLossPct,
            'max_loss_usdt' => $maxLossUsdt,
            'risk_per_contract' => abs($plan->entry - $plan->stop) * $plan->contractSize,
            'max_size_allowed' => $maxSizeAllowed,
            'base_size' => $plan->size,
            'scaled_size' => $scaledSize,
            'base_leverage' => $plan->leverage,
            'scaled_leverage' => $scaledLeverage,
            'mode' => $mode,
            'decision_key' => $decisionKey,
        ]);

        return $plan->copyWith(size: $scaledSize, leverage: $scaledLeverage);
    }

    private function normalizedPositivePct(mixed $value): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $pct = (float)$value;
        if (!\is_finite($pct)) {
            return null;
        }
        if ($pct > 1.0) {
            $pct *= 0.01;
        }

        return $pct > 0.0 ? $pct : null;
    }
}
