<?php

declare(strict_types=1);

namespace App\Trading\Listener;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use App\Logging\TradeLifecycleLogger;
use App\Provider\Context\ExchangeContext;
use App\Repository\TradeLifecycleEventRepository;
use App\Trading\Lineage\TradeLineageManager;
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
        private readonly ?TradeLineageManager $tradeLineageManager = null,
    ) {}

    // --- POSITION OUVERTE ----------------------------------------------------

    #[AsEventListener(event: PositionOpenedEvent::class)]
    public function onPositionOpened(PositionOpenedEvent $event): void
    {
        $position = $event->position;
        $marketType = $this->marketTypeFromExtra($event->extra);

        $rawPositionId = $this->positionIdFromRaw($position->raw);
        $positionId = $rawPositionId
            ?? sprintf('%s:%s:%s', $position->symbol, strtolower($position->side->value), $position->openedAt->format('U'));
        $lineage = $this->safeResolveLineageFromPositionPayload($position->raw, $event->extra, $marketType, $rawPositionId);
        $this->safeAttachPositionId($lineage, $rawPositionId);
        $extra = array_merge(
            $lineage !== null ? $this->tradeLineageManager?->lifecycleExtra($lineage) ?? [] : [],
            [
                'source' => 'trading_state_sync',
                'raw'    => $position->raw,
            ],
            $event->extra,
        );

        $this->tradeLifecycleLogger->logPositionOpened(
            symbol: $position->symbol,
            positionId: (string) $positionId,
            side: strtoupper($position->side->value),
            qty: $position->size->__toString(),
            entryPrice: $position->entryPrice->__toString(),
            runId: $event->runId ?? $lineage?->getRunId(),
            exchange: $event->exchange,
            accountId: $event->accountId,
            extra: $extra,
            marketType: $marketType,
        );
    }

    // --- POSITION FERMÉE -----------------------------------------------------

    #[AsEventListener(event: PositionClosedEvent::class)]
    public function onPositionClosed(PositionClosedEvent $event): void
    {
        $history = $event->positionHistory;
        $marketType = $this->marketTypeFromExtra($event->extra);

        $rawPositionId = $this->positionIdFromRaw($history->raw);
        $positionId = $rawPositionId
            ?? sprintf('%s:%s:%s', $history->symbol, strtolower($history->side->value), $history->closedAt->format('U'));
        $lineage = $this->safeResolveLineageFromPositionPayload($history->raw, $event->extra, $marketType, $rawPositionId);
        $this->safeAttachPositionId($lineage, $rawPositionId);

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
        $effectiveRunId = $event->runId ?? $lineage?->getRunId();

        // Approximate initial risk in USDT from the most recent ORDER_SUBMITTED lifecycle event
        $pnlR = null;
        try {
            $criteria = [
                'symbol' => strtoupper($history->symbol),
                'eventType' => 'order_submitted',
            ];
            if ($effectiveRunId !== null) {
                $criteria['runId'] = $effectiveRunId;
            }
            if ($event->exchange !== null) {
                $criteria['exchange'] = $event->exchange;
            }
            if ($marketType !== null) {
                $criteria['marketType'] = $marketType;
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
                $klineProvider = $this->mainProvider
                    ->forContext(ExchangeContext::fromValues($event->exchange, $marketType))
                    ->getKlineProvider();
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
            runId: $effectiveRunId,
            exchange: $event->exchange,
            accountId: $event->accountId,
            reasonCode: $reasonCode,
            extra: array_merge(
                $lineage !== null ? $this->tradeLineageManager?->lifecycleExtra($lineage) ?? [] : [],
                [
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
                ],
                $event->extra,
            ),
            marketType: $marketType,
        );
    }

    // --- ORDRE EXPIRED / CLOSED SANS FILL -----------------------------------

    #[AsEventListener(event: OrderStateChangedEvent::class)]
    public function onOrderStateChanged(OrderStateChangedEvent $event): void
    {
        $order = $event->order;
        $marketType = $this->marketTypeFromExtra($event->extra);

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
                side: strtoupper($order->side->value),
                reasonCode: $reasonCode,
                runId: $event->runId,
                exchange: $event->exchange,
                accountId: $event->accountId,
                marketType: $marketType,
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

    /**
     * @param array<string,mixed> $extra
     */
    private function marketTypeFromExtra(array $extra): ?string
    {
        $marketType = $extra['market_type'] ?? $extra['marketType'] ?? null;

        return \is_string($marketType) && $marketType !== '' ? $marketType : null;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $extra
     */
    private function resolveLineageFromPositionPayload(
        array $raw,
        array $extra,
        ?string $marketType,
        mixed $positionId,
    ): ?\App\Entity\TradeLineage {
        if ($this->tradeLineageManager === null) {
            return null;
        }

        $context = ExchangeContext::fromValues(
            $this->stringValue($raw['exchange'] ?? $extra['exchange'] ?? null),
            $marketType ?? $this->stringValue($raw['market_type'] ?? $extra['market_type'] ?? null),
        );

        return $this->tradeLineageManager->resolve(
            $context,
            internalTradeId: $this->stringValue($extra['internal_trade_id'] ?? $raw['internal_trade_id'] ?? null),
            clientOrderId: $this->stringValue($extra['client_order_id'] ?? $raw['client_order_id'] ?? null),
            exchangeOrderId: $this->stringValue(
                $extra['exchange_order_id']
                    ?? $raw['exchange_order_id']
                    ?? $raw['order_id']
                    ?? $raw['last_order_id']
                    ?? null
            ),
            positionId: $this->stringValue($positionId),
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $extra
     */
    private function safeResolveLineageFromPositionPayload(
        array $raw,
        array $extra,
        ?string $marketType,
        mixed $positionId,
    ): ?\App\Entity\TradeLineage {
        try {
            return $this->resolveLineageFromPositionPayload($raw, $extra, $marketType, $positionId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeAttachPositionId(?\App\Entity\TradeLineage $lineage, mixed $positionId): void
    {
        $positionId = $this->stringValue($positionId);
        if ($lineage === null || $positionId === null) {
            return;
        }

        try {
            $this->tradeLineageManager?->attachPositionId($lineage, $positionId);
        } catch (\Throwable) {
            // Le lifecycle reste prioritaire; le lineage sera récupéré par un identifiant exact ultérieur.
        }
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function positionIdFromRaw(array $raw): mixed
    {
        return $raw['position_id']
            ?? $raw['positionId']
            ?? $this->positionIdFromNestedRaw($raw['raw_history'] ?? null)
            ?? $this->positionIdFromNestedRaw($raw['raw_snapshot'] ?? null)
            ?? null;
    }

    private function positionIdFromNestedRaw(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return null;
        }

        return $raw['position_id']
            ?? $raw['positionId']
            ?? null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
