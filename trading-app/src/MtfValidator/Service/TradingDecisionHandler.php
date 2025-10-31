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

        if (!$this->canExecuteTrading($symbolResult)) {
            return $this->createSkippedResult($symbolResult, 'trading_conditions_not_met');
        }

        $tradeRequest = $this->buildTradeEntryRequest(
            $symbolResult,
            $symbolResult->currentPrice,
            $symbolResult->atr
        );

        if ($tradeRequest === null) {
            return $this->createSkippedResult($symbolResult, 'unable_to_build_request');
        }

        try {
            // Correlate logs across MTF + TradeEntry
            try { $decisionKey = sprintf('mtf:%s:%s', $symbolResult->symbol, bin2hex(random_bytes(6))); }
            catch (\Throwable) { $decisionKey = uniqid('mtf:' . $symbolResult->symbol . ':', true); }

            $this->positionsFlowLogger->info('[PositionsFlow] Executing trade entry', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
                'side' => $symbolResult->signalSide,
                'decision_key' => $decisionKey,
            ]);

            $execution = $mtfRunDto->dryRun
                ? $this->tradeEntryService->buildAndSimulate($tradeRequest, $decisionKey)
                : $this->tradeEntryService->buildAndExecute($tradeRequest, $decisionKey);

            $decision = [
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'raw' => $execution->raw,
            ];

            // Disable symbol after a real order submission to avoid immediate re-entries via MTF
            // Note: dry-run does not toggle switches.
            if (!$mtfRunDto->dryRun && ($execution->status === 'submitted')) {
                try {
                    $this->mtfSwitchRepository->turnOffSymbolFor4Hours($symbolResult->symbol);
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

    private function canExecuteTrading(SymbolResultDto $symbolResult): bool
    {
        if ($symbolResult->executionTf === null) {
            return false;
        }

        $allowedTfs = (array)($this->decisionConfig->get('allowed_execution_timeframes', $this->mtfConfig->getDefault('allowed_execution_timeframes', ['1m','5m','15m'])));
        if (!in_array(strtolower($symbolResult->executionTf), array_map('strtolower', $allowedTfs), true)) {
            $this->logger->info('[Trading Decision] Skipping (unsupported execution TF)', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
            ]);
            return false;
        }

        if ($symbolResult->signalSide === null) {
            return false;
        }

        $requirePriceOrAtr = (bool)($this->decisionConfig->get('require_price_or_atr', $this->mtfConfig->getDefault('require_price_or_atr', true)));
        if ($requirePriceOrAtr && $symbolResult->currentPrice === null && $symbolResult->atr === null) {
            $this->logger->debug('[Trading Decision] Missing price and ATR', [
                'symbol' => $symbolResult->symbol,
            ]);
            return false;
        }

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

        $sideEnum = $side === 'LONG' ? Side::Long : Side::Short;

        return new TradeEntryRequest(
            symbol: $symbolResult->symbol,
            side: $sideEnum,
            orderType: $orderType,
            openType: $defaults['open_type'] ?? 'isolated',
            orderMode: (int)($defaults['order_mode'] ?? 4),
            initialMarginUsdt: $initialMargin,
            riskPct: $riskPct,
            rMultiple: (float)($defaults['r_multiple'] ?? 2.0),
            entryLimitHint: $entryLimitHint,
            stopFrom: $stopFrom,
            atrValue: $atrValue,
            atrK: (float)$atrK,
            marketMaxSpreadPct: $marketMaxSpreadPct
        );
    }

    private function createSkippedResult(SymbolResultDto $symbolResult, string $reason): SymbolResultDto
    {
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
        } catch (\Throwable $e) {
            $this->logger->error('[Trading Decision] Failed to log trade entry', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
