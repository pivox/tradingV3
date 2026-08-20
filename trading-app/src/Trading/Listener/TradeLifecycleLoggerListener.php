<?php

declare(strict_types=1);

namespace App\Trading\Listener;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use App\Logging\TradeLifecycleLogger;
use App\Provider\Context\ExchangeContext;
use App\Repository\TradeLifecycleEventRepository;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Pnl\CanonicalFillEvidenceRefresherInterface;
use App\Trading\Pnl\CanonicalTradeFillWindowResolverInterface;
use App\Trading\Event\OrderStateChangedEvent;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Event\SymbolSkippedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class TradeLifecycleLoggerListener implements CanonicalFillEvidenceRefresherInterface
{
    private const FILL_EVIDENCE_TIMESTAMP_FORMAT = 'Y-m-d\\TH:i:s.uP';

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
        private readonly ?CanonicalTradeFillWindowResolverInterface $fillWindowResolver = null,
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

    public function refreshAfterFill(string $internalTradeId, string $exchange, string $marketType): void
    {
        if ($this->fillWindowResolver === null || $this->mainProvider === null) {
            return;
        }

        try {
            $window = $this->fillWindowResolver->resolve($internalTradeId, $exchange, $marketType);
            if ($window === null) {
                return;
            }
            $closedEvents = $this->tradeLifecycleRepository->findRecentBy([
                'internalTradeId' => $internalTradeId,
                'eventType' => 'position_closed',
                'exchange' => $exchange,
                'marketType' => $marketType,
            ], 1);
            $closed = $closedEvents[0] ?? null;
            if ($closed === null || !\in_array(strtoupper((string) $closed->getSide()), ['LONG', 'SHORT'], true)) {
                return;
            }

            $excursion = $this->calculateExcursionEvidence(
                $closed->getSymbol(),
                strtoupper((string) $closed->getSide()),
                $exchange,
                $marketType,
                $window->entryFirstFillAt,
                $window->exitLastFillAt,
                $window->entryVwap,
            );
            $holdingTimeSec = (float) $window->exitLastFillAt->format('U.u') - (float) $window->entryFirstFillAt->format('U.u');
            if (abs($holdingTimeSec - round($holdingTimeSec)) < 1e-9) {
                $holdingTimeSec = (int) round($holdingTimeSec);
            }
            $closed->setExtra(array_replace($closed->getExtra() ?? [], [
                'holding_time_sec' => $holdingTimeSec,
                'holding_time_source' => 'fill_cost_ledger_v1',
                'max_favorable_price' => $excursion['mfe_price'],
                'max_adverse_price' => $excursion['mae_price'],
                'mfe_pct' => $excursion['mfe_pct'],
                'mae_pct' => $excursion['mae_pct'],
                'mfe_at' => $excursion['mfe_at']?->format(\DateTimeInterface::ATOM),
                'mae_at' => $excursion['mae_at']?->format(\DateTimeInterface::ATOM),
                'mfe_mae_source' => $excursion['source'],
                'mfe_mae_timeframe' => $excursion['timeframe'],
                'mfe_mae_window_start' => $window->entryFirstFillAt->format(self::FILL_EVIDENCE_TIMESTAMP_FORMAT),
                'mfe_mae_window_end' => $window->exitLastFillAt->format(self::FILL_EVIDENCE_TIMESTAMP_FORMAT),
                'mfe_mae_window_source' => 'fill_cost_ledger_v1',
                'mfe_mae_entry_price_source' => 'fill_cost_ledger_v1',
                'mfe_mae_sample_count' => $excursion['sample_count'],
                'mfe_mae_expected_sample_count' => $excursion['expected_sample_count'],
                'mfe_mae_limit' => $excursion['limit'],
                'mfe_mae_data_quality' => $excursion['data_quality'],
            ]));
            $this->tradeLifecycleRepository->save($closed);
        } catch (\Throwable) {
            // Fill ingestion remains authoritative; unavailable analytics evidence stays explicitly non-canonical.
        }
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
        $analysisWindowStart = $history->openedAt;
        $analysisWindowEnd = $history->closedAt;
        $holdingTimeSource = 'provider_position_history';
        $analysisWindowSource = 'provider_position_history';
        $entryPriceSource = 'provider_position_history';
        if ($lineage !== null && $event->exchange !== null && $marketType !== null && $this->fillWindowResolver !== null) {
            try {
                $fillWindow = $this->fillWindowResolver->resolve(
                    $lineage->getInternalTradeId(),
                    $event->exchange,
                    $marketType,
                );
                if ($fillWindow !== null) {
                    $analysisWindowStart = $fillWindow->entryFirstFillAt;
                    $analysisWindowEnd = $fillWindow->exitLastFillAt;
                    $entryPriceFloat = $fillWindow->entryVwap;
                    $holdingTimeSource = 'fill_cost_ledger_v1';
                    $analysisWindowSource = 'fill_cost_ledger_v1';
                    $entryPriceSource = 'fill_cost_ledger_v1';
                }
            } catch (\Throwable) {
                // Ledger evidence is optional here; an unavailable or incomplete window remains explicit best-effort provider history.
            }
        }
        $holdingTimeSec = (float) $analysisWindowEnd->format('U.u') - (float) $analysisWindowStart->format('U.u');
        if (abs($holdingTimeSec - round($holdingTimeSec)) < 1e-9) {
            $holdingTimeSec = (int) round($holdingTimeSec);
        }
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

        $excursion = $this->calculateExcursionEvidence(
            $history->symbol,
            strtoupper($history->side->value),
            $event->exchange,
            $marketType,
            $analysisWindowStart,
            $analysisWindowEnd,
            $entryPriceFloat,
        );
        $mfePrice = $excursion['mfe_price'];
        $maePrice = $excursion['mae_price'];
        $mfeAt = $excursion['mfe_at'];
        $maeAt = $excursion['mae_at'];
        $mfePct = $excursion['mfe_pct'];
        $maePct = $excursion['mae_pct'];
        $mfeMaeSource = $excursion['source'];
        $mfeMaeTimeframe = $excursion['timeframe'];
        $mfeMaeSampleCount = $excursion['sample_count'];
        $mfeMaeExpectedSampleCount = $excursion['expected_sample_count'];
        $mfeMaeLimit = $excursion['limit'];
        $mfeMaeDataQuality = $excursion['data_quality'];

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
                    'fees' => $history->fees?->__toString(),
                    'raw'  => $history->raw,
                ],
                $certifiedPnlExtra,
                $event->extra,
                [
                    // Computed analysis evidence is authoritative over untrusted provider/event extras.
                    'holding_time_sec' => $holdingTimeSec,
                    'holding_time_source' => $holdingTimeSource,
                    'max_favorable_price' => $mfePrice,
                    'max_adverse_price' => $maePrice,
                    'mfe_pct' => $mfePct,
                    'mae_pct' => $maePct,
                    'mfe_at' => $mfeAt?->format(\DateTimeInterface::ATOM),
                    'mae_at' => $maeAt?->format(\DateTimeInterface::ATOM),
                    'mfe_mae_source' => $mfeMaeSource,
                    'mfe_mae_timeframe' => $mfeMaeTimeframe,
                    'mfe_mae_window_start' => $analysisWindowStart->format(self::FILL_EVIDENCE_TIMESTAMP_FORMAT),
                    'mfe_mae_window_end' => $analysisWindowEnd->format(self::FILL_EVIDENCE_TIMESTAMP_FORMAT),
                    'mfe_mae_window_source' => $analysisWindowSource,
                    'mfe_mae_entry_price_source' => $entryPriceSource,
                    'mfe_mae_sample_count' => $mfeMaeSampleCount,
                    'mfe_mae_expected_sample_count' => $mfeMaeExpectedSampleCount,
                    'mfe_mae_limit' => $mfeMaeLimit,
                    'mfe_mae_data_quality' => $mfeMaeDataQuality,
                ],
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

    /**
     * @return array{
     *   mfe_price:?float,mae_price:?float,mfe_at:?\DateTimeImmutable,mae_at:?\DateTimeImmutable,
     *   mfe_pct:?float,mae_pct:?float,source:?string,timeframe:?string,sample_count:?int,
     *   expected_sample_count:?int,limit:int,data_quality:?string
     * }
     */
    private function calculateExcursionEvidence(
        string $symbol,
        string $side,
        ?string $exchange,
        ?string $marketType,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        float $entryPrice,
    ): array {
        $evidence = [
            'mfe_price' => null,
            'mae_price' => null,
            'mfe_at' => null,
            'mae_at' => null,
            'mfe_pct' => null,
            'mae_pct' => null,
            'source' => null,
            'timeframe' => null,
            'sample_count' => null,
            'expected_sample_count' => null,
            'limit' => 500,
            'data_quality' => null,
        ];
        if ($this->mainProvider === null) {
            return $evidence;
        }

        $evidence['source'] = 'kline_1m_high_low';
        $evidence['timeframe'] = Timeframe::TF_1M->value;
        $evidence['sample_count'] = 0;
        $evidence['expected_sample_count'] = $this->expectedOneMinuteSampleCount($windowStart, $windowEnd);
        $klineOpenTimes = [];
        try {
            $klines = $this->mainProvider
                ->forContext(ExchangeContext::fromValues($exchange, $marketType))
                ->getKlineProvider()
                ->getKlinesInWindow($symbol, Timeframe::TF_1M, $windowStart, $windowEnd, $evidence['limit']);

            foreach ($klines as $kline) {
                $high = $this->klineNumericValue($kline, 'high');
                $low = $this->klineNumericValue($kline, 'low');
                $openedAt = $this->klineOpenedAt($kline);
                if (
                    $high === null
                    || $low === null
                    || $openedAt === null
                    || $openedAt < $windowStart
                    || $openedAt->modify('+1 minute') > $windowEnd
                ) {
                    continue;
                }
                ++$evidence['sample_count'];
                $klineOpenTimes[$openedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)] = true;

                if ($side === 'LONG') {
                    if ($evidence['mfe_price'] === null || $high > $evidence['mfe_price']) {
                        $evidence['mfe_price'] = $high;
                        $evidence['mfe_at'] = $openedAt;
                    }
                    if ($evidence['mae_price'] === null || $low < $evidence['mae_price']) {
                        $evidence['mae_price'] = $low;
                        $evidence['mae_at'] = $openedAt;
                    }
                } else {
                    if ($evidence['mfe_price'] === null || $low < $evidence['mfe_price']) {
                        $evidence['mfe_price'] = $low;
                        $evidence['mfe_at'] = $openedAt;
                    }
                    if ($evidence['mae_price'] === null || $high > $evidence['mae_price']) {
                        $evidence['mae_price'] = $high;
                        $evidence['mae_at'] = $openedAt;
                    }
                }
            }

            if ($entryPrice > 0.0 && $evidence['mfe_price'] !== null) {
                $evidence['mfe_pct'] = $side === 'LONG'
                    ? ($evidence['mfe_price'] - $entryPrice) / $entryPrice
                    : ($entryPrice - $evidence['mfe_price']) / $entryPrice;
            }
            if ($entryPrice > 0.0 && $evidence['mae_price'] !== null) {
                $evidence['mae_pct'] = $side === 'LONG'
                    ? ($entryPrice - $evidence['mae_price']) / $entryPrice
                    : ($evidence['mae_price'] - $entryPrice) / $entryPrice;
            }
            if ($evidence['sample_count'] === 0 || $evidence['mfe_price'] === null || $evidence['mae_price'] === null) {
                $evidence['data_quality'] = 'missing_price_data';
            } elseif (
                $this->isOneMinuteBoundary($windowStart)
                && $this->isOneMinuteBoundary($windowEnd)
                && $evidence['expected_sample_count'] !== null
                && $evidence['expected_sample_count'] <= $evidence['limit']
                && \count($klineOpenTimes) >= $evidence['expected_sample_count']
            ) {
                $evidence['data_quality'] = 'complete';
            } else {
                $evidence['data_quality'] = 'partial';
            }
        } catch (\Throwable) {
            $evidence['data_quality'] = 'provider_error';
        }

        return $evidence;
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
