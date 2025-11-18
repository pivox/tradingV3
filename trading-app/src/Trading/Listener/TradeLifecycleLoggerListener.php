<?php

declare(strict_types=1);

namespace App\Trading\Listener;

use App\Logging\TradeLifecycleLogger;
use App\Trading\Event\OrderStateChangedEvent;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Event\SymbolSkippedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class TradeLifecycleLoggerListener
{
    public function __construct(
        private readonly TradeLifecycleLogger $tradeLifecycleLogger,
    ) {}

    // --- POSITION OUVERTE ----------------------------------------------------

    #[AsEventListener(event: PositionOpenedEvent::class)]
    public function onPositionOpened(PositionOpenedEvent $event): void
    {
        $position = $event->position;

        $positionId = $position->raw['position_id']
            ?? $position->raw['positionId']
            ?? sprintf('%s:%s:%s', $position->symbol, strtolower($position->side->value), $position->openedAt->format('U'));

        $this->tradeLifecycleLogger->logPositionOpened(
            symbol: $position->symbol,
            positionId: (string) $positionId,
            side: strtoupper($position->side->value),
            qty: $position->size->__toString(),
            entryPrice: $position->entryPrice->__toString(),
            runId: $event->runId,
            exchange: $event->exchange,
            accountId: $event->accountId,
            extra: array_merge([
                'source' => 'trading_state_sync',
                'raw'    => $position->raw,
            ], $event->extra),
        );
    }

    // --- POSITION FERMÉE -----------------------------------------------------

    #[AsEventListener(event: PositionClosedEvent::class)]
    public function onPositionClosed(PositionClosedEvent $event): void
    {
        $history = $event->positionHistory;

        $positionId = $history->raw['position_id']
            ?? $history->raw['positionId']
            ?? sprintf('%s:%s:%s', $history->symbol, strtolower($history->side->value), $history->closedAt->format('U'));

        // Déterminer le reasonCode si non fourni
        $reasonCode = $event->reasonCode;
        if ($reasonCode === null) {
            $realizedPnlFloat = (float)$history->realizedPnl->__toString();
            $reasonCode = $realizedPnlFloat < 0.0 ? 'loss_or_stop'
                : ($realizedPnlFloat > 0.0 ? 'profit_or_tp' : 'closed_flat');
        }

        $this->tradeLifecycleLogger->logPositionClosed(
            symbol: $history->symbol,
            positionId: (string) $positionId,
            side: strtoupper($history->side->value),
            runId: $event->runId,
            exchange: $event->exchange,
            accountId: $event->accountId,
            reasonCode: $reasonCode,
            extra: array_merge([
                'pnl'  => $history->realizedPnl->__toString(),
                'fees' => $history->fees?->__toString(),
                'raw'  => $history->raw,
            ], $event->extra),
        );
    }

    // --- ORDRE EXPIRED / CLOSED SANS FILL -----------------------------------

    #[AsEventListener(event: OrderStateChangedEvent::class)]
    public function onOrderStateChanged(OrderStateChangedEvent $event): void
    {
        $order = $event->order;

        // Détection simple "expired" : ordre qui passe d'OPEN -> CLOSED/CANCELED sans aucun fill
        $isClosedState = \in_array(strtoupper($event->newStatus), ['CANCELED', 'CLOSED', 'REJECTED', 'CANCELLED'], true);
        $wasOpenState  = \in_array(strtoupper($event->previousStatus), ['NEW', 'PARTIALLY_FILLED', 'UPDATED', 'SENT', 'READY', 'PENDING'], true);

        // Vérifier si l'ordre n'a pas été rempli (filledQuantity = 0)
        $filledQuantityFloat = (float)$order->filledQuantity->__toString();

        if ($isClosedState && $wasOpenState && $filledQuantityFloat <= 0.0) {
            $reasonCode = 'order_expired_or_timed_cancel';

            $this->tradeLifecycleLogger->logOrderExpired(
                symbol: $order->symbol,
                orderId: $order->orderId,
                clientOrderId: $order->clientOrderId,
                reasonCode: $reasonCode,
                runId: $event->runId,
                exchange: $event->exchange,
                accountId: $event->accountId,
                extra: array_merge([
                    'previous_status' => $event->previousStatus,
                    'new_status'      => $event->newStatus,
                    'raw'             => $order->raw,
                ], $event->extra),
            );
        }

        // Si tu veux loguer d'autres transitions d'ordres (FILLED, PARTIALLY_FILLED),
        // tu peux rajouter d'autres appels ici (ex: logOrderSubmitted, logOrderFilled, etc.).
    }

    // --- SYMBOL SKIPPED (MTF) ----------------------------------------------

    #[AsEventListener(event: SymbolSkippedEvent::class)]
    public function onSymbolSkipped(SymbolSkippedEvent $event): void
    {
        $this->tradeLifecycleLogger->logSymbolSkipped(
            symbol: $event->symbol,
            reasonCode: $event->reasonCode,
            runId: $event->runId,
            timeframe: $event->timeframe,
            configProfile: $event->configProfile,
            configVersion: $event->configVersion,
            extra: $event->extra,
        );
    }
}


