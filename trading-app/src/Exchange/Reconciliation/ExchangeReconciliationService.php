<?php

declare(strict_types=1);

namespace App\Exchange\Reconciliation;

use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangeOrderCancelled;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangeOrderPartiallyFilled;
use App\Exchange\Event\ExchangeOrderRejected;
use App\Exchange\Event\ExchangeOrderUpdated;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Event\ExchangeProtectionOrderCreated;
use App\Exchange\Event\ExchangeProtectionOrderRejected;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ExchangeReconciliationService
{
    public function __construct(
        private ExchangeEventBus $bus,
        private ExchangeLocalProjectionStoreInterface $projectionStore,
        private ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.positions')] private LoggerInterface $logger,
    ) {
    }

    public function reconcile(ExchangeAdapterInterface $adapter, ?string $symbol = null): ExchangeReconciliationResult
    {
        $startedAt = $this->clock->now();
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;
        $snapshotProofProvider = $adapter instanceof ExchangeReconciliationSnapshotProofProviderInterface
            ? $adapter
            : null;
        $pendingSnapshotProof = $snapshotProofProvider instanceof ExchangeReconciliationSnapshotProofProviderInterface
            ? $snapshotProofProvider->captureReconciliationSnapshotProof($normalizedSymbol)
            : null;
        $orders = $adapter instanceof ExchangeRestSnapshotProviderInterface
            ? $adapter->getOrdersSnapshot($normalizedSymbol)
            : $adapter->getOpenOrders($normalizedSymbol);
        $positions = $adapter->getOpenPositions($normalizedSymbol);
        $fills = $adapter instanceof ExchangeRestSnapshotProviderInterface
            ? $adapter->getFillsSnapshot($normalizedSymbol)
            : [];

        $unknownOrders = [];
        $eventsProjected = 0;
        foreach ($orders as $order) {
            if (!$this->projectionStore->hasOrder($order)) {
                $unknownOrders[] = $order->exchangeOrderId;
            }
            $this->bus->publish($this->eventForOrder($order, $startedAt));
            ++$eventsProjected;
        }

        foreach ($positions as $position) {
            $this->bus->publish(new ExchangePositionUpdated(
                exchange: $position->exchange,
                marketType: $position->marketType,
                symbol: $position->symbol,
                side: $position->side,
                size: $position->size,
                position: $position,
                occurredAt: $position->updatedAt ?? $startedAt,
                payload: ['source' => 'rest_reconciliation'],
            ));
            ++$eventsProjected;
        }

        $positionSnapshotAuthoritative = $adapter instanceof ExchangeRestSnapshotProviderInterface
            && $adapter->hasAuthoritativePositionSnapshot($normalizedSymbol);
        if ($positionSnapshotAuthoritative) {
            foreach ($this->missingLocalPositions($adapter, $positions, $normalizedSymbol) as $missingPosition) {
                $this->bus->publish(new \App\Exchange\Event\ExchangePositionClosed(
                    exchange: $adapter->exchange(),
                    marketType: $adapter->marketType(),
                    symbol: $missingPosition['symbol'],
                    side: $missingPosition['side'],
                    size: 0.0,
                    position: null,
                    occurredAt: $startedAt,
                    payload: [
                        'source' => 'rest_reconciliation',
                        'reason' => 'missing_from_rest_position_snapshot',
                        'previous_size' => $missingPosition['size'],
                    ],
                ));
                ++$eventsProjected;
            }
        }

        foreach ($fills as $fill) {
            $this->bus->publish(new ExchangeFillReceived($fill, ['source' => 'rest_reconciliation']));
            ++$eventsProjected;
        }

        $unprotectedPositions = $this->detectUnprotectedPositions($adapter, $positions);
        $completedAt = $this->clock->now();
        $snapshotProof = $pendingSnapshotProof !== null
            && $snapshotProofProvider instanceof ExchangeReconciliationSnapshotProofProviderInterface
                ? $snapshotProofProvider->attestReconciliationSnapshotProof($pendingSnapshotProof)
                : null;
        $metadata = [
            'unknown_order_ids' => $unknownOrders,
            'unprotected_positions' => $unprotectedPositions,
            'events_projected' => $eventsProjected,
            'position_snapshot_authoritative' => $positionSnapshotAuthoritative,
        ];
        if ($snapshotProof !== null) {
            $metadata['fake_private_ws_snapshot_proof'] = $snapshotProof;
        }

        $result = new ExchangeReconciliationResult(
            exchange: $adapter->exchange(),
            marketType: $adapter->marketType(),
            symbol: $normalizedSymbol,
            startedAt: $startedAt,
            completedAt: $completedAt,
            ordersChecked: \count($orders),
            positionsChecked: \count($positions),
            fillsImported: \count($fills),
            correctionsApplied: $eventsProjected,
            unknownOrdersDetected: \count($unknownOrders),
            metadata: $metadata,
        );

        $this->logger->info('exchange_reconciliation.completed', [
            'exchange' => $result->exchange->value,
            'market_type' => $result->marketType->value,
            'symbol' => $result->symbol,
            'orders_checked' => $result->ordersChecked,
            'positions_checked' => $result->positionsChecked,
            'fills_imported' => $result->fillsImported,
            'unknown_orders_detected' => $result->unknownOrdersDetected,
            'unprotected_positions' => \count($unprotectedPositions),
        ]);

        return $result;
    }

    /**
     * @param ExchangePositionDto[] $snapshotPositions
     * @return array<int,array{symbol: string, side: ExchangePositionSide, size: float}>
     */
    private function missingLocalPositions(
        ExchangeAdapterInterface $adapter,
        array $snapshotPositions,
        ?string $symbol,
    ): array {
        $snapshotKeys = [];
        foreach ($snapshotPositions as $position) {
            $snapshotKeys[$this->positionKey($position->symbol, $position->side)] = true;
        }

        $missing = [];
        foreach ($this->projectionStore->openPositions($adapter->exchange(), $adapter->marketType(), $symbol) as $localPosition) {
            if (isset($snapshotKeys[$this->positionKey($localPosition['symbol'], $localPosition['side'])])) {
                continue;
            }

            $missing[] = $localPosition;
        }

        return $missing;
    }

    private function eventForOrder(ExchangeOrderDto $order, \DateTimeImmutable $occurredAt): ExchangeEventInterface
    {
        if ($this->isProtectionOrder($order) && $order->status === ExchangeOrderStatus::REJECTED) {
            return new ExchangeProtectionOrderRejected($order, $order->updatedAt ?? $occurredAt, ['source' => 'rest_reconciliation']);
        }

        if ($this->isProtectionOrder($order) && \in_array($order->status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true)) {
            return new ExchangeProtectionOrderCreated($order, $order->updatedAt ?? $occurredAt, ['source' => 'rest_reconciliation']);
        }

        return match ($order->status) {
            ExchangeOrderStatus::FILLED => new ExchangeOrderFilled($order, $order->updatedAt ?? $occurredAt, ['source' => 'rest_reconciliation']),
            ExchangeOrderStatus::PARTIALLY_FILLED => new ExchangeOrderPartiallyFilled($order, $order->updatedAt ?? $occurredAt, ['source' => 'rest_reconciliation']),
            ExchangeOrderStatus::CANCELLED, ExchangeOrderStatus::EXPIRED => new ExchangeOrderCancelled($order, $order->updatedAt ?? $occurredAt, ['source' => 'rest_reconciliation']),
            ExchangeOrderStatus::REJECTED => new ExchangeOrderRejected($order, $order->updatedAt ?? $occurredAt, ['source' => 'rest_reconciliation']),
            default => new ExchangeOrderUpdated($order, $order->updatedAt ?? $occurredAt, ['source' => 'rest_reconciliation']),
        };
    }

    /**
     * @param ExchangePositionDto[] $positions
     * @return array<int,array<string,mixed>>
     */
    private function detectUnprotectedPositions(ExchangeAdapterInterface $adapter, array $positions): array
    {
        $unprotected = [];
        foreach ($positions as $position) {
            $coveredQuantity = 0.0;
            foreach ($adapter->getOpenOrders($position->symbol) as $order) {
                if (!$this->isConfirmedStopLossProtection($order, $position)) {
                    continue;
                }
                $coveredQuantity += $order->remainingQuantity;
                if ($coveredQuantity + 0.00000001 >= $position->size) {
                    break;
                }
            }

            if ($coveredQuantity + 0.00000001 < $position->size) {
                $unprotected[] = [
                    'symbol' => $position->symbol,
                    'side' => $position->side->value,
                    'size' => $position->size,
                    'entry_price' => $position->entryPrice,
                ];
            }
        }

        return $unprotected;
    }

    private function isConfirmedStopLossProtection(ExchangeOrderDto $order, ExchangePositionDto $position): bool
    {
        if (!$this->activeOrderStatus($order->status)) {
            return false;
        }
        if (!$order->reduceOnly) {
            return false;
        }
        if (!\in_array($order->orderType, [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TRIGGER], true)) {
            return false;
        }
        if ($order->positionSide !== $position->side || $order->side !== $this->exitOrderSide($position->side)) {
            return false;
        }
        if ($order->stopPrice === null || $order->remainingQuantity <= 0.00000001) {
            return false;
        }
        if (!$this->stopPriceLooksProtective($order, $position)) {
            return false;
        }

        return true;
    }

    private function stopPriceLooksProtective(ExchangeOrderDto $order, ExchangePositionDto $position): bool
    {
        if ($order->stopPrice === null) {
            return false;
        }
        if ($position->entryPrice <= 0.0) {
            return $order->orderType === ExchangeOrderType::STOP_LOSS;
        }

        return $position->side === ExchangePositionSide::SHORT
            ? $order->stopPrice >= $position->entryPrice
            : $order->stopPrice <= $position->entryPrice;
    }

    private function activeOrderStatus(ExchangeOrderStatus $status): bool
    {
        return \in_array($status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }

    private function exitOrderSide(ExchangePositionSide $side): ExchangeOrderSide
    {
        return $side === ExchangePositionSide::SHORT ? ExchangeOrderSide::BUY : ExchangeOrderSide::SELL;
    }

    private function isProtectionOrder(ExchangeOrderDto $order): bool
    {
        return $order->reduceOnly || \in_array($order->orderType, [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER,
        ], true);
    }

    private function positionKey(string $symbol, ExchangePositionSide $side): string
    {
        return strtoupper($symbol) . ':' . $side->value;
    }
}
