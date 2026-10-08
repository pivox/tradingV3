<?php

declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use Psr\Clock\ClockInterface;

final readonly class RestingEntryWatcher
{
    private const EPSILON = 0.00000001;

    public function __construct(
        private ProtectionEnforcer $protection,
        private ClockInterface $clock,
    ) {
    }

    public function inspect(
        ExchangeAdapterInterface $adapter,
        string $symbol,
        string $exchangeOrderId,
        ?string $clientOrderId,
        ?ExchangePositionSide $positionSide = null,
    ): RestingEntryState {
        $order = $adapter->getOrder($symbol, $exchangeOrderId);
        if ($order !== null) {
            $active = \in_array($order->status, [
                ExchangeOrderStatus::PENDING,
                ExchangeOrderStatus::OPEN,
                ExchangeOrderStatus::PARTIALLY_FILLED,
            ], true);
            if ($order->filledQuantity > self::EPSILON) {
                return new RestingEntryState(
                    RestingEntryState::FILLED,
                    $order->filledQuantity,
                    $active && $order->remainingQuantity > self::EPSILON,
                    $order,
                    $order->averagePrice ?? $order->price,
                );
            }

            return new RestingEntryState($active ? RestingEntryState::ACTIVE : RestingEntryState::CLOSED, 0.0, $active, $order);
        }

        $filled = 0.0;
        $notional = 0.0;
        if ($adapter instanceof ExchangeRestSnapshotProviderInterface) {
            foreach ($adapter->getFillsSnapshot($symbol) as $fill) {
                if ($fill->exchangeOrderId === $exchangeOrderId || ($clientOrderId !== null && $fill->clientOrderId === $clientOrderId)) {
                    $filled += $fill->quantity;
                    $notional += $fill->quantity * $fill->price;
                }
            }
        }
        if ($filled > self::EPSILON) {
            return new RestingEntryState(RestingEntryState::FILLED, $filled, false, null, $notional / $filled);
        }

        if ($positionSide !== null) {
            foreach ($adapter->getOpenPositions($symbol) as $position) {
                if ($position->side === $positionSide && $position->size > self::EPSILON) {
                    return new RestingEntryState(RestingEntryState::FILLED, $position->size, false, null, $position->entryPrice);
                }
            }
        }

        return new RestingEntryState(RestingEntryState::CLOSED);
    }

    public function cancel(
        ExchangeAdapterInterface $adapter,
        string $symbol,
        string $exchangeOrderId,
        ?string $clientOrderId,
        ?string $decisionKey,
    ): CancelOrderResult {
        return $adapter->cancelOrder(new CancelOrderRequest(
            exchange: $adapter->exchange(),
            marketType: $adapter->marketType(),
            symbol: $symbol,
            exchangeOrderId: $exchangeOrderId,
            clientOrderId: $clientOrderId,
            metadata: ['write_kind' => 'protective', 'reason' => 'entry_remainder', 'decision_key' => $decisionKey],
        ));
    }

    public function protectFilledEntry(
        ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        RestingEntryState $state,
        string $exchangeOrderId,
        string $clientOrderId,
        ?string $decisionKey,
    ): ProtectionEnforcementResult {
        return $this->protection->enforceAfterEntryFill(
            adapter: $adapter,
            plan: $plan,
            entryResult: new PlaceOrderResult(
                accepted: true,
                symbol: $plan->symbol,
                clientOrderId: $clientOrderId,
                exchangeOrderId: $exchangeOrderId,
                status: $state->remainderActive ? ExchangeOrderStatus::PARTIALLY_FILLED : ExchangeOrderStatus::FILLED,
                submittedAt: $this->clock->now(),
                order: $state->order,
            ),
            entryClientOrderId: $clientOrderId,
            attachedProtectionRequested: false,
            decisionKey: $decisionKey,
        );
    }
}
