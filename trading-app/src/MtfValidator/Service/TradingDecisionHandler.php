<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Config\{TradeEntryConfig, TradeEntryConfigResolver, MtfValidationConfig};
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\TradeEntry\Builder\TradeEntryRequestBuilder;
use App\TradeEntry\Hook\MtfPostExecutionHook;
use App\TradeEntry\Service\TradeEntryService;
use App\Contract\Provider\MainProviderInterface;
use App\Common\Enum\Timeframe;
use App\Logging\LifecycleContextFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gestionnaire des décisions de trading qui délègue à TradeEntryService.
 */
final class TradingDecisionHandler
{
    private const ENTRY_ZONE_DEFAULT_K_ATR = 0.30;
    private const ENTRY_ZONE_DEFAULT_W_MIN = 0.0005;
    private const ENTRY_ZONE_DEFAULT_W_MAX = 0.0100;

    public function __construct(
        private readonly TradeEntryService $tradeEntryService,
        private readonly TradeEntryRequestBuilder $requestBuilder,
        private readonly \App\MtfValidator\Execution\ExecutionSelector $executionSelector,
        private readonly IndicatorProviderInterface $indicatorProvider,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $mtfLogger,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $positionsFlowLogger,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
        private readonly TradeEntryConfigResolver $tradeEntryConfigResolver,
        private readonly MtfValidationConfig $mtfConfig,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly LifecycleContextFactory $lifecycleContextFactory,
        private readonly ?MainProviderInterface $mainProvider = null,
    ) {}

    public function handleTradingDecision(SymbolResultDto $symbolResult, MtfRunDto $mtfRunDto, string $runId): SymbolResultDto
    {
        if ($symbolResult->isError() || $symbolResult->isSkipped()) {
            return $symbolResult;
        }

        if (strtoupper($symbolResult->status) !== 'READY') {
            return $symbolResult;
        }

        $decisionKey = $this->generateDecisionKey($symbolResult->symbol);
        // trade_id global pour ce cycle de trade (zone → ouverture → clôture)
        try {
            $tradeId = sprintf(
                'trd:%s:%s',
                strtolower($symbolResult->symbol),
                bin2hex(random_bytes(6))
            );
        } catch (\Throwable) {
            $tradeId = uniqid('trd:' . strtolower($symbolResult->symbol) . ':', true);
        }
        // Force ATR to the 5m timeframe so downstream sizing/guards stay consistent across execution TFs.
        $forcedAtr5m = $this->indicatorProvider->getAtr(symbol: $symbolResult->symbol, tf: '5m');

        $resolvedMode = $this->tradeEntryConfigResolver->resolveMode($symbolResult->tradeEntryModeUsed);
        $tradeEntryConfig = $this->tradeEntryConfigResolver->resolve($symbolResult->tradeEntryModeUsed);

        $this->mtfLogger->info('order_journey.signal_ready', [
            'symbol' => $symbolResult->symbol,
            'execution_tf' => $symbolResult->executionTf,
            'side' => $symbolResult->signalSide,
            'decision_key' => $decisionKey,
            'trade_id' => $tradeId,
            'run_id' => $runId,
            'reason' => 'mtf_signal_ready',
        ]);

        // 1. Validation MTF spécifique
        if (!$this->canExecuteMtfTrading($symbolResult, $mtfRunDto, $forcedAtr5m, $decisionKey, null, $tradeEntryConfig)) {
            return $this->createSkippedResult($symbolResult, 'trading_conditions_not_met', $forcedAtr5m, $decisionKey, $resolvedMode);
        }

        // 2. Utiliser le TF d'exécution déjà décidé par le pipeline MTF (ExecutionSelectionService)
        // Plus de re-sélection : on prend directement le TF calculé par MtfValidatorCoreService
        if ($symbolResult->executionTf === null || $symbolResult->executionTf === '') {
            $this->mtfLogger->error('[TradingDecision] executionTf is required but missing', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'status' => $symbolResult->status,
            ]);
            return $this->createSkippedResult($symbolResult, 'execution_tf_missing', $forcedAtr5m, $decisionKey, $resolvedMode);
        }
        $effectiveTf = $symbolResult->executionTf;
        
        // ATR du TF d'exécution (fallbacks hiérarchiques)
        $atrForTf = null;
        try { $atrForTf = $this->indicatorProvider->getAtr(symbol: $symbolResult->symbol, tf: $effectiveTf); } catch (\Throwable) {}
        if (!\is_float($atrForTf) || $atrForTf <= 0.0) {
            // Fallbacks : 5m puis 15m
            try { $atrForTf = $this->indicatorProvider->getAtr(symbol: $symbolResult->symbol, tf: '5m'); } catch (\Throwable) {}
            if (!\is_float($atrForTf) || $atrForTf <= 0.0) {
                try { $atrForTf = $this->indicatorProvider->getAtr(symbol: $symbolResult->symbol, tf: '15m'); } catch (\Throwable) {}
            }
        }

        $this->mtfLogger->info('[TradingDecision] Using execution TF from ExecutionSelectionService', [
            'symbol' => $symbolResult->symbol,
            'decision_key' => $decisionKey,
            'execution_tf' => $effectiveTf,
            'source' => 'ExecutionSelectionService',
        ]);

        $lifecycleContext = $this->lifecycleContextFactory->create($symbolResult->symbol)
            ->withDecisionKey($decisionKey)
            ->withTradeId($tradeId)
            ->withProfile($resolvedMode)
            ->withMtfContext($effectiveTf, $this->extractMtfContext($symbolResult), $symbolResult->blockingTf)
            ->withSelectorDecision(null, null) // Plus de sélection ici: décidé en amont par ExecutionSelectionService
            ->withIndicatorMetrics(null, null, null) // Plus de métriques de sélection
            ->merge([
                'config_version' => $tradeEntryConfig->getVersion(),
                'run_id' => $runId,
            ]);

        // 3. Construction via Builder (délégation) avec champs minimaux
        $tradeRequest = $this->requestBuilder->fromMtfSignal(
            $symbolResult->symbol,
            (string)$symbolResult->signalSide,
            $effectiveTf,
            $symbolResult->currentPrice,
            (\is_float($atrForTf) && $atrForTf > 0.0) ? $atrForTf : $forcedAtr5m,
            $resolvedMode, // Passer le mode (même mécanisme que validations.{mode}.yaml)
        );

        if ($tradeRequest === null) {
            $this->mtfLogger->warning('order_journey.trade_request.unable_to_build', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'builder_returned_null',
            ]);
            return $this->createSkippedResult($symbolResult, 'unable_to_build_request', $forcedAtr5m, $decisionKey, $resolvedMode);
        }

        $this->mtfLogger->info('order_journey.trade_request.built', [
            'symbol' => $tradeRequest->symbol,
            'decision_key' => $decisionKey,
            'trade_id' => $tradeId,
            'order_type' => $tradeRequest->orderType,
            'order_mode' => $tradeRequest->orderMode,
            'risk_pct' => $tradeRequest->riskPct,
            'initial_margin_usdt' => $tradeRequest->initialMarginUsdt,
            'run_id' => $runId,
            'stop_from' => $tradeRequest->stopFrom,
            'validation_mode_used' => $symbolResult->validationModeUsed,
            'trade_entry_mode_used' => $resolvedMode,
            'reason' => 'mtf_defaults_applied',
        ]);

        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Executing trade entry', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
                'side' => $symbolResult->signalSide,
                'decision_key' => $decisionKey,
                'trade_id' => $tradeId,
                'run_id' => $runId,
                'validation_mode_used' => $symbolResult->validationModeUsed,
                'trade_entry_mode_used' => $resolvedMode,
            ]);

            $this->mtfLogger->info('order_journey.trade_entry.dispatch', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'trade_id' => $tradeId,
                'run_id' => $runId,
                'dry_run' => $mtfRunDto->dryRun,
                'validation_mode_used' => $symbolResult->validationModeUsed,
                'trade_entry_mode_used' => $resolvedMode,
                'reason' => $mtfRunDto->dryRun ? 'dry_run_simulation' : 'live_execution',
            ]);

            // 3. Exécution avec hook (délégation)
            $hook = new MtfPostExecutionHook(
                $this->mtfSwitchRepository,
                $this->auditLogger,
                $mtfRunDto->dryRun,
                $this->positionsLogger
            );

            $execution = $mtfRunDto->dryRun
                ? $this->tradeEntryService->buildAndSimulate($tradeRequest, $decisionKey, $hook, $resolvedMode, $lifecycleContext, $runId, $tradeId)
                : $this->tradeEntryService->buildAndExecute($tradeRequest, $decisionKey, $hook, $resolvedMode, $lifecycleContext, $runId, $tradeId);

            $decision = [
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'raw' => $execution->raw,
                'decision_key' => $decisionKey,
                'trade_id' => $tradeId,
            ];

            $this->mtfLogger->info('order_journey.trade_entry.result', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'run_id' => $runId,
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'validation_mode_used' => $symbolResult->validationModeUsed,
                'trade_entry_mode_used' => $resolvedMode,
                'reason' => 'trade_entry_service_completed',
            ]);

            $this->logExecution($symbolResult->symbol, $decision);

            return new SymbolResultDto(
                symbol: $symbolResult->symbol,
                status: $symbolResult->status,
                executionTf: $effectiveTf,
                signalSide: $symbolResult->signalSide,
                tradingDecision: $decision,
                error: $symbolResult->error,
                context: $symbolResult->context,
                currentPrice: $symbolResult->currentPrice,
                atr: $forcedAtr5m,
                validationModeUsed: $symbolResult->validationModeUsed,
                tradeEntryModeUsed: $resolvedMode
            );
        } catch (\Throwable $e) {
            $this->mtfLogger->error('[Trading Decision] Trade entry execution failed', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);

            $this->positionsFlowLogger->error('[PositionsFlow] Trade entry failed', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);

            $this->mtfLogger->error('order_journey.trade_entry.failed', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'exception_during_trade_entry',
                'error' => $e->getMessage(),
            ]);

            $this->auditLogger->logAction(
                'TRADE_ENTRY_FAILED',
                'TRADE_ENTRY',
                $symbolResult->symbol,
                [
                    'error' => $e->getMessage(),
                    'execution_tf' => $symbolResult->executionTf,
                ]
            );

            return new SymbolResultDto(
                symbol: $symbolResult->symbol,
                status: $symbolResult->status,
                executionTf: $effectiveTf,
                signalSide: $symbolResult->signalSide,
                tradingDecision: [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ],
                error: $symbolResult->error,
                context: $symbolResult->context,
                currentPrice: $symbolResult->currentPrice,
                atr: $forcedAtr5m,
                validationModeUsed: $symbolResult->validationModeUsed,
                tradeEntryModeUsed: $resolvedMode
            );
        }
    }

    /**
     * Validation MTF spécifique (garde dans TradingDecisionHandler).
     * Les validations génériques sont déléguées à TradeEntryService.
     */
    private function canExecuteMtfTrading(
        SymbolResultDto $symbolResult,
        MtfRunDto $mtfRunDto,
        ?float $forcedAtr5m = null,
        ?string $decisionKey = null,
        ?string $executionTfOverride = null,
        ?TradeEntryConfig $tradeEntryConfig = null
    ): bool
    {
        if ($symbolResult->executionTf === null) {
            $this->mtfLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'missing_execution_tf',
            ]);
            return false;
        }

        // En dry-run, si le flag est activé, accepter tous les timeframes (y compris 4h et 1h)
        $decision = $tradeEntryConfig?->getDecision() ?? [];
        $dryRunValidateAllTfs = (bool)($this->mtfConfig->getDefault('dry_run_validate_all_timeframes', false));
        $allTimeframes = ['1m', '5m', '15m', '1h', '4h'];

        $effectiveTf = strtolower((string)($executionTfOverride ?? $symbolResult->executionTf));
        if ($mtfRunDto->dryRun && $dryRunValidateAllTfs) {
            // En dry-run avec flag activé, accepter tous les timeframes
            if (!in_array($effectiveTf, array_map('strtolower', $allTimeframes), true)) {
                $this->mtfLogger->info('[Trading Decision] Skipping (unsupported execution TF even in dry-run)', [
                    'symbol' => $symbolResult->symbol,
                    'execution_tf' => $effectiveTf,
                    'dry_run' => true,
                ]);
                $this->mtfLogger->info('order_journey.preconditions.blocked', [
                    'symbol' => $symbolResult->symbol,
                    'decision_key' => $decisionKey,
                    'reason' => 'unsupported_execution_tf',
                    'execution_tf' => $symbolResult->executionTf,
                    'dry_run' => true,
                ]);
                return false;
            }
            // Log que tous les TF sont acceptés en dry-run
            $this->mtfLogger->debug('[Trading Decision] Dry-run mode: accepting all timeframes', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
            ]);
        } else {
            // Mode normal : validation optionnelle avec allowed_execution_timeframes depuis TradeEntryConfig
            // Note: Le TF est déjà décidé par ExecutionSelectionService, on valide juste qu'il est autorisé par TradeEntry
            $allowedTfs = (array)($decision['allowed_execution_timeframes'] ?? []);
            // Si allowed_execution_timeframes est vide ou non défini, on accepte tous les TF
            if (!empty($allowedTfs) && !in_array($effectiveTf, array_map('strtolower', $allowedTfs), true)) {
                $this->mtfLogger->info('[Trading Decision] Skipping (execution TF not allowed by TradeEntry config)', [
                    'symbol' => $symbolResult->symbol,
                    'execution_tf' => $effectiveTf,
                    'allowed_tfs' => $allowedTfs,
                    'note' => 'TF was decided by ExecutionSelectionService but rejected by TradeEntry allowed_execution_timeframes',
                ]);
                $this->mtfLogger->info('order_journey.preconditions.blocked', [
                    'symbol' => $symbolResult->symbol,
                    'decision_key' => $decisionKey,
                    'reason' => 'execution_tf_not_allowed_by_trade_entry',
                    'execution_tf' => $effectiveTf,
                ]);
                return false;
            }
        }

        if ($symbolResult->signalSide === null) {
            $this->mtfLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'missing_signal_side',
            ]);
            return false;
        }

        if ($symbolResult->currentPrice === null && ($forcedAtr5m === null || $forcedAtr5m <= 0.0)) {
            $this->mtfLogger->debug('[Trading Decision] Missing price and ATR', [
                'symbol' => $symbolResult->symbol,
            ]);
            $this->mtfLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'missing_price_and_atr',
            ]);
            return false;
        }

        $this->mtfLogger->debug('order_journey.preconditions.passed', [
            'symbol' => $symbolResult->symbol,
            'decision_key' => $decisionKey,
            'execution_tf' => $effectiveTf,
            'signal_side' => $symbolResult->signalSide,
        ]);

        return true;
    }

    /**
     * Construit un contexte minimal pour le sélecteur d'exécution.
     * Les valeurs absentes restent nulles (les conditions géreront missing_data).
     * @return array<string,mixed>
     */
    private function buildSelectorContext(SymbolResultDto $symbolResult, ?TradeEntryConfig $tradeEntryConfig = null): array
    {
        $symbol = $symbolResult->symbol;
        $price = $symbolResult->currentPrice ?? null;
        $atr15m = null;
        try { $atr15m = $this->indicatorProvider->getAtr(symbol: $symbol, tf: '15m'); } catch (\Throwable) {}
        $atrPct15mBps = ($atr15m !== null && $price !== null && $price > 0.0)
            ? (10000.0 * $atr15m / $price) : null;

        // Valeur R multiple attendue (fallback au défaut de config)
        $defaults = $tradeEntryConfig?->getDefaults() ?? [];
        $expectedR = isset($defaults['r_multiple']) ? (float)$defaults['r_multiple'] : 2.0;

        // Contexte de base consommé par l'ExecutionSelector
        $context = [
            'expected_r_multiple' => $expectedR,
            'entry_zone_width_pct' => null, // à enrichir via EntryZoneCalculator si souhaité
            'atr_pct_15m_bps' => $atrPct15mBps,
            'adx_5m' => null,
            'spread_bps' => null,
            'volume_ratio' => null,
            'scalping' => false,
            'trailing_after_tp1' => false,
            'end_of_zone_fallback' => false,
        ];

        // Enrichissement pour filters_mandatory: fournir les clés attendues par les conditions
        // rsi_lt_70, price_lte_ma21_plus_k_atr, pullback_confirmed_ma9_21, pullback_confirmed_vwap, adx_min_for_trend_1h, lev_bounds
        $context['symbol'] = $symbol;
        $context['timeframe'] = '15m';
        if ($price !== null) {
            $context['close'] = (float) $price;
        } else {
            // Fallback: récupérer la dernière close kline 15m via MainProvider si disponible
            try {
                if ($this->mainProvider !== null) {
                    $klines = $this->mainProvider->getKlineProvider()->getKlines($symbol, Timeframe::TF_15M, 2);
                    if (!empty($klines)) {
                        $last = end($klines);
                        if (\is_object($last) && isset($last->close)) {
                            $context['close'] = (float) $last->close->toFloat();
                            $price = $context['close'];
                        }
                    }
                }
            } catch (\Throwable) {
                // best-effort
            }
        }

        // Snapshot 15m: rsi, vwap, ma9, ma21, atr
        $entryRsiValue = null;
        $ma21Value = null;
        $atrForMa21 = null;

        try {
            $snap = $this->indicatorProvider->getSnapshot($symbol, '15m');
            // rsi
            if (isset($snap->rsi) && is_float($snap->rsi)) {
                $context['rsi'] = $snap->rsi;
                $entryRsiValue = $snap->rsi;
            }
            // vwap
            if ($snap->vwap !== null) {
                $context['vwap'] = (float) ((string) $snap->vwap);
            }
            // ma9 / ma21
            $ma = [];
            if ($snap->ma9 !== null) { $ma[9] = (float) ((string) $snap->ma9); }
            if ($snap->ma21 !== null) {
                $ma21Value = (float) ((string) $snap->ma21);
                $ma[21] = $ma21Value;
            }
            if ($ma !== []) { $context['ma'] = $ma; }

            // ma_21_plus_k_atr pour price_lte_ma21_plus_k_atr
            $atrK = (float) ($defaults['atr_k'] ?? 1.5);
            $atrVal = $snap->atr !== null ? (float) ((string) $snap->atr) : ($atr15m ?? null);
            $ma21 = $ma21Value;
            if ($atr15m === null && $atrVal !== null) {
                $atr15m = $atrVal;
            }
            if ($ma21 !== null && $atrVal !== null) {
                $atrForMa21 = $atrVal;
                $context['ma_21_plus_k_atr'] = $ma21 + ($atrK * $atrVal);
                $context['ma_21_plus_1.3atr'] = $ma21 + (1.3 * $atrVal);
                $context['ma_21_plus_1.5atr'] = $ma21 + (1.5 * $atrVal);
                $context['ma_21_plus_2atr'] = $ma21 + (2.0 * $atrVal); // legacy consumers expect this key
            }
        } catch (\Throwable) {
            // best-effort: ces clés restent null/absentes si indisponibles
        }

        $closeForRatio = $context['close'] ?? $price;
        if (
            $closeForRatio !== null &&
            $ma21Value !== null &&
            $atrForMa21 !== null &&
            $atrForMa21 > 0.0
        ) {
            $context['price_vs_ma21_k_atr'] = ($closeForRatio - $ma21Value) / $atrForMa21;
        }
        if ($entryRsiValue !== null) {
            $context['entry_rsi'] = $entryRsiValue;
        }

        if (
            $context['atr_pct_15m_bps'] === null &&
            $price !== null &&
            $price > 0.0 &&
            $atr15m !== null &&
            $atr15m > 0.0
        ) {
            $context['atr_pct_15m_bps'] = 10000.0 * $atr15m / $price;
        }

        if ($context['entry_zone_width_pct'] === null) {
            $entryZoneWidth = $this->estimateEntryZoneWidthPct($symbol, $price ?? $context['close'] ?? null, $atr15m, $tradeEntryConfig);
            if ($entryZoneWidth !== null) {
                $context['entry_zone_width_pct'] = $entryZoneWidth;
            }
        }

        // ADX 1h
        try {
            $list1h = $this->indicatorProvider->getListPivot(symbol: $symbol, tf: '1h');
            if ($list1h !== null) {
                $ind = $list1h->toArray();
                $adx1h = $ind['adx'] ?? null;
                if (is_numeric($adx1h)) {
                    $context['adx_1h'] = (float) $adx1h;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        // ADX 5m for forbid_drop_to_5m_if_any.adx_5m_lt
        try {
            $list5m = $this->indicatorProvider->getListPivot(symbol: $symbol, tf: '5m');
            if ($list5m !== null) {
                $ind5m = $list5m->toArray();
                $adx5m = $ind5m['adx'] ?? null;
                if (is_numeric($adx5m)) {
                    $context['adx_5m'] = (float) $adx5m;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        // Threshold suggestion for ADX 1h filter (less strict intraday)
        $context['adx_1h_min_threshold'] = 14.0;

        // Levier approximatif pour lev_bounds (borné par la config)
        try {
            if ($price !== null && $atr15m !== null && $atr15m > 0.0) {
                $riskPctPercent = (float) ($defaults['risk_pct_percent'] ?? 5.0);
                $riskPct = $riskPctPercent > 1.0 ? $riskPctPercent / 100.0 : $riskPctPercent;
                $atrK = (float) ($defaults['atr_k'] ?? 1.5);
                $stopPct = max(1e-9, $atrK * $atr15m / max($price, 1e-9));
                $levApprox = $riskPct / $stopPct;

                $levCfg = $tradeEntryConfig?->getLeverage() ?? [];
                $floor = (float) ($levCfg['floor'] ?? 1.0);
                $cap = (float) ($levCfg['exchange_cap'] ?? 20.0);
                $context['leverage'] = max($floor, min($cap, (float) $levApprox));
            }
        } catch (\Throwable) {
            // ignore
        }

        // Orderbook-based spread guard (bps)
        if ($context['spread_bps'] === null) {
            $spreadBps = $this->computeSpreadBps($symbol);
            if ($spreadBps !== null) {
                $context['spread_bps'] = $spreadBps;
            }
        }

        // Volume ratio derived from recent klines if available
        if ($context['volume_ratio'] === null) {
            $volumeRatio = $this->computeVolumeRatio($symbol);
            if ($volumeRatio !== null) {
                $context['volume_ratio'] = $volumeRatio;
            }
        }

        return $context;
    }

    private function estimateEntryZoneWidthPct(string $symbol, ?float $referencePrice, ?float $atr15m, ?TradeEntryConfig $tradeEntryConfig = null): ?float
    {
        [$pivotCandidate, $atrFromSnapshot] = $this->fetchEntryZonePivotAndAtr($symbol);
        $pivot = $pivotCandidate ?? $referencePrice;
        if (!\is_finite((float) ($pivot ?? 0)) || $pivot === null || $pivot <= 0.0) {
            return null;
        }

        $atrVal = $atrFromSnapshot ?? $atr15m;
        if ($atrVal !== null && (!\is_finite($atrVal) || $atrVal <= 0.0)) {
            $atrVal = null;
        }

        $postValidation = $tradeEntryConfig?->getPostValidation() ?? [];
        $entryZoneCfg = (array) ($postValidation['entry_zone'] ?? []);
        $kAtr = isset($entryZoneCfg['k_atr']) && \is_numeric($entryZoneCfg['k_atr'])
            ? (float) $entryZoneCfg['k_atr']
            : self::ENTRY_ZONE_DEFAULT_K_ATR;
        $wMin = isset($entryZoneCfg['w_min']) && \is_numeric($entryZoneCfg['w_min'])
            ? (float) $entryZoneCfg['w_min']
            : self::ENTRY_ZONE_DEFAULT_W_MIN;
        $wMax = isset($entryZoneCfg['w_max']) && \is_numeric($entryZoneCfg['w_max'])
            ? (float) $entryZoneCfg['w_max']
            : self::ENTRY_ZONE_DEFAULT_W_MAX;

        $halfFromAtr = $atrVal !== null ? $kAtr * $atrVal : 0.0;
        $minHalf = $pivot * $wMin;
        $maxHalf = $pivot * $wMax;
        $half = max($halfFromAtr, $minHalf);
        $half = min($half, $maxHalf);

        if (!\is_finite($half) || $half <= 0.0) {
            return null;
        }

        return 100.0 * ((2.0 * $half) / $pivot);
    }

    /**
     * @return array{0:?float,1:?float}
     */
    private function fetchEntryZonePivotAndAtr(string $symbol): array
    {
        $pivot = null;
        $atr = null;

        try {
            $snap5m = $this->indicatorProvider->getSnapshot($symbol, '5m');
            if ($snap5m->vwap !== null) {
                $pivot = (float) ((string) $snap5m->vwap);
            }
            if (($pivot === null || $pivot <= 0.0) && $snap5m->ma21 !== null) {
                $pivot = (float) ((string) $snap5m->ma21);
            }
            if (($pivot === null || $pivot <= 0.0) && $snap5m->ma9 !== null) {
                $pivot = (float) ((string) $snap5m->ma9);
            }
            if ($snap5m->atr !== null) {
                $atr = (float) ((string) $snap5m->atr);
            }
        } catch (\Throwable) {
            // ignore
        }

        return [$pivot, $atr];
    }

    private function computeSpreadBps(string $symbol): ?float
    {
        if ($this->mainProvider === null) {
            return null;
        }

        try {
            $top = $this->mainProvider->getOrderProvider()->getOrderBookTop($symbol);
        } catch (\Throwable) {
            return null;
        }

        $bid = null;
        $ask = null;
        if (\is_array($top)) {
            $bid = isset($top['bid']) && \is_numeric($top['bid']) ? (float) $top['bid'] : null;
            $ask = isset($top['ask']) && \is_numeric($top['ask']) ? (float) $top['ask'] : null;
        } elseif (\is_object($top)) {
            if (isset($top->bid) && \is_numeric($top->bid)) {
                $bid = (float) $top->bid;
            }
            if (isset($top->ask) && \is_numeric($top->ask)) {
                $ask = (float) $top->ask;
            }
            if ($bid === null && method_exists($top, 'toArray')) {
                $arr = $top->toArray();
                $bid = isset($arr['bid']) && \is_numeric($arr['bid']) ? (float) $arr['bid'] : $bid;
                $ask = isset($arr['ask']) && \is_numeric($arr['ask']) ? (float) $arr['ask'] : $ask;
            }
        }

        if ($bid === null || $ask === null || $bid <= 0.0 || $ask <= 0.0) {
            return null;
        }

        $mid = 0.5 * ($bid + $ask);
        if ($mid <= 0.0) {
            return null;
        }

        return 10000.0 * ($ask - $bid) / $mid;
    }

    private function computeVolumeRatio(string $symbol): ?float
    {
        if ($this->mainProvider === null) {
            return null;
        }

        try {
            $klines = $this->mainProvider->getKlineProvider()->getKlines($symbol, Timeframe::TF_15M, 25);
        } catch (\Throwable) {
            return null;
        }

        if (empty($klines)) {
            return null;
        }

        $volumes = [];
        foreach ($klines as $kline) {
            $volume = $this->extractKlineVolume($kline);
            if ($volume !== null) {
                $volumes[] = $volume;
            }
        }

        return $this->volumeRatioFromSeries($volumes);
    }

    /**
     * @param float[] $volumes
     */
    private function volumeRatioFromSeries(array $volumes): ?float
    {
        $count = count($volumes);
        if ($count < 3) {
            return null;
        }

        $current = $volumes[$count - 1];
        if ($current <= 0.0) {
            return null;
        }

        $window = min(20, $count - 1);
        if ($window < 1) {
            return null;
        }

        $past = array_slice($volumes, -($window + 1), $window);
        if ($past === [] && $count > 1) {
            $past = array_slice($volumes, 0, -1);
        }

        $past = array_values(array_filter($past, static fn($v) => \is_finite($v) && $v > 0.0));
        if ($past === []) {
            return null;
        }

        $avg = array_sum($past) / count($past);
        if ($avg <= 0.0) {
            return null;
        }

        return $current / $avg;
    }

    private function extractKlineVolume(mixed $kline): ?float
    {
        if (\is_object($kline)) {
            if (method_exists($kline, 'getVolume')) {
                $value = $kline->getVolume();
                if ($value instanceof \Brick\Math\BigDecimal) {
                    return (float) $value->toFloat();
                }
                if (\is_numeric($value)) {
                    return (float) $value;
                }
            }

            if (isset($kline->volume)) {
                $value = $kline->volume;
                if ($value instanceof \Brick\Math\BigDecimal) {
                    return (float) $value->toFloat();
                }
                if (\is_numeric($value)) {
                    return (float) $value;
                }
            }
        } elseif (\is_array($kline) && isset($kline['volume'])) {
            $value = $kline['volume'];
            if ($value instanceof \Brick\Math\BigDecimal) {
                return (float) $value->toFloat();
            }
            if (\is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractMtfContext(SymbolResultDto $symbolResult): array
    {
        $context = $symbolResult->context ?? [];
        $tfs = [];
        if (isset($context['context_tfs']) && \is_array($context['context_tfs'])) {
            $tfs = array_map(
                static fn($tf) => \is_string($tf) ? strtolower($tf) : null,
                $context['context_tfs']
            );
            $tfs = array_values(array_filter($tfs));
        }

        return $tfs;
    }

    private function createSkippedResult(SymbolResultDto $symbolResult, string $reason, ?float $forcedAtr5m = null, ?string $decisionKey = null, ?string $modeUsed = null): SymbolResultDto
    {
        if ($decisionKey !== null) {
            $this->mtfLogger->info('order_journey.trade_request.skipped', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => $reason,
            ]);
        }

        return new SymbolResultDto(
            symbol: $symbolResult->symbol,
            status: $symbolResult->status,
            executionTf: $symbolResult->executionTf,
            signalSide: $symbolResult->signalSide,
            tradingDecision: [
                'status' => 'skipped',
                'reason' => $reason,
            ],
            error: $symbolResult->error,
            context: $symbolResult->context,
            currentPrice: $symbolResult->currentPrice,
            atr: $forcedAtr5m,
            validationModeUsed: $symbolResult->validationModeUsed,
            tradeEntryModeUsed: $modeUsed ?? $symbolResult->tradeEntryModeUsed
        );
    }

    private function logExecution(string $symbol, array $decision): void
    {
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Trade entry submitted', [
                'symbol' => $symbol,
                'status' => $decision['status'] ?? null,
                'client_order_id' => $decision['client_order_id'] ?? null,
                'exchange_order_id' => $decision['exchange_order_id'] ?? null,
            ]);

            $this->mtfLogger->info('order_journey.trade_entry.submitted', [
                'symbol' => $symbol,
                'decision_key' => $decision['decision_key'] ?? null,
                'status' => $decision['status'] ?? null,
                'client_order_id' => $decision['client_order_id'] ?? null,
                'exchange_order_id' => $decision['exchange_order_id'] ?? null,
                'reason' => 'order_sent_to_exchange',
            ]);
        } catch (\Throwable $e) {
            $this->mtfLogger->error('[Trading Decision] Failed to log trade entry', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateDecisionKey(string $symbol): string
    {
        try {
            return sprintf('mtf:%s:%s', $symbol, bin2hex(random_bytes(6)));
        } catch (\Throwable) {
            return uniqid('mtf:' . $symbol . ':', true);
        }
    }
}
