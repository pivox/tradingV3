<?php

declare(strict_types=1);

namespace App\Trading\Listener;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use App\Logging\TradeLifecycleLogger;
use App\Repository\TradeLifecycleEventRepository;
use App\Trading\Event\OrderStateChangedEvent;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Event\SymbolSkippedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class TradeLifecycleLoggerListener
{
    public function __construct(
        private readonly TradeLifecycleLogger $tradeLifecycleLogger,
        private readonly TradeLifecycleEventRepository $tradeLifecycleRepository,
        private readonly ?MainProviderInterface $mainProvider = null,
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

        $entryPriceFloat = (float)$history->entryPrice->__toString();
        $exitPriceFloat = (float)$history->exitPrice->__toString();
        $sizeFloat = (float)$history->size->__toString();
        $notional = $entryPriceFloat > 0.0 && $sizeFloat > 0.0
            ? $entryPriceFloat * $sizeFloat
            : null;
        $pnlFloat = (float)$history->realizedPnl->__toString();
        $pnlPct = $notional !== null && $notional > 0.0 ? $pnlFloat / $notional : null;
        $holdingTimeSec = $history->closedAt->getTimestamp() - $history->openedAt->getTimestamp();

        // Approximate initial risk in USDT from the most recent ORDER_SUBMITTED lifecycle event
        $pnlR = null;
        try {
            $criteria = [
                'symbol' => strtoupper($history->symbol),
                'eventType' => 'order_submitted',
            ];
            if ($event->runId !== null) {
                $criteria['runId'] = $event->runId;
            }
            $recent = $this->tradeLifecycleRepository->findRecentBy($criteria, 10);
            foreach ($recent as $lifecycleEvent) {
                $extra = $lifecycleEvent->getExtra();
                if (!\is_array($extra)) {
                    continue;
                }
                $riskUsdt = $extra['risk_usdt'] ?? null;
                if ($riskUsdt !== null && \is_numeric($riskUsdt) && (float)$riskUsdt > 0.0) {
                    $riskValue = (float)$riskUsdt;
                    $pnlR = $riskValue > 0.0 ? $pnlFloat / $riskValue : null;
                    break;
                }
            }
        } catch (\Throwable) {
            // best-effort: pnl_R reste null si la recherche échoue
        }

        // MFE / MAE (best-effort à partir des klines 1m)
        $mfePrice = null;
        $maePrice = null;
        $mfePct = null;
        $maePct = null;

        if ($this->mainProvider !== null) {
            try {
                $klineProvider = $this->mainProvider->getKlineProvider();
                $klines = $klineProvider->getKlinesInWindow(
                    $history->symbol,
                    Timeframe::TF_1M,
                    $history->openedAt,
                    $history->closedAt,
                    500
                );

                foreach ($klines as $kline) {
                    $high = isset($kline['high']) ? (float)$kline['high'] : null;
                    $low = isset($kline['low']) ? (float)$kline['low'] : null;
                    if ($high === null || $low === null) {
                        continue;
                    }

                    if (strtoupper($history->side->value) === 'LONG') {
                        // Favorable = plus haut, défavorable = plus bas
                        if ($mfePrice === null || $high > $mfePrice) {
                            $mfePrice = $high;
                        }
                        if ($maePrice === null || $low < $maePrice) {
                            $maePrice = $low;
                        }
                    } else {
                        // SHORT: favorable = plus bas, défavorable = plus haut
                        if ($mfePrice === null || $low < $mfePrice) {
                            $mfePrice = $low;
                        }
                        if ($maePrice === null || $high > $maePrice) {
                            $maePrice = $high;
                        }
                    }
                }

                if ($entryPriceFloat > 0.0) {
                    if ($mfePrice !== null) {
                        $mfePct = strtoupper($history->side->value) === 'LONG'
                            ? ($mfePrice - $entryPriceFloat) / $entryPriceFloat
                            : ($entryPriceFloat - $mfePrice) / $entryPriceFloat;
                    }
                    if ($maePrice !== null) {
                        $maePct = strtoupper($history->side->value) === 'LONG'
                            ? ($entryPriceFloat - $maePrice) / $entryPriceFloat
                            : ($maePrice - $entryPriceFloat) / $entryPriceFloat;
                    }
                }
            } catch (\Throwable) {
                // best-effort: metrics restent null en cas d'échec
            }
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
                'pnl_pct' => $pnlPct,
                'pnl_R' => $pnlR,
                'notional_usdt' => $notional,
                'entry_price' => $history->entryPrice->__toString(),
                'exit_price' => $history->exitPrice->__toString(),
                'entry_time' => $history->openedAt->format('Y-m-d H:i:s'),
                'close_time' => $history->closedAt->format('Y-m-d H:i:s'),
                'holding_time_sec' => $holdingTimeSec,
                'max_favorable_price' => $mfePrice,
                'max_adverse_price' => $maePrice,
                'mfe_pct' => $mfePct,
                'mae_pct' => $maePct,
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
