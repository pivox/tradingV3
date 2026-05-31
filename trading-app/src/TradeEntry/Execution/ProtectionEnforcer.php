<?php

declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProtectionEnforcer
{
    public function __construct(
        private readonly EmergencyCloseService $emergencyClose,
        private readonly TradeEntryMetricsService $metrics,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {
    }

    public function enforceAfterEntryFill(
        ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        PlaceOrderResult $entryResult,
        string $entryClientOrderId,
        bool $attachedProtectionRequested,
        ?string $decisionKey = null,
    ): ProtectionEnforcementResult {
        if (!$this->entryFilled($entryResult)) {
            return new ProtectionEnforcementResult(
                status: ExecutionResult::STATUS_ENTRY_SUBMITTED,
                protected: false,
                metadata: [
                    'reason' => 'entry_not_filled_yet',
                    'entry_status' => $entryResult->status->value,
                ],
            );
        }

        if ($attachedProtectionRequested) {
            try {
                $confirmed = $this->findConfirmedStopLoss($adapter, $plan);
            } catch (\Throwable $e) {
                return $this->failAndEmergencyClose(
                    adapter: $adapter,
                    plan: $plan,
                    entryClientOrderId: $entryClientOrderId,
                    decisionKey: $decisionKey,
                    reason: 'protection_confirmation_failed',
                    extra: ['error' => $e->getMessage()],
                );
            }
            if ($confirmed instanceof ExchangeOrderDto) {
                return $this->confirmed($confirmed, 'attached', $decisionKey);
            }

            return $this->failAndEmergencyClose(
                adapter: $adapter,
                plan: $plan,
                entryClientOrderId: $entryClientOrderId,
                decisionKey: $decisionKey,
                reason: 'attached_stop_loss_not_confirmed',
            );
        }

        $capabilities = $adapter->capabilities();
        if (!$capabilities->supportsReduceOnly || !$capabilities->supportsTriggerOrders) {
            return $this->failAndEmergencyClose(
                adapter: $adapter,
                plan: $plan,
                entryClientOrderId: $entryClientOrderId,
                decisionKey: $decisionKey,
                reason: 'separate_stop_loss_not_supported',
            );
        }

        try {
            $stopResult = $adapter->placeOrder(new PlaceOrderRequest(
                exchange: $adapter->exchange(),
                marketType: $adapter->marketType(),
                symbol: $plan->symbol,
                side: $this->exitOrderSide($plan->side),
                positionSide: $this->positionSide($plan->side),
                orderType: ExchangeOrderType::STOP_LOSS,
                timeInForce: ExchangeTimeInForce::GTC,
                quantity: $this->requiredProtectionQuantity($adapter, $plan, $entryResult),
                price: null,
                stopPrice: $plan->stop,
                reduceOnly: true,
                postOnly: false,
                leverage: $plan->leverage,
                marginMode: $plan->openType,
                clientOrderId: $this->stopLossClientOrderId($entryClientOrderId),
                metadata: [
                    'decision_key' => $decisionKey,
                    'protection_mode' => 'separate_reduce_only_stop',
                    'parent_exchange_order_id' => $entryResult->exchangeOrderId,
                    'parent_client_order_id' => $entryClientOrderId,
                ],
            ));
        } catch (\Throwable $e) {
            return $this->failAndEmergencyClose(
                adapter: $adapter,
                plan: $plan,
                entryClientOrderId: $entryClientOrderId,
                decisionKey: $decisionKey,
                reason: 'separate_stop_loss_submit_exception',
                extra: ['error' => $e->getMessage()],
            );
        }

        if (!$stopResult->accepted || !$this->activeOrderStatus($stopResult->status)) {
            return $this->failAndEmergencyClose(
                adapter: $adapter,
                plan: $plan,
                entryClientOrderId: $entryClientOrderId,
                decisionKey: $decisionKey,
                reason: 'separate_stop_loss_rejected',
                extra: [
                    'protection_status' => $stopResult->status->value,
                    'protection_order_id' => $stopResult->exchangeOrderId,
                ],
            );
        }

        try {
            $confirmed = $this->findConfirmedStopLoss($adapter, $plan);
        } catch (\Throwable $e) {
            return $this->failAndEmergencyClose(
                adapter: $adapter,
                plan: $plan,
                entryClientOrderId: $entryClientOrderId,
                decisionKey: $decisionKey,
                reason: 'protection_confirmation_failed',
                extra: ['error' => $e->getMessage()],
            );
        }
        if ($confirmed instanceof ExchangeOrderDto) {
            return $this->confirmed($confirmed, 'separate_reduce_only_stop', $decisionKey);
        }

        return $this->failAndEmergencyClose(
            adapter: $adapter,
            plan: $plan,
            entryClientOrderId: $entryClientOrderId,
            decisionKey: $decisionKey,
            reason: 'separate_stop_loss_not_confirmed',
            extra: [
                'protection_order_id' => $stopResult->exchangeOrderId,
                'protection_status' => $stopResult->status->value,
            ],
        );
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function emergencyCloseAfterEntryRisk(
        ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        string $entryClientOrderId,
        ?string $decisionKey,
        string $reason,
        array $extra = [],
    ): ProtectionEnforcementResult {
        return $this->failAndEmergencyClose($adapter, $plan, $entryClientOrderId, $decisionKey, $reason, $extra);
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function failAndEmergencyClose(
        ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        string $entryClientOrderId,
        ?string $decisionKey,
        string $reason,
        array $extra = [],
    ): ProtectionEnforcementResult {
        $this->metrics->incr('protection_failed');
        $this->positionsLogger->critical('protection.failed', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'stop' => $plan->stop,
            'decision_key' => $decisionKey,
            'reason' => $reason,
        ] + $extra);

        $emergency = $this->emergencyClose->closeUnprotectedPosition(
            adapter: $adapter,
            plan: $plan,
            parentClientOrderId: $entryClientOrderId,
            decisionKey: $decisionKey,
        );
        $staleProtectionCancel = null;
        if ($emergency->closed && !$emergency->critical) {
            $staleProtectionCancel = $this->cancelMatchingProtectionOrders($adapter, $plan, $decisionKey);
        }

        return new ProtectionEnforcementResult(
            status: $emergency->status,
            protected: false,
            emergencyOrderId: $emergency->exchangeOrderId,
            metadata: [
                'reason' => $reason,
                'emergency_close' => $emergency->metadata,
                'stale_protection_cancel' => $staleProtectionCancel,
            ] + $extra,
        );
    }

    private function confirmed(ExchangeOrderDto $order, string $mode, ?string $decisionKey): ProtectionEnforcementResult
    {
        $this->metrics->incr('protection_confirmed');
        $this->positionsLogger->info('protection.confirmed', [
            'symbol' => $order->symbol,
            'protection_order_id' => $order->exchangeOrderId,
            'client_order_id' => $order->clientOrderId,
            'mode' => $mode,
            'stop_price' => $order->stopPrice,
            'decision_key' => $decisionKey,
        ]);

        return new ProtectionEnforcementResult(
            status: ExecutionResult::STATUS_SUBMITTED_PROTECTED,
            protected: true,
            protectionOrderId: $order->exchangeOrderId,
            metadata: [
                'protection_mode' => $mode,
                'protection_status' => $order->status->value,
                'protection_stop_price' => $order->stopPrice,
            ],
        );
    }

    private function findConfirmedStopLoss(ExchangeAdapterInterface $adapter, OrderPlanModel $plan): ?ExchangeOrderDto
    {
        $requiredQuantity = $this->requiredProtectionQuantity($adapter, $plan, null);
        $coveredQuantity = 0.0;
        $confirmed = null;

        foreach ($adapter->getOpenOrders($plan->symbol) as $order) {
            if (!$this->activeOrderStatus($order->status)) {
                continue;
            }
            if (!\in_array($order->orderType, [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TRIGGER], true) || !$order->reduceOnly) {
                continue;
            }
            if ($order->positionSide !== $this->positionSide($plan->side) || $order->side !== $this->exitOrderSide($plan->side)) {
                continue;
            }
            if ($order->stopPrice === null || !$this->samePrice($order->stopPrice, $plan->stop)) {
                continue;
            }
            $coveredQuantity += $order->remainingQuantity;
            $confirmed = $order;

            if ($coveredQuantity + 0.00000001 >= $requiredQuantity) {
                return $confirmed;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function cancelMatchingProtectionOrders(
        ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        ?string $decisionKey,
    ): array {
        try {
            $orders = $adapter->getOpenOrders($plan->symbol);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('protection.stale_stop_cancel.list_failed', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'error' => $e->getMessage(),
            ]);

            return [
                'attempted' => true,
                'cancelled' => 0,
                'failed' => 0,
                'reason' => 'open_orders_lookup_failed',
                'error' => $e->getMessage(),
            ];
        }

        $cancelled = 0;
        $failed = 0;
        $errors = [];
        foreach ($orders as $order) {
            if (!$this->matchesProtectionOrder($order, $plan)) {
                continue;
            }

            try {
                $result = $adapter->cancelOrder(new CancelOrderRequest(
                    exchange: $adapter->exchange(),
                    marketType: $adapter->marketType(),
                    symbol: $plan->symbol,
                    exchangeOrderId: $order->exchangeOrderId,
                    clientOrderId: $order->clientOrderId,
                    metadata: [
                        'decision_key' => $decisionKey,
                        'reason' => 'stale_protection_after_emergency_close',
                    ],
                ));
            } catch (\Throwable $e) {
                ++$failed;
                $errors[] = [
                    'exchange_order_id' => $order->exchangeOrderId,
                    'client_order_id' => $order->clientOrderId,
                    'error' => $e->getMessage(),
                ];
                $this->positionsLogger->warning('protection.stale_stop_cancel.exception', [
                    'symbol' => $plan->symbol,
                    'exchange_order_id' => $order->exchangeOrderId,
                    'client_order_id' => $order->clientOrderId,
                    'decision_key' => $decisionKey,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($result->cancelled) {
                ++$cancelled;
                $this->positionsLogger->info('protection.stale_stop_cancel.cancelled', [
                    'symbol' => $plan->symbol,
                    'exchange_order_id' => $order->exchangeOrderId,
                    'client_order_id' => $order->clientOrderId,
                    'decision_key' => $decisionKey,
                ]);
            } else {
                ++$failed;
                $errors[] = [
                    'exchange_order_id' => $order->exchangeOrderId,
                    'client_order_id' => $order->clientOrderId,
                    'cancel_status' => $result->status->value,
                    'metadata' => $result->metadata,
                ];
                $this->positionsLogger->warning('protection.stale_stop_cancel.failed', [
                    'symbol' => $plan->symbol,
                    'exchange_order_id' => $order->exchangeOrderId,
                    'client_order_id' => $order->clientOrderId,
                    'cancel_status' => $result->status->value,
                    'decision_key' => $decisionKey,
                ]);
            }
        }

        return [
            'attempted' => true,
            'cancelled' => $cancelled,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function matchesProtectionOrder(ExchangeOrderDto $order, OrderPlanModel $plan): bool
    {
        if (!$this->activeOrderStatus($order->status)) {
            return false;
        }
        if (!\in_array($order->orderType, [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TAKE_PROFIT, ExchangeOrderType::TRIGGER], true) || !$order->reduceOnly) {
            return false;
        }
        if ($order->positionSide !== $this->positionSide($plan->side) || $order->side !== $this->exitOrderSide($plan->side)) {
            return false;
        }

        return $order->stopPrice !== null
            && ($this->samePrice($order->stopPrice, $plan->stop)
                || ($plan->takeProfit > 0.0 && $this->samePrice($order->stopPrice, $plan->takeProfit)));
    }

    private function requiredProtectionQuantity(
        ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        ?PlaceOrderResult $entryResult,
    ): float {
        foreach ($adapter->getOpenPositions($plan->symbol) as $position) {
            if ($position instanceof ExchangePositionDto && $position->side === $this->positionSide($plan->side)) {
                return max($position->size, 0.00000001);
            }
        }

        if ($entryResult?->order instanceof ExchangeOrderDto && $entryResult->order->filledQuantity > 0.0) {
            return $entryResult->order->filledQuantity;
        }

        return (float) $plan->size;
    }

    private function entryFilled(PlaceOrderResult $result): bool
    {
        return \in_array($result->status, [
            ExchangeOrderStatus::FILLED,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }

    private function activeOrderStatus(ExchangeOrderStatus $status): bool
    {
        return \in_array($status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }

    private function stopLossClientOrderId(string $entryClientOrderId): string
    {
        return substr($entryClientOrderId, 0, 56) . '-sl';
    }

    private function samePrice(float $a, float $b): bool
    {
        return abs($a - $b) <= max(0.00000001, abs($b) * 0.000001);
    }

    private function positionSide(Side $side): ExchangePositionSide
    {
        return $side === Side::Long ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
    }

    private function exitOrderSide(Side $side): ExchangeOrderSide
    {
        return $side === Side::Long ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }
}
