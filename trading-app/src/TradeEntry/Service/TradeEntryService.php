<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\Dto\{TradeEntryRequest, ExecutionResult, ZoneSkipEventDto, PreflightReport};
use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Dto\FallbackEndOfZoneConfig;
use App\TradeEntry\Execution\ExecutionBox;
use App\Config\TradeEntryConfigResolver;
use App\TradeEntry\Exception\EntryZoneOutOfBoundsException;
use App\TradeEntry\Hook\PostExecutionHookInterface;
use App\TradeEntry\Workflow\{BuildPreOrder, BuildOrderPlan, ExecuteOrderPlan};
use App\Logging\TradeLifecycleLogger;
use App\Logging\TradeLifecycleReason;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Logging\Dto\LifecycleContextBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TradeEntryService
{
    public function __construct(
        private readonly BuildPreOrder $preflight,
        private readonly BuildOrderPlan $planner,
        private readonly ExecuteOrderPlan $executor,
        private readonly TradeEntryMetricsService $metrics,
        private readonly \App\TradeEntry\Policy\DailyLossGuard $dailyLossGuard,
        private readonly TradeEntryConfigResolver $tradeEntryConfigResolver,
        private readonly ExecutionBox $executionBox,
        private readonly TradeLifecycleLogger $tradeLifecycleLogger,
        private readonly ZoneSkipPersistenceService $zoneSkipPersistence,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function buildAndExecute(
        TradeEntryRequest $request,
        ?string $decisionKey = null,
        ?PostExecutionHookInterface $hook = null,
        ?string $mode = null, // Mode de configuration (ex: 'regular', 'scalping'). Si null, utilise la config par défaut.
        ?LifecycleContextBuilder $lifecycleContext = null,
    ): ExecutionResult {
        // Correlation key for logs across steps (allow external propagation)
        if ($decisionKey === null) {
            try {
                $decisionKey = sprintf('te:%s:%s', $request->symbol, bin2hex(random_bytes(6)));
            } catch (\Throwable) {
                $decisionKey = uniqid('te:' . $request->symbol . ':', true);
            }
        }
        if ($lifecycleContext !== null) {
            $lifecycleContext
                ->withDecisionKey($decisionKey)
                ->withProfile($mode);
        }

        // Daily loss guard: block trading when limit is reached
        try {
            $state = $this->dailyLossGuard->checkAndMaybeLock($mode);
            if ($state['locked'] === true) {
                $cid = sprintf('SKIP-DAILY-LOCK-%s', substr(sha1(($decisionKey ?? '') . microtime(true)), 0, 12));
                $this->positionsLogger->warning('order_journey.trade_entry.blocked', [
                    'symbol' => $request->symbol,
                    'decision_key' => $decisionKey,
                    'reason' => 'daily_loss_limit_reached',
                    'limit_usdt' => $state['limit_usdt'] ?? null,
                    'pnl_today' => $state['pnl_today'] ?? null,
                    'measure' => $state['measure'] ?? null,
                    'measure_value' => $state['measure_value'] ?? null,
                    'start_measure' => $state['start_measure'] ?? null,
                ]);
                $this->logSymbolSkippedEvent(
                    request: $request,
                    reasonCode: TradeLifecycleReason::DAILY_LOSS_LIMIT,
                    decisionKey: $decisionKey,
                    mode: $mode,
                    extra: [
                        'limit_usdt' => $state['limit_usdt'] ?? null,
                        'pnl_today' => $state['pnl_today'] ?? null,
                        'measure' => $state['measure'] ?? null,
                        'measure_value' => $state['measure_value'] ?? null,
                        'start_measure' => $state['start_measure'] ?? null,
                    ],
                    contextBuilder: $lifecycleContext,
                );
                return new ExecutionResult(
                    clientOrderId: $cid,
                    exchangeOrderId: null,
                    status: 'skipped',
                    raw: [
                        'reason' => 'daily_loss_limit_reached',
                        'limit_usdt' => $state['limit_usdt'] ?? null,
                        'pnl_today' => $state['pnl_today'] ?? null,
                        'measure' => $state['measure'] ?? null,
                        'measure_value' => $state['measure_value'] ?? null,
                        'start_measure' => $state['start_measure'] ?? null,
                    ],
                );
            }
        } catch (\Throwable $e) {
            // If guard fails unexpectedly, do not block, just log and continue
            $this->positionsLogger->error('order_journey.trade_entry.guard_error', [
                'symbol' => $request->symbol,
                'decision_key' => $decisionKey,
                'error' => $e->getMessage(),
            ]);
        }

        $entryConfig = $this->tradeEntryConfigResolver->resolve($mode);
        $configDefaults = $entryConfig->getDefaults();

        $this->positionsLogger->info('order_journey.trade_entry.preflight_start', [
            'symbol' => $request->symbol,
            'decision_key' => $decisionKey,
            'reason' => 'pretrade_checks_begin',
            'order_type' => $request->orderType,
            'side' => $request->side->value,
        ]);

        $preflight = ($this->preflight)($request, $decisionKey);
        if ($lifecycleContext !== null) {
            $this->captureMarketSnapshot($lifecycleContext, $preflight, $request);
        }

        $this->positionsLogger->debug('order_journey.trade_entry.preflight_snapshot', [
            'symbol' => $preflight->symbol,
            'decision_key' => $decisionKey,
            'best_bid' => $preflight->bestBid,
            'best_ask' => $preflight->bestAsk,
            'spread_pct' => $preflight->spreadPct,
            'available_usdt' => $preflight->availableUsdt,
            'reason' => 'snapshot_after_checks',
        ]);

        try {
            $plan = ($this->planner)($request, $preflight, $decisionKey);
        } catch (EntryZoneOutOfBoundsException $e) {
            $cid = sprintf('SKIP-ZONE-%s', substr(sha1(($decisionKey ?? '') . microtime(true)), 0, 12));

            $skipContext = $e->getContext();
            if ($lifecycleContext !== null) {
                $skipContext = $this->augmentSkipContext($skipContext, $lifecycleContext);
            }

            $this->positionsLogger->warning('order_journey.trade_entry.skipped', [
                'symbol' => $request->symbol,
                'decision_key' => $decisionKey,
                'reason' => $e->getReason(),
                'context' => $skipContext,
            ]);
            if ($lifecycleContext !== null) {
                $this->captureEntryZoneFromContext($lifecycleContext, $skipContext);
            }
            $this->persistZoneSkipEvent(
                request: $request,
                preflight: $preflight,
                decisionKey: $decisionKey,
                mode: $mode,
                context: $skipContext,
                lifecycleContext: $lifecycleContext,
            );
            $this->logSymbolSkippedEvent(
                request: $request,
                reasonCode: $e->getReason(),
                decisionKey: $decisionKey,
                mode: $mode,
                extra: $skipContext,
                contextBuilder: $lifecycleContext,
            );

            return new ExecutionResult(
                clientOrderId: $cid,
                exchangeOrderId: null,
                status: 'skipped',
                raw: [
                    'reason' => $e->getReason(),
                    'message' => $e->getMessage(),
                    'context' => $e->getContext(),
                ],
            );
        }

        // End-of-zone fallback decision (taker switch) if configured
        try {
            $fallbackCfg = $entryConfig->getFallbackEndOfZoneConfig();
            if ($fallbackCfg->enabled) {
                $zone = new EntryZone(
                    min: $plan->entryZoneLow ?? PHP_FLOAT_MIN,
                    max: $plan->entryZoneHigh ?? PHP_FLOAT_MAX,
                    rationale: 'plan_zone'
                );
                $currentPrice = (float)($preflight->markPrice ?? ($request->side->name === 'Long' ? $preflight->bestAsk : $preflight->bestBid));
                $nowTs = (new \DateTimeImmutable())->getTimestamp();
                $ttlRemaining = $plan->zoneExpiresAt ? max(0, $plan->zoneExpiresAt->getTimestamp() - $nowTs) : PHP_INT_MAX;

                $fallbackDecision = $this->executionBox->applyEndOfZoneFallback(
                    $fallbackCfg,
                    $zone,
                    $request->symbol,
                    $currentPrice,
                    $ttlRemaining
                );

                if (is_array($fallbackDecision)) {
                    $newOrderType = (string)($fallbackDecision['order_type'] ?? $plan->orderType);
                    $newOrderMode = $newOrderType === 'market' ? 1 : $plan->orderMode;
                    $plan = $plan->copyWith(orderType: $newOrderType, orderMode: $newOrderMode);
                }
            }
        } catch (\Throwable) {
            // non-blocking
        }

        if ($lifecycleContext !== null) {
            $this->captureEntryZoneMetrics($lifecycleContext, $plan, $preflight, $request, $configDefaults);
            $this->capturePlanMetrics($lifecycleContext, $plan);
        }

        $this->positionsLogger->info('order_journey.trade_entry.plan_ready', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'entry' => $plan->entry,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'order_mode' => $plan->orderMode,
            'reason' => 'plan_constructed',
        ]);

        $result = ($this->executor)($plan, $decisionKey, $lifecycleContext);
        if ($result->status === 'submitted') {
            $this->logOrderSubmittedEvent($plan, $result, $request, $decisionKey, $mode, $lifecycleContext);
        } elseif ($result->status === 'skipped') {
            $this->logSymbolSkippedEvent(
                request: $request,
                reasonCode: (string)($result->raw['reason'] ?? TradeLifecycleReason::SUBMIT_FAILED),
                decisionKey: $decisionKey,
                mode: $mode,
                extra: $result->raw,
                contextBuilder: $lifecycleContext,
            );
        }

        $this->positionsLogger->info('order_journey.trade_entry.execution_complete', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'status' => $result->status,
            'client_order_id' => $result->clientOrderId,
            'exchange_order_id' => $result->exchangeOrderId,
            'reason' => 'execution_finished',
        ]);

        $metric = match ($result->status) {
            'submitted' => 'submitted',
            'skipped' => 'skipped',
            default => 'errors',
        };
        $this->metrics->incr($metric);

        // Appeler le hook si fourni et si l'ordre a été soumis
        if ($hook !== null && $result->status === 'submitted') {
            $hook->onSubmitted($request, $result, $decisionKey);
        }

        return $result;
    }

    public function buildAndSimulate(
        TradeEntryRequest $request,
        ?string $decisionKey = null,
        ?PostExecutionHookInterface $hook = null,
        ?string $mode = null, // Mode de configuration (ex: 'regular', 'scalping'). Si null, utilise la config par défaut.
        ?LifecycleContextBuilder $lifecycleContext = null,
    ): ExecutionResult {
        if ($decisionKey === null) {
            try {
                $decisionKey = sprintf('te:%s:%s', $request->symbol, bin2hex(random_bytes(6)));
            } catch (\Throwable) {
                $decisionKey = uniqid('te:' . $request->symbol . ':', true);
            }
        }

        $this->positionsLogger->info('order_journey.trade_entry.simulation_start', [
            'symbol' => $request->symbol,
            'decision_key' => $decisionKey,
            'reason' => 'simulate_trade_entry',
        ]);

        $entryConfig = $this->tradeEntryConfigResolver->resolve($mode);
        $configDefaults = $entryConfig->getDefaults();

        // Run preflight and planning only (no execution)
        // Propagate decision key for consistent logging across steps
        $preflight = ($this->preflight)($request, $decisionKey);

        try {
            $plan = ($this->planner)($request, $preflight, $decisionKey);
        } catch (EntryZoneOutOfBoundsException $e) {
            $cid = 'SIM-SKIP-ZONE-' . substr(sha1($decisionKey), 0, 12);

            $this->positionsLogger->info('order_journey.trade_entry.simulation_skipped', [
                'symbol' => $request->symbol,
                'decision_key' => $decisionKey,
                'reason' => $e->getReason(),
                'context' => $e->getContext(),
            ]);

            return new ExecutionResult(
                clientOrderId: $cid,
                exchangeOrderId: null,
                status: 'skipped',
                raw: [
                    'reason' => $e->getReason(),
                    'message' => $e->getMessage(),
                    'context' => $e->getContext(),
                ],
            );
        }

        // End-of-zone fallback decision also applied in simulation to mirror logs
        try {
            $fallbackCfg = $entryConfig->getFallbackEndOfZoneConfig();
            if ($fallbackCfg->enabled) {
                $zone = new EntryZone(
                    min: $plan->entryZoneLow ?? PHP_FLOAT_MIN,
                    max: $plan->entryZoneHigh ?? PHP_FLOAT_MAX,
                    rationale: 'plan_zone'
                );
                $currentPrice = (float)($preflight->markPrice ?? ($request->side->name === 'Long' ? $preflight->bestAsk : $preflight->bestBid));
                $nowTs = (new \DateTimeImmutable())->getTimestamp();
                $ttlRemaining = $plan->zoneExpiresAt ? max(0, $plan->zoneExpiresAt->getTimestamp() - $nowTs) : PHP_INT_MAX;

                $fallbackDecision = $this->executionBox->applyEndOfZoneFallback(
                    $fallbackCfg,
                    $zone,
                    $request->symbol,
                    $currentPrice,
                    $ttlRemaining
                );

                if (is_array($fallbackDecision)) {
                    $newOrderType = (string)($fallbackDecision['order_type'] ?? $plan->orderType);
                    $newOrderMode = $newOrderType === 'market' ? 1 : $plan->orderMode;
                    $plan = $plan->copyWith(orderType: $newOrderType, orderMode: $newOrderMode);
                }
            }
        } catch (\Throwable) {
            // ignore during simulation
        }

        $cid = 'SIM-' . substr(sha1($decisionKey), 0, 12);
        $result = new ExecutionResult(
            clientOrderId: $cid,
            exchangeOrderId: null,
            status: 'simulated',
            raw: [
                'preflight' => [
                    'symbol' => $preflight->symbol,
                    'best_bid' => $preflight->bestBid,
                    'best_ask' => $preflight->bestAsk,
                    'price_precision' => $preflight->pricePrecision,
                    'available_usdt' => $preflight->availableUsdt,
                ],
                'plan' => [
                    'symbol' => $plan->symbol,
                    'side' => $plan->side->value,
                    'entry' => $plan->entry,
                    'stop' => $plan->stop,
                    'take_profit' => $plan->takeProfit,
                    'size' => $plan->size,
                    'leverage' => $plan->leverage,
                ],
            ],
        );

        // Appeler le hook si fourni
        if ($hook !== null) {
            $hook->onSimulated($request, $result, $decisionKey);
        }

        return $result;
    }

    /**
     * @param array<string,mixed>|null $extra
     */
    private function logSymbolSkippedEvent(
        TradeEntryRequest $request,
        string $reasonCode,
        ?string $decisionKey,
        ?string $mode,
        ?array $extra = null,
        ?LifecycleContextBuilder $contextBuilder = null,
    ): void {
        try {
            $payload = [
                'decision_key' => $decisionKey,
                'mode' => $mode,
            ];
            if ($extra !== null) {
                $payload = array_merge($payload, $extra);
            }
            $payload = $this->enrichZoneDeviationPayload($payload, $request);
            $payload = $this->sanitizeExtra($this->mergeContextExtra($contextBuilder, $payload));

            $this->tradeLifecycleLogger->logSymbolSkipped(
                symbol: $request->symbol,
                reasonCode: $reasonCode,
                timeframe: $request->executionTf,
                configProfile: $mode,
                extra: $payload,
            );
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('trade_lifecycle.skip_log_failed', [
                'symbol' => $request->symbol,
                'reason_code' => $reasonCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function persistZoneSkipEvent(
        TradeEntryRequest $request,
        \App\TradeEntry\Dto\PreflightReport $preflight,
        ?string $decisionKey,
        ?string $mode,
        array $context,
        ?LifecycleContextBuilder $lifecycleContext,
    ): void {
        try {
            $dto = $this->buildZoneSkipEventDto($request, $preflight, $decisionKey, $mode, $context, $lifecycleContext);
            if ($dto !== null) {
                $this->zoneSkipPersistence->persist($dto);
            }
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('zone_skip_event.persistence_failed', [
                'symbol' => $request->symbol,
                'decision_key' => $decisionKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildZoneSkipEventDto(
        TradeEntryRequest $request,
        \App\TradeEntry\Dto\PreflightReport $preflight,
        ?string $decisionKey,
        ?string $mode,
        array $context,
        ?LifecycleContextBuilder $lifecycleContext,
    ): ?ZoneSkipEventDto {
        $zoneMin = isset($context['zone_min']) ? (float)$context['zone_min'] : null;
        $zoneMax = isset($context['zone_max']) ? (float)$context['zone_max'] : null;
        $candidate = isset($context['candidate']) ? (float)$context['candidate'] : null;
        $zoneDevPct = isset($context['zone_dev_pct']) ? (float)$context['zone_dev_pct'] : null;
        $zoneMaxDevPct = isset($context['zone_max_dev_pct']) ? (float)$context['zone_max_dev_pct'] : null;

        if (
            $zoneMin === null ||
            $zoneMax === null ||
            $candidate === null ||
            $zoneDevPct === null ||
            $zoneMaxDevPct === null
        ) {
            return null;
        }

        [$mtfContext, $mtfLevel] = $this->extractMtfContext($lifecycleContext);

        return new ZoneSkipEventDto(
            symbol: $request->symbol,
            happenedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            decisionKey: $decisionKey,
            timeframe: $request->executionTf,
            configProfile: $mode,
            zoneMin: $zoneMin,
            zoneMax: $zoneMax,
            candidatePrice: $candidate,
            zoneDevPct: $zoneDevPct,
            zoneMaxDevPct: $zoneMaxDevPct,
            atrPct: $this->computeAtrPct($request->atrValue, $candidate),
            spreadBps: round($preflight->spreadPct * 10000, 4),
            volumeRatio: $preflight->volumeRatio,
            vwapDistancePct: null,
            entryZoneWidthPct: $this->computeZoneWidthPct($zoneMin, $zoneMax),
            mtfContext: $mtfContext,
            mtfLevel: $mtfLevel,
        );
    }

    private function computeZoneWidthPct(float $zoneMin, float $zoneMax): ?float
    {
        $mid = ($zoneMin + $zoneMax) / 2;
        if ($mid <= 0.0) {
            return null;
        }

        return ($zoneMax - $zoneMin) / $mid;
    }

    private function computeAtrPct(?float $atrValue, float $referencePrice): ?float
    {
        if ($atrValue === null || $referencePrice <= 0.0) {
            return null;
        }

        return $atrValue / max($referencePrice, 1e-9);
    }

    /**
     * @return array{0: array<int,string>, 1: ?string}
     */
    private function extractMtfContext(?LifecycleContextBuilder $builder): array
    {
        if ($builder === null) {
            return [[], null];
        }

        $snapshot = $builder->toArray();
        $context = [];
        if (isset($snapshot['mtf_context']) && \is_array($snapshot['mtf_context'])) {
            $context = array_values(array_map('strval', $snapshot['mtf_context']));
        }

        $level = isset($snapshot['mtf_level']) && \is_string($snapshot['mtf_level'])
            ? $snapshot['mtf_level']
            : null;

        return [$context, $level];
    }

    private function logOrderSubmittedEvent(
        OrderPlanModel $plan,
        ExecutionResult $result,
        TradeEntryRequest $request,
        ?string $decisionKey,
        ?string $mode,
        ?LifecycleContextBuilder $contextBuilder = null,
    ): void {
        if ($result->exchangeOrderId === null) {
            return;
        }

        $side = $plan->side === Side::Long ? 'BUY' : 'SELL';
        $price = $plan->orderType === 'market'
            ? null
            : $this->formatDecimal($plan->entry, $plan->pricePrecision);

        try {
            $extra = [
                'decision_key' => $decisionKey,
                'order_type' => $plan->orderType,
                'order_mode' => $plan->orderMode,
                'stop' => $plan->stop,
                'take_profit' => $plan->takeProfit,
                'leverage' => $plan->leverage,
                'trade_entry_mode' => $mode,
            ];
            $extra = $this->sanitizeExtra($this->mergeContextExtra($contextBuilder, $extra));

            $this->tradeLifecycleLogger->logOrderSubmitted(
                symbol: $plan->symbol,
                orderId: (string) $result->exchangeOrderId,
                clientOrderId: $result->clientOrderId,
                side: $side,
                qty: (string) $plan->size,
                price: $price,
                runId: null,
                exchange: null,
                accountId: null,
                extra: $extra,
                timeframe: $request->executionTf,
                configProfile: $mode,
            );
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('trade_lifecycle.submit_log_failed', [
                'symbol' => $plan->symbol,
                'order_id' => $result->exchangeOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function captureMarketSnapshot(LifecycleContextBuilder $builder, \App\TradeEntry\Dto\PreflightReport $preflight, TradeEntryRequest $request): void
    {
        $mid = 0.5 * ($preflight->bestBid + $preflight->bestAsk);
        $spreadBps = $preflight->spreadPct * 10000;
        $builder->withMarket([
            'spread_bps' => round($spreadBps, 4),
            'book_liquidity_score' => $preflight->bookLiquidityScore !== null ? round($preflight->bookLiquidityScore, 4) : null,
            'volatility_pct_1m' => $preflight->volatilityPct1m !== null ? round($preflight->volatilityPct1m, 6) : null,
            'volume_ratio' => $preflight->volumeRatio !== null ? round($preflight->volumeRatio, 4) : null,
            'depth_top_usd' => $preflight->depthTopUsd,
        ]);
        $builder->withInfra([
            'latency_ms_rest' => $preflight->latencyRestMs,
            'latency_ms_ws' => $preflight->latencyWsMs,
        ]);
    }

    private function captureEntryZoneMetrics(
        LifecycleContextBuilder $builder,
        OrderPlanModel $plan,
        PreflightReport $preflight,
        TradeEntryRequest $request,
        array $configDefaults = []
    ): void
    {
        $low = $plan->entryZoneLow;
        $high = $plan->entryZoneHigh;
        $entry = $plan->entry;
        if ($low === null || $high === null || $entry <= 0.0) {
            return;
        }

        $mid = ($low + $high) / 2;
        $widthPct = $mid > 0.0 ? ($high - $low) / $mid : null;
        $distancePct = null;
        $direction = 'inside';
        if ($entry > $high) {
            $direction = 'above';
            $distancePct = ($entry - $high) / $entry;
        } elseif ($entry < $low) {
            $direction = 'below';
            $distancePct = ($low - $entry) / $entry;
        }
        $inZone = $direction === 'inside';

        $atrValue = $plan->entryZoneMeta['atr'] ?? $request->atrValue;
        $atrPct = ($atrValue !== null && $entry > 0.0) ? $atrValue / $entry : null;
        $atrTimeframe = $plan->entryZoneMeta['timeframe'] ?? $request->executionTf;

        $pivotSource = $plan->entryZoneMeta['pivot_source'] ?? null;
        $pivotValue = $plan->entryZoneMeta['pivot'] ?? null;
        $vwapDistancePct = null;
        if ($pivotSource === 'vwap' && \is_numeric($pivotValue) && $entry > 0.0) {
            $vwapDistancePct = abs($entry - (float)$pivotValue) / $entry;
        }

        $referencePrice = $this->resolveEntryReferencePrice($preflight, $request);
        $zoneDeviation = $this->computeZoneDeviation($referencePrice, $low, $high);
        if (
            $zoneDeviation === null &&
            $referencePrice !== null &&
            $referencePrice >= $low &&
            $referencePrice <= $high
        ) {
            $zoneDeviation = 0.0;
        }
        $zoneMaxDeviation = $this->resolveZoneMaxDeviation($request, $configDefaults);

        $builder->withEntryZone([
            'width_pct' => $widthPct !== null ? round($widthPct, 6) : null,
            'atr_pct' => $atrPct !== null ? round($atrPct, 6) : null,
            'atr_timeframe' => $atrTimeframe,
            'vwap_distance_pct' => $vwapDistancePct !== null ? round($vwapDistancePct, 6) : null,
            'distance_from_zone_pct' => $distancePct !== null ? round($distancePct, 6) : null,
            'zone_direction' => $direction,
            'in_zone' => $inZone,
        ]);

        $builder->merge([
            'zone_dev_pct' => $zoneDeviation,
            'zone_max_dev_pct' => $zoneMaxDeviation,
        ]);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function captureEntryZoneFromContext(LifecycleContextBuilder $builder, array $context): void
    {
        $low = isset($context['zone_min']) ? (float)$context['zone_min'] : null;
        $high = isset($context['zone_max']) ? (float)$context['zone_max'] : null;
        $candidate = isset($context['candidate']) ? (float)$context['candidate'] : null;
        if ($low === null || $high === null || $candidate === null || $candidate <= 0.0) {
            return;
        }

        $mid = ($low + $high) / 2;
        $widthPct = $mid > 0.0 ? ($high - $low) / $mid : null;
        $distancePct = null;
        $direction = 'inside';
        if ($candidate > $high) {
            $direction = 'above';
            $distancePct = ($candidate - $high) / $candidate;
        } elseif ($candidate < $low) {
            $direction = 'below';
            $distancePct = ($low - $candidate) / $candidate;
        }
        $inZone = $direction === 'inside';

        $builder->withEntryZone([
            'width_pct' => $widthPct,
            'atr_pct' => $context['zone_dev_pct'] ?? null,
            'atr_timeframe' => $context['context_tf'] ?? null,
            'vwap_distance_pct' => null,
            'distance_from_zone_pct' => $distancePct,
            'zone_direction' => $direction,
            'in_zone' => $inZone,
        ]);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function augmentSkipContext(array $context, ?LifecycleContextBuilder $builder): array
    {
        if ($builder === null) {
            return $context;
        }

        $snapshot = $builder->toArray();
        foreach (['zone_dev_pct', 'zone_max_dev_pct', 'price_vs_ma21_k_atr', 'entry_rsi', 'volume_ratio', 'r_multiple_final'] as $key) {
            if (!array_key_exists($key, $snapshot)) {
                continue;
            }
            if (!array_key_exists($key, $context)) {
                $context[$key] = $snapshot[$key];
            }
        }

        return $context;
    }

    private function resolveEntryReferencePrice(PreflightReport $preflight, TradeEntryRequest $request): ?float
    {
        $mark = $preflight->markPrice;
        if ($mark !== null && $mark > 0.0) {
            return $mark;
        }

        return $request->side === Side::Long ? $preflight->bestAsk : $preflight->bestBid;
    }

    private function resolveZoneMaxDeviation(TradeEntryRequest $request, array $defaults = []): ?float
    {
        $value = $request->zoneMaxDeviationPct;
        if ($value === null) {
            if (isset($defaults['zone_max_deviation_pct']) && \is_numeric($defaults['zone_max_deviation_pct'])) {
                $value = (float) $defaults['zone_max_deviation_pct'];
            }
        }

        return $value !== null ? $this->normalizePercent($value) : null;
    }

    private function capturePlanMetrics(LifecycleContextBuilder $builder, OrderPlanModel $plan): void
    {
        $risk = null;
        $reward = null;
        if ($plan->side === Side::Long) {
            $risk = $plan->entry - $plan->stop;
            $reward = $plan->takeProfit - $plan->entry;
        } else {
            $risk = $plan->stop - $plan->entry;
            $reward = $plan->entry - $plan->takeProfit;
        }
        $risk = $risk > 0 ? $risk : null;
        $reward = $reward > 0 ? $reward : null;
        $expectedR = ($risk !== null && $reward !== null && $risk > 0.0) ? $reward / $risk : null;
        $rStopPct = ($risk !== null && $plan->entry > 0.0) ? $risk / $plan->entry : null;
        $rTpPct = ($reward !== null && $plan->entry > 0.0) ? $reward / $plan->entry : null;

        $builder->withPlan([
            'expected_r_multiple' => $expectedR !== null ? round($expectedR, 4) : null,
            'r_stop_pct' => $rStopPct !== null ? round($rStopPct, 6) : null,
            'r_tp1_pct' => $rTpPct !== null ? round($rTpPct, 6) : null,
            'leverage_target' => $plan->leverage,
            'conviction_score' => null,
        ]);

        $builder->merge([
            'r_multiple_final' => $expectedR !== null ? round($expectedR, 4) : null,
        ]);
    }

    private function formatDecimal(float $value, int $scale): string
    {
        $formatted = number_format($value, $scale, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function enrichZoneDeviationPayload(array $payload, TradeEntryRequest $request): array
    {
        $hasZoneDev = isset($payload['zone_dev_pct']) && is_numeric($payload['zone_dev_pct']);
        if (!$hasZoneDev) {
            $candidate = isset($payload['candidate']) && is_numeric($payload['candidate'])
                ? (float) $payload['candidate']
                : null;
            $zoneMin = isset($payload['zone_min']) && is_numeric($payload['zone_min'])
                ? (float) $payload['zone_min']
                : null;
            $zoneMax = isset($payload['zone_max']) && is_numeric($payload['zone_max'])
                ? (float) $payload['zone_max']
                : null;
            $deviation = $this->computeZoneDeviation($candidate, $zoneMin, $zoneMax);
            if ($deviation !== null) {
                $payload['zone_dev_pct'] = $deviation;
            }
        }

        $hasZoneMax = isset($payload['zone_max_dev_pct']) && is_numeric($payload['zone_max_dev_pct']);
        if (!$hasZoneMax && $request->zoneMaxDeviationPct !== null) {
            $payload['zone_max_dev_pct'] = $this->normalizePercent($request->zoneMaxDeviationPct);
        }

        return $payload;
    }

    private function computeZoneDeviation(?float $candidate, ?float $zoneMin, ?float $zoneMax): ?float
    {
        if ($candidate === null || $candidate <= 0.0) {
            return null;
        }

        $distance = null;
        if ($zoneMin !== null && $candidate < $zoneMin) {
            $distance = ($zoneMin - $candidate) / $candidate;
        } elseif ($zoneMax !== null && $candidate > $zoneMax) {
            $distance = ($candidate - $zoneMax) / $candidate;
        }

        if ($distance !== null && $distance > 0.0) {
            return round($distance, 6);
        }

        return null;
    }

    private function normalizePercent(float $value): float
    {
        $value = max(0.0, $value);
        if ($value > 1.0) {
            $value *= 0.01;
        }

        return min($value, 1.0);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function mergeContextExtra(?LifecycleContextBuilder $builder, array $extra): array
    {
        if ($builder === null) {
            return $extra;
        }

        return array_merge($builder->toArray(), $extra);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function sanitizeExtra(array $extra): array
    {
        return array_filter(
            $extra,
            static fn($value) => $value !== null && $value !== ''
        );
    }
}
