<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Config\{TradeEntryConfig, MtfValidationConfig};
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
    public function __construct(
        private readonly TradeEntryService $tradeEntryService,
        private readonly TradeEntryRequestBuilder $requestBuilder,
        private readonly \App\MtfValidator\Execution\ExecutionSelector $executionSelector,
        private readonly IndicatorProviderInterface $indicatorProvider,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $mtfLogger,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $positionsFlowLogger,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
        private readonly TradeEntryConfig $tradeEntryConfig,
        private readonly MtfValidationConfig $mtfConfig,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly LifecycleContextFactory $lifecycleContextFactory,
        private readonly ?MainProviderInterface $mainProvider = null,
    ) {}

    public function handleTradingDecision(SymbolResultDto $symbolResult, MtfRunDto $mtfRunDto): SymbolResultDto
    {
        if ($symbolResult->isError() || $symbolResult->isSkipped()) {
            return $symbolResult;
        }

        if (strtoupper($symbolResult->status) !== 'READY') {
            return $symbolResult;
        }

        $decisionKey = $this->generateDecisionKey($symbolResult->symbol);
        // Force ATR to the 5m timeframe so downstream sizing/guards stay consistent across execution TFs.
        $forcedAtr5m = $this->indicatorProvider->getAtr(symbol: $symbolResult->symbol, tf: '5m');

        $this->mtfLogger->info('order_journey.signal_ready', [
            'symbol' => $symbolResult->symbol,
            'execution_tf' => $symbolResult->executionTf,
            'side' => $symbolResult->signalSide,
            'decision_key' => $decisionKey,
            'reason' => 'mtf_signal_ready',
        ]);

        // 1. Validation MTF spécifique
        if (!$this->canExecuteMtfTrading($symbolResult, $mtfRunDto, $forcedAtr5m, $decisionKey, null)) {
            return $this->createSkippedResult($symbolResult, 'trading_conditions_not_met', $forcedAtr5m, $decisionKey);
        }

        // 2. Sélecteur d'exécution (15m/5m/1m) basé sur execution_selector
        $selectorContext = $this->buildSelectorContext($symbolResult);
        $execDecision = $this->executionSelector->decide($selectorContext);
        $effectiveTf = $execDecision->executionTimeframe !== 'NONE' ? $execDecision->executionTimeframe : ($symbolResult->executionTf ?? '1m');
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

        // Extraire la configuration execution_selector du YAML pour le log
        $cfg = $this->mtfConfig->getConfig();
        $execSelectorConfig = (array)($cfg['execution_selector'] ?? []);
        $execSelectorForLog = [];
        if (isset($execSelectorConfig['stay_on_15m_if'])) {
            $execSelectorForLog['stay_on_15m_if'] = $execSelectorConfig['stay_on_15m_if'];
        }
        if (isset($execSelectorConfig['drop_to_5m_if_any'])) {
            $execSelectorForLog['drop_to_5m_if_any'] = $execSelectorConfig['drop_to_5m_if_any'];
        }

        $this->mtfLogger->info('[ExecutionSelector] decision='.$effectiveTf, [
            'symbol' => $symbolResult->symbol,
            'decision_key' => $decisionKey,
            'execution_tf' => $effectiveTf,
            'expected_r_multiple' => $execDecision->expectedRMultiple,
            'entry_zone_width_pct' => $execDecision->entryZoneWidthPct,
            'atr_pct_15m_bps' => $selectorContext['atr_pct_15m_bps'] ?? null,
            'execution_selector_config' => $execSelectorForLog,
        ] + ['meta' => $execDecision->meta]);

        $lifecycleContext = $this->lifecycleContextFactory->create($symbolResult->symbol)
            ->withDecisionKey($decisionKey)
            ->withProfile($symbolResult->tradeEntryModeUsed)
            ->withMtfContext($effectiveTf, $this->extractMtfContext($symbolResult), $symbolResult->blockingTf)
            ->withSelectorDecision($execDecision->expectedRMultiple, $execDecision->entryZoneWidthPct)
            ->merge(['config_version' => $this->tradeEntryConfig->getVersion()]);

        // 3. Construction via Builder (délégation) avec champs minimaux
        $tradeRequest = $this->requestBuilder->fromMtfSignal(
            $symbolResult->symbol,
            (string)$symbolResult->signalSide,
            $effectiveTf,
            $symbolResult->currentPrice,
            (\is_float($atrForTf) && $atrForTf > 0.0) ? $atrForTf : $forcedAtr5m,
            $symbolResult->tradeEntryModeUsed, // Passer le mode (même mécanisme que validations.{mode}.yaml)
        );

        if ($tradeRequest === null) {
            $this->mtfLogger->warning('order_journey.trade_request.unable_to_build', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'builder_returned_null',
            ]);
            return $this->createSkippedResult($symbolResult, 'unable_to_build_request', $forcedAtr5m, $decisionKey);
        }

        $this->mtfLogger->info('order_journey.trade_request.built', [
            'symbol' => $tradeRequest->symbol,
            'decision_key' => $decisionKey,
            'order_type' => $tradeRequest->orderType,
            'order_mode' => $tradeRequest->orderMode,
            'risk_pct' => $tradeRequest->riskPct,
            'initial_margin_usdt' => $tradeRequest->initialMarginUsdt,
            'stop_from' => $tradeRequest->stopFrom,
            'validation_mode_used' => $symbolResult->validationModeUsed,
            'trade_entry_mode_used' => $symbolResult->tradeEntryModeUsed,
            'reason' => 'mtf_defaults_applied',
        ]);

        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Executing trade entry', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
                'side' => $symbolResult->signalSide,
                'decision_key' => $decisionKey,
                'validation_mode_used' => $symbolResult->validationModeUsed,
                'trade_entry_mode_used' => $symbolResult->tradeEntryModeUsed,
            ]);

            $this->mtfLogger->info('order_journey.trade_entry.dispatch', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'dry_run' => $mtfRunDto->dryRun,
                'validation_mode_used' => $symbolResult->validationModeUsed,
                'trade_entry_mode_used' => $symbolResult->tradeEntryModeUsed,
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
                ? $this->tradeEntryService->buildAndSimulate($tradeRequest, $decisionKey, $hook, $symbolResult->tradeEntryModeUsed, $lifecycleContext)
                : $this->tradeEntryService->buildAndExecute($tradeRequest, $decisionKey, $hook, $symbolResult->tradeEntryModeUsed, $lifecycleContext);

            $decision = [
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'raw' => $execution->raw,
                'decision_key' => $decisionKey,
            ];

            $this->mtfLogger->info('order_journey.trade_entry.result', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'validation_mode_used' => $symbolResult->validationModeUsed,
                'trade_entry_mode_used' => $symbolResult->tradeEntryModeUsed,
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
                tradeEntryModeUsed: $symbolResult->tradeEntryModeUsed
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
                tradeEntryModeUsed: $symbolResult->tradeEntryModeUsed
            );
        }
    }

    /**
     * Validation MTF spécifique (garde dans TradingDecisionHandler).
     * Les validations génériques sont déléguées à TradeEntryService.
     */
    private function canExecuteMtfTrading(SymbolResultDto $symbolResult, MtfRunDto $mtfRunDto, ?float $forcedAtr5m = null, ?string $decisionKey = null, ?string $executionTfOverride = null): bool
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
        $decision = $this->tradeEntryConfig->getDecision();
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
            // Mode normal : utiliser allowed_execution_timeframes depuis TradeEntryConfig
            $allowedTfs = (array)($decision['allowed_execution_timeframes'] ?? ['1m','5m','15m']);
            if (!in_array($effectiveTf, array_map('strtolower', $allowedTfs), true)) {
                $this->mtfLogger->info('[Trading Decision] Skipping (unsupported execution TF)', [
                    'symbol' => $symbolResult->symbol,
                    'execution_tf' => $effectiveTf,
                    'allowed_tfs' => $allowedTfs,
                ]);
                $this->mtfLogger->info('order_journey.preconditions.blocked', [
                    'symbol' => $symbolResult->symbol,
                    'decision_key' => $decisionKey,
                    'reason' => 'unsupported_execution_tf',
                    'execution_tf' => $symbolResult->executionTf,
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
    private function buildSelectorContext(SymbolResultDto $symbolResult): array
    {
        $symbol = $symbolResult->symbol;
        $price = $symbolResult->currentPrice ?? null;
        $atr15m = null;
        try { $atr15m = $this->indicatorProvider->getAtr(symbol: $symbol, tf: '15m'); } catch (\Throwable) {}
        $atrPct15mBps = ($atr15m !== null && $price !== null && $price > 0.0)
            ? (10000.0 * $atr15m / $price) : null;

        // Valeur R multiple attendue (fallback au défaut de config)
        $defaults = $this->tradeEntryConfig->getDefaults();
        $expectedR = isset($defaults['r_multiple']) ? (float)$defaults['r_multiple'] : 2.0;

        // Contexte de base consommé par l'ExecutionSelector
        $context = [
            'expected_r_multiple' => $expectedR,
            'entry_zone_width_pct' => null, // à enrichir via EntryZoneCalculator si souhaité
            'atr_pct_15m_bps' => $atrPct15mBps,
            'adx_5m' => null,
            'spread_bps' => null,
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
        try {
            $snap = $this->indicatorProvider->getSnapshot($symbol, '15m');
            // rsi
            if (isset($snap->rsi) && is_float($snap->rsi)) {
                $context['rsi'] = $snap->rsi;
            }
            // vwap
            if ($snap->vwap !== null) {
                $context['vwap'] = (float) ((string) $snap->vwap);
            }
            // ma9 / ma21
            $ma = [];
            if ($snap->ma9 !== null) { $ma[9] = (float) ((string) $snap->ma9); }
            if ($snap->ma21 !== null) { $ma[21] = (float) ((string) $snap->ma21); }
            if ($ma !== []) { $context['ma'] = $ma; }

            // ma_21_plus_k_atr pour price_lte_ma21_plus_k_atr
            $atrK = (float) ($defaults['atr_k'] ?? 1.5);
            $atrVal = $snap->atr !== null ? (float) ((string) $snap->atr) : ($atr15m ?? null);
            $ma21 = $snap->ma21 !== null ? (float) ((string) $snap->ma21) : null;
            if ($ma21 !== null && $atrVal !== null) {
                $context['ma_21_plus_k_atr'] = $ma21 + ($atrK * $atrVal);
            }
        } catch (\Throwable) {
            // best-effort: ces clés restent null/absentes si indisponibles
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

                $levCfg = $this->tradeEntryConfig->getLeverage();
                $floor = (float) ($levCfg['floor'] ?? 1.0);
                $cap = (float) ($levCfg['exchange_cap'] ?? 20.0);
                $context['leverage'] = max($floor, min($cap, (float) $levApprox));
            }
        } catch (\Throwable) {
            // ignore
        }

        return $context;
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

    private function createSkippedResult(SymbolResultDto $symbolResult, string $reason, ?float $forcedAtr5m = null, ?string $decisionKey = null): SymbolResultDto
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
            tradeEntryModeUsed: $symbolResult->tradeEntryModeUsed
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
