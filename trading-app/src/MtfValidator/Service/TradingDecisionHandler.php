<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Config\{TradingDecisionConfig, MtfValidationConfig};
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\Repository\MtfSwitchRepository;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Service\TradeEntryService;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gestionnaire des décisions de trading qui délègue à TradeEntryService.
 */
final class TradingDecisionHandler
{
    public function __construct(
        private readonly TradeEntryService $tradeEntryService,
        private readonly AuditLoggerInterface $auditLogger,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $positionsFlowLogger,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $orderJourneyLogger,
        private readonly TradingDecisionConfig $decisionConfig,
        private readonly MtfValidationConfig $mtfConfig,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
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

        $this->orderJourneyLogger->info('order_journey.signal_ready', [
            'symbol' => $symbolResult->symbol,
            'execution_tf' => $symbolResult->executionTf,
            'side' => $symbolResult->signalSide,
            'decision_key' => $decisionKey,
            'reason' => 'mtf_signal_ready',
        ]);

        if (!$this->canExecuteTrading($symbolResult, $decisionKey)) {
            return $this->createSkippedResult($symbolResult, 'trading_conditions_not_met', $decisionKey);
        }

        $tradeRequest = $this->buildTradeEntryRequest(
            $symbolResult,
            $symbolResult->currentPrice,
            $symbolResult->atr
        );

        if ($tradeRequest === null) {
            $this->orderJourneyLogger->warning('order_journey.trade_request.unable_to_build', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'builder_returned_null',
            ]);
            return $this->createSkippedResult($symbolResult, 'unable_to_build_request', $decisionKey);
        }

        $this->orderJourneyLogger->info('order_journey.trade_request.built', [
            'symbol' => $tradeRequest->symbol,
            'decision_key' => $decisionKey,
            'order_type' => $tradeRequest->orderType,
            'order_mode' => $tradeRequest->orderMode,
            'risk_pct' => $tradeRequest->riskPct,
            'initial_margin_usdt' => $tradeRequest->initialMarginUsdt,
            'stop_from' => $tradeRequest->stopFrom,
            'reason' => 'mtf_defaults_applied',
        ]);

        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Executing trade entry', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
                'side' => $symbolResult->signalSide,
                'decision_key' => $decisionKey,
            ]);

            $this->orderJourneyLogger->info('order_journey.trade_entry.dispatch', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'dry_run' => $mtfRunDto->dryRun,
                'reason' => $mtfRunDto->dryRun ? 'dry_run_simulation' : 'live_execution',
            ]);

            $execution = $mtfRunDto->dryRun
                ? $this->tradeEntryService->buildAndSimulate($tradeRequest, $decisionKey)
                : $this->tradeEntryService->buildAndExecute($tradeRequest, $decisionKey);

            $decision = [
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'raw' => $execution->raw,
                'decision_key' => $decisionKey,
            ];

            $this->orderJourneyLogger->info('order_journey.trade_entry.result', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'reason' => 'trade_entry_service_completed',
            ]);

            // Disable symbol after a real order submission to avoid immediate re-entries via MTF
            // Note: dry-run does not toggle switches.
            if (!$mtfRunDto->dryRun && ($execution->status === 'submitted')) {
                try {
                    $this->mtfSwitchRepository->turnOffSymbolFor15Minutes($symbolResult->symbol);
                    $this->logger->info('[Trading Decision] Symbol switched OFF for 4 hours after order', [
                        'symbol' => $symbolResult->symbol,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('[Trading Decision] Failed to switch OFF symbol', [
                        'symbol' => $symbolResult->symbol,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logExecution($symbolResult->symbol, $decision);

            $this->auditLogger->logAction(
                $mtfRunDto->dryRun ? 'TRADE_ENTRY_SIMULATED' : 'TRADE_ENTRY_EXECUTED',
                'TRADE_ENTRY',
                $symbolResult->symbol,
                [
                    'status' => $execution->status,
                    'client_order_id' => $execution->clientOrderId,
                    'exchange_order_id' => $execution->exchangeOrderId,
                    'execution_tf' => $symbolResult->executionTf,
                    'order_type' => $tradeRequest->orderType,
                ]
            );

            return new SymbolResultDto(
                symbol: $symbolResult->symbol,
                status: $symbolResult->status,
                executionTf: $symbolResult->executionTf,
                signalSide: $symbolResult->signalSide,
                tradingDecision: $decision,
                error: $symbolResult->error,
                context: $symbolResult->context,
                currentPrice: $symbolResult->currentPrice,
                atr: $symbolResult->atr
            );
        } catch (\Throwable $e) {
            $this->logger->error('[Trading Decision] Trade entry execution failed', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);

            $this->positionsFlowLogger->error('[PositionsFlow] Trade entry failed', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);

            $this->orderJourneyLogger->error('order_journey.trade_entry.failed', [
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
                executionTf: $symbolResult->executionTf,
                signalSide: $symbolResult->signalSide,
                tradingDecision: [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ],
                error: $symbolResult->error,
                context: $symbolResult->context,
                currentPrice: $symbolResult->currentPrice,
                atr: $symbolResult->atr
            );
        }
    }

    private function canExecuteTrading(SymbolResultDto $symbolResult, ?string $decisionKey = null): bool
    {
        if ($symbolResult->executionTf === null) {
            $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'missing_execution_tf',
            ]);
            return false;
        }

        $allowedTfs = (array)($this->decisionConfig->get('allowed_execution_timeframes', $this->mtfConfig->getDefault('allowed_execution_timeframes', ['1m','5m','15m'])));
        if (!in_array(strtolower($symbolResult->executionTf), array_map('strtolower', $allowedTfs), true)) {
            $this->logger->info('[Trading Decision] Skipping (unsupported execution TF)', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
            ]);
            $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'unsupported_execution_tf',
                'execution_tf' => $symbolResult->executionTf,
            ]);
            return false;
        }

        if ($symbolResult->signalSide === null) {
            $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'missing_signal_side',
            ]);
            return false;
        }

        $requirePriceOrAtr = (bool)($this->decisionConfig->get('require_price_or_atr', $this->mtfConfig->getDefault('require_price_or_atr', true)));
        if ($requirePriceOrAtr && $symbolResult->currentPrice === null && $symbolResult->atr === null) {
            $this->logger->debug('[Trading Decision] Missing price and ATR', [
                'symbol' => $symbolResult->symbol,
            ]);
            $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'missing_price_and_atr',
            ]);
            return false;
        }

        $this->orderJourneyLogger->debug('order_journey.preconditions.passed', [
            'symbol' => $symbolResult->symbol,
            'decision_key' => $decisionKey,
            'execution_tf' => $symbolResult->executionTf,
            'signal_side' => $symbolResult->signalSide,
        ]);

        return true;
    }

    private function resolveTradingPrice(SymbolResultDto $symbolResult): ?object
    {
        $side = strtoupper((string)$symbolResult->signalSide);
        if (!in_array($side, ['LONG', 'SHORT'], true)) {
            return null;
        }

        try {
            return $this->tradingPriceResolver->resolve(
                $symbolResult->symbol,
                SignalSide::from(strtoupper($symbolResult->signalSide)),
                $symbolResult->currentPrice,
                $symbolResult->atr
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Trading Decision] Unable to resolve trading price', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildTradeEntryRequest(SymbolResultDto $symbolResult, ?float $price, ?float $atr): ?TradeEntryRequest
    {
        $side = strtoupper((string)$symbolResult->signalSide);
        if (!in_array($side, ['LONG', 'SHORT'], true)) {
            return null;
        }

        $executionTf = strtolower($symbolResult->executionTf ?? '1m');
        $defaults = $this->mtfConfig->getDefaults();
        $multipliers = $defaults['timeframe_multipliers'] ?? [];
        $tfMultiplier = (float)($multipliers[$executionTf] ?? 1.0);

        $riskPctPercent = (float)($defaults['risk_pct_percent'] ?? 2.0);
        $riskPct = max(0.0, $riskPctPercent / 100.0) * $tfMultiplier;
        if ($riskPct <= 0.0) {
            return null;
        }

        $initialMargin = max(0.0, (float)($defaults['initial_margin_usdt'] ?? 100.0) * $tfMultiplier);
        if ($initialMargin <= 0.0) {
            $fallbackCapital = (float)($defaults['fallback_account_balance'] ?? 0.0);
            $initialMargin = $fallbackCapital * $riskPct;
        }

        if ($initialMargin <= 0.0) {
            return null;
        }

        $stopFrom = $defaults['stop_from'] ?? 'risk';
        $atrK = (float)($defaults['atr_k'] ?? 1.5);
        $atrValue = ($stopFrom === 'atr' && $atr !== null && $atr > 0.0) ? $atr : null;
        if ($atrValue === null && $stopFrom === 'atr') {
            $stopFrom = 'risk';
        }

        $orderType = $defaults['order_type'] ?? 'limit';
        // entryLimitHint est optionnel; si null, OrderPlanBuilder utilisera best bid/ask
        $entryLimitHint = ($orderType === 'limit' && $price !== null) ? $price : null;

        $marketMaxSpreadPct = (float)($defaults['market_max_spread_pct'] ?? 0.001);
        if ($marketMaxSpreadPct > 1.0) {
            $marketMaxSpreadPct /= 100.0;
        }

        $insideTicks = (int)($defaults['inside_ticks'] ?? 1);
        $maxDeviationPct = isset($defaults['max_deviation_pct']) ? (float)$defaults['max_deviation_pct'] : null;
        $implausiblePct = isset($defaults['implausible_pct']) ? (float)$defaults['implausible_pct'] : null;
        $zoneMaxDeviationPct = isset($defaults['zone_max_deviation_pct']) ? (float)$defaults['zone_max_deviation_pct'] : null;

        $tpPolicy = (string)($defaults['tp_policy'] ?? 'pivot_conservative');
        $tpBufferPct = isset($defaults['tp_buffer_pct']) ? (float)$defaults['tp_buffer_pct'] : null;
        if ($tpBufferPct !== null && $tpBufferPct <= 0.0) {
            $tpBufferPct = null;
        }
        $tpBufferTicks = isset($defaults['tp_buffer_ticks']) ? (int)$defaults['tp_buffer_ticks'] : null;
        if ($tpBufferTicks !== null && $tpBufferTicks <= 0) {
            $tpBufferTicks = null;
        }
        $tpMinKeepRatio = (float)($defaults['tp_min_keep_ratio'] ?? 0.95);
        $tpMaxExtraR = isset($defaults['tp_max_extra_r']) ? (float)$defaults['tp_max_extra_r'] : null;
        if ($tpMaxExtraR !== null && $tpMaxExtraR < 0.0) {
            $tpMaxExtraR = null;
        }

        $sideEnum = $side === 'LONG' ? Side::Long : Side::Short;

        return new TradeEntryRequest(
            symbol: $symbolResult->symbol,
            side: $sideEnum,
            orderType: $orderType,
            openType: $defaults['open_type'] ?? 'isolated',
            orderMode: (int)($defaults['order_mode'] ?? 1),
            initialMarginUsdt: $initialMargin,
            riskPct: $riskPct,
            rMultiple: (float)($defaults['r_multiple'] ?? 2.0),
            entryLimitHint: $entryLimitHint,
            stopFrom: $stopFrom,
            atrValue: $atrValue,
            atrK: (float)$atrK,
            marketMaxSpreadPct: $marketMaxSpreadPct,
            insideTicks: $insideTicks,
            maxDeviationPct: $maxDeviationPct,
            implausiblePct: $implausiblePct,
            zoneMaxDeviationPct: $zoneMaxDeviationPct,
            tpPolicy: $tpPolicy,
            tpBufferPct: $tpBufferPct,
            tpBufferTicks: $tpBufferTicks,
            tpMinKeepRatio: $tpMinKeepRatio,
            tpMaxExtraR: $tpMaxExtraR,
        );
    }

    private function createSkippedResult(SymbolResultDto $symbolResult, string $reason, ?string $decisionKey = null): SymbolResultDto
    {
        if ($decisionKey !== null) {
            $this->orderJourneyLogger->info('order_journey.trade_request.skipped', [
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
            atr: $symbolResult->atr
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

            $this->orderJourneyLogger->info('order_journey.trade_entry.submitted', [
                'symbol' => $symbol,
                'decision_key' => $decision['decision_key'] ?? null,
                'status' => $decision['status'] ?? null,
                'client_order_id' => $decision['client_order_id'] ?? null,
                'exchange_order_id' => $decision['exchange_order_id'] ?? null,
                'reason' => 'order_sent_to_exchange',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[Trading Decision] Failed to log trade entry', [
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
