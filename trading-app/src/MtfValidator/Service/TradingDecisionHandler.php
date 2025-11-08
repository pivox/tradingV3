<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Config\{TradeEntryConfig, MtfValidationConfig};
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\TradeEntry\Builder\TradeEntryRequestBuilder;
use App\TradeEntry\Hook\MtfPostExecutionHook;
use App\TradeEntry\Service\TradeEntryService;
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
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $positionsFlowLogger,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $orderJourneyLogger,
        private readonly TradeEntryConfig $tradeEntryConfig,
        private readonly MtfValidationConfig $mtfConfig,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly AuditLoggerInterface $auditLogger,
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

        // 1. Validation MTF spécifique
        if (!$this->canExecuteMtfTrading($symbolResult, $mtfRunDto, $decisionKey)) {
            return $this->createSkippedResult($symbolResult, 'trading_conditions_not_met', $decisionKey);
        }

        // 2. Construction via Builder (délégation)
        $tradeRequest = $this->requestBuilder->fromMtfSignal(
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

            // 3. Exécution avec hook (délégation)
            $hook = new MtfPostExecutionHook(
                $this->mtfSwitchRepository,
                $this->auditLogger,
                $mtfRunDto->dryRun,
                $this->logger,
                $this->orderJourneyLogger,
            );

            $execution = $mtfRunDto->dryRun
                ? $this->tradeEntryService->buildAndSimulate($tradeRequest, $decisionKey, $hook)
                : $this->tradeEntryService->buildAndExecute($tradeRequest, $decisionKey, $hook);

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

            $this->logExecution($symbolResult->symbol, $decision);

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

    /**
     * Validation MTF spécifique (garde dans TradingDecisionHandler).
     * Les validations génériques sont déléguées à TradeEntryService.
     */
    private function canExecuteMtfTrading(SymbolResultDto $symbolResult, MtfRunDto $mtfRunDto, ?string $decisionKey = null): bool
    {
        if ($symbolResult->executionTf === null) {
            $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
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

        if ($mtfRunDto->dryRun && $dryRunValidateAllTfs) {
            // En dry-run avec flag activé, accepter tous les timeframes
            if (!in_array(strtolower($symbolResult->executionTf), array_map('strtolower', $allTimeframes), true)) {
                $this->logger->info('[Trading Decision] Skipping (unsupported execution TF even in dry-run)', [
                    'symbol' => $symbolResult->symbol,
                    'execution_tf' => $symbolResult->executionTf,
                    'dry_run' => true,
                ]);
                $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
                    'symbol' => $symbolResult->symbol,
                    'decision_key' => $decisionKey,
                    'reason' => 'unsupported_execution_tf',
                    'execution_tf' => $symbolResult->executionTf,
                    'dry_run' => true,
                ]);
                return false;
            }
            // Log que tous les TF sont acceptés en dry-run
            $this->logger->debug('[Trading Decision] Dry-run mode: accepting all timeframes', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
            ]);
        } else {
            // Mode normal : utiliser allowed_execution_timeframes depuis TradeEntryConfig
            $allowedTfs = (array)($decision['allowed_execution_timeframes'] ?? ['1m','5m','15m']);
            if (!in_array(strtolower($symbolResult->executionTf), array_map('strtolower', $allowedTfs), true)) {
                $this->logger->info('[Trading Decision] Skipping (unsupported execution TF)', [
                    'symbol' => $symbolResult->symbol,
                    'execution_tf' => $symbolResult->executionTf,
                    'allowed_tfs' => $allowedTfs,
                ]);
                $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
                    'symbol' => $symbolResult->symbol,
                    'decision_key' => $decisionKey,
                    'reason' => 'unsupported_execution_tf',
                    'execution_tf' => $symbolResult->executionTf,
                ]);
                return false;
            }
        }

        if ($symbolResult->signalSide === null) {
            $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbolResult->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'missing_signal_side',
            ]);
            return false;
        }

        if ($symbolResult->currentPrice === null && $symbolResult->atr === null) {
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
