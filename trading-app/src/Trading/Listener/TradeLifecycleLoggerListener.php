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
    /**
     * @var string[]
     */
    private const CERTIFIED_PNL_EXTRA_KEYS = [
        'gross_realized_pnl_usdt',
        'recorded_pnl_usdt',
        'entry_fee_usdt',
        'exit_fee_usdt',
        'other_trading_fees_usdt',
        'funding_usdt',
        'spread_cost_usdt',
        'slippage_cost_usdt',
        'borrow_cost_usdt',
        'liquidation_fee_usdt',
        'entry_qty',
        'exit_qty',
        'remaining_qty',
        'position_fully_closed',
        'fills_complete',
        'quantity_coherent',
        'lineage_sufficient',
        'identifier_conflict',
        'pnl_source',
        'cost_completeness',
    ];

    /**
     * @var string[]
     */
    private const LINEAGE_PAYLOAD_EXTRA_KEYS = [
        'internal_trade_id',
        'trade_id',
        'internal_position_id',
        'position_id',
        'exchange_position_id',
        'client_order_id',
        'exchange_order_id',
        'order_intent_id',
        'run_id',
        'correlation_run_id',
        'orchestration_run_id',
        'orchestration_set_id',
        'orchestration_dashboard_id',
        'mtf_profile',
        'profile',
        'origin',
        'attempt_number',
    ];

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
        $certifiedPnlExtra = $this->certifiedPnlExtraFromRaw($history->raw);

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
            if ($lineage !== null) {
                $criteria['internalTradeId'] = $lineage->getInternalTradeId();
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
        $mfeAt = null;
        $maeAt = null;
        $mfePct = null;
        $maePct = null;
        $mfeMaeSource = null;
        $mfeMaeTimeframe = null;
        $mfeMaeSampleCount = null;
        $mfeMaeExpectedSampleCount = null;
        $mfeMaeDataQuality = null;
        $mfeMaeLimit = 500;

        if ($this->mainProvider !== null) {
            $mfeMaeSource = 'kline_1m_high_low';
            $mfeMaeTimeframe = Timeframe::TF_1M->value;
            $mfeMaeSampleCount = 0;
            $mfeMaeExpectedSampleCount = $this->expectedOneMinuteSampleCount($history->openedAt, $history->closedAt);
            $klineOpenTimes = [];
            try {
                $klineProvider = $this->mainProvider
                    ->forContext(ExchangeContext::fromValues($event->exchange, $marketType))
                    ->getKlineProvider();
                $klines = $klineProvider->getKlinesInWindow(
                    $history->symbol,
                    Timeframe::TF_1M,
                    $history->openedAt,
                    $history->closedAt,
                    $mfeMaeLimit
                );

                foreach ($klines as $kline) {
                    $high = $this->klineNumericValue($kline, 'high');
                    $low = $this->klineNumericValue($kline, 'low');
                    if ($high === null || $low === null) {
                        continue;
                    }
                    $openedAt = $this->klineOpenedAt($kline);
                    if ($openedAt !== null && $openedAt >= $history->closedAt) {
                        continue;
                    }
                    ++$mfeMaeSampleCount;
                    if ($openedAt !== null) {
                        $klineOpenTimes[$openedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)] = true;
                    }

                    if (strtoupper($history->side->value) === 'LONG') {
                        // Favorable = plus haut, défavorable = plus bas
                        if ($mfePrice === null || $high > $mfePrice) {
                            $mfePrice = $high;
                            $mfeAt = $openedAt;
                        }
                        if ($maePrice === null || $low < $maePrice) {
                            $maePrice = $low;
                            $maeAt = $openedAt;
                        }
                    } else {
                        // SHORT: favorable = plus bas, défavorable = plus haut
                        if ($mfePrice === null || $low < $mfePrice) {
                            $mfePrice = $low;
                            $mfeAt = $openedAt;
                        }
                        if ($maePrice === null || $high > $maePrice) {
                            $maePrice = $high;
                            $maeAt = $openedAt;
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
                if ($mfeMaeSampleCount === 0 || $mfePrice === null || $maePrice === null) {
                    $mfeMaeDataQuality = 'missing_price_data';
                } elseif (
                    $this->isOneMinuteBoundary($history->openedAt)
                    && $this->isOneMinuteBoundary($history->closedAt)
                    && $mfeMaeExpectedSampleCount !== null
                    && $mfeMaeExpectedSampleCount <= $mfeMaeLimit
                    && \count($klineOpenTimes) >= $mfeMaeExpectedSampleCount
                ) {
                    $mfeMaeDataQuality = 'complete';
                } else {
                    $mfeMaeDataQuality = 'partial';
                }
            } catch (\Throwable) {
                // best-effort: metrics restent null en cas d'échec
                $mfeMaeDataQuality = 'provider_error';
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
                    'mfe_at' => $mfeAt?->format(\DateTimeInterface::ATOM),
                    'mae_at' => $maeAt?->format(\DateTimeInterface::ATOM),
                    'mfe_mae_source' => $mfeMaeSource,
                    'mfe_mae_timeframe' => $mfeMaeTimeframe,
                    'mfe_mae_window_start' => $history->openedAt->format(\DateTimeInterface::ATOM),
                    'mfe_mae_window_end' => $history->closedAt->format(\DateTimeInterface::ATOM),
                    'mfe_mae_sample_count' => $mfeMaeSampleCount,
                    'mfe_mae_expected_sample_count' => $mfeMaeExpectedSampleCount,
                    'mfe_mae_limit' => $mfeMaeLimit,
                    'mfe_mae_data_quality' => $mfeMaeDataQuality,
                    'fees' => $history->fees?->__toString(),
                    'raw'  => $history->raw,
                ],
                $certifiedPnlExtra,
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

        $payload = $this->positionPayloadFromRaw($raw);
        $context = ExchangeContext::fromValues(
            $this->stringValue($raw['exchange'] ?? $payload['exchange'] ?? $extra['exchange'] ?? null),
            $marketType ?? $this->stringValue($raw['market_type'] ?? $payload['market_type'] ?? $extra['market_type'] ?? null),
        );

        return $this->tradeLineageManager->resolve(
            $context,
            internalTradeId: $this->stringValue($extra['internal_trade_id'] ?? $raw['internal_trade_id'] ?? $payload['internal_trade_id'] ?? $payload['trade_id'] ?? null),
            clientOrderId: $this->stringValue($extra['client_order_id'] ?? $raw['client_order_id'] ?? $payload['client_order_id'] ?? null),
            exchangeOrderId: $this->stringValue(
                $extra['exchange_order_id']
                    ?? $raw['exchange_order_id']
                    ?? $payload['exchange_order_id']
                    ?? $raw['order_id']
                    ?? $payload['order_id']
                    ?? $raw['last_order_id']
                    ?? $payload['last_order_id']
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

    private function expectedOneMinuteSampleCount(\DateTimeImmutable $start, \DateTimeImmutable $end): ?int
    {
        $startTimestamp = $start->getTimestamp();
        $endTimestamp = $end->getTimestamp();
        if ($endTimestamp <= $startTimestamp) {
            return null;
        }

        return (int) ceil(($endTimestamp - $startTimestamp) / 60);
    }

    private function isOneMinuteBoundary(\DateTimeImmutable $time): bool
    {
        return $time->format('s.u') === '00.000000';
    }

    private function klineNumericValue(mixed $kline, string $field): ?float
    {
        $value = null;
        if (\is_array($kline)) {
            $value = $kline[$field]
                ?? $kline[$field . '_price']
                ?? $kline[$field . 'Price']
                ?? null;
        } elseif (\is_object($kline) && property_exists($kline, $field)) {
            $value = $kline->{$field};
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        return \is_numeric($value) ? (float) $value : null;
    }

    private function klineOpenedAt(mixed $kline): ?\DateTimeImmutable
    {
        $value = null;
        if (\is_array($kline)) {
            $value = $kline['openTime'] ?? $kline['open_time'] ?? $kline['timestamp'] ?? null;
        } elseif (\is_object($kline) && property_exists($kline, 'openTime')) {
            $value = $kline->openTime;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (\is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 9999999999) {
                $timestamp = (int) round($timestamp / 1000);
            }

            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
        }
        if (\is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
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
            ?? $this->positionIdFromNestedRaw($raw['payload'] ?? null)
            ?? null;
    }

    private function positionIdFromNestedRaw(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return null;
        }

        return $raw['position_id']
            ?? $raw['positionId']
            ?? $raw['exchange_position_id']
            ?? $raw['exchangePositionId']
            ?? null;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function certifiedPnlExtraFromRaw(array $raw): array
    {
        $payload = $this->positionPayloadFromRaw($raw);
        if ($payload === []) {
            return [];
        }

        $extra = [];
        foreach ([...self::CERTIFIED_PNL_EXTRA_KEYS, ...self::LINEAGE_PAYLOAD_EXTRA_KEYS] as $key) {
            if (!\array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if ($value === null || \is_scalar($value)) {
                $extra[$key] = $value;
            }
        }

        return $extra;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function positionPayloadFromRaw(array $raw): array
    {
        foreach ([
            $raw,
            $raw['raw_history'] ?? null,
            $raw['raw_snapshot'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && \is_array($candidate['payload'] ?? null)) {
                return $candidate['payload'];
            }
        }

        return [];
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
