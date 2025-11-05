<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\MtfValidator\Service\Decision\TradingDecisionEvaluation;
use App\MtfValidator\Service\Decision\TradingDecisionService;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\Metrics\RunMetricsAggregator;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Service\TradeEntryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adaptateur entre le pipeline MTF et la logique de décision de trading.
 *
 * Cette classe délègue la construction de la commande de trade à
 * {@see TradingDecisionService} et orchestre la production de métriques via
 * {@see RunMetricsAggregator}.
 */
final class TradingDecisionHandler
{
    public function __construct(
        private readonly TradeEntryService $tradeEntryService,
        private readonly TradingDecisionService $decisionService,
        private readonly RunMetricsAggregator $metricsAggregator,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $positionsFlowLogger,
    ) {
    }

    public function handleTradingDecision(SymbolResultDto $symbolResult, MtfRunDto $mtfRunDto): SymbolResultDto
    {
        if ($symbolResult->isError() || $symbolResult->isSkipped()) {
            return $symbolResult;
        }

        if (!$symbolResult->isReady()) {
            return $symbolResult;
        }

        $decisionKey = $this->decisionService->generateDecisionKey($symbolResult->symbol);
        $this->metricsAggregator->decisionSignalReady($symbolResult, $decisionKey);

        $evaluation = $this->decisionService->evaluate($symbolResult, $decisionKey);

        if ($evaluation->action === TradingDecisionEvaluation::ACTION_NONE) {
            return $evaluation->result;
        }

        if ($evaluation->action === TradingDecisionEvaluation::ACTION_SKIP) {
            if ($evaluation->blockReason !== null) {
                $this->metricsAggregator->decisionPreconditionBlocked($evaluation->result, $decisionKey, $evaluation->blockReason);
            }

            if (($evaluation->extraContext['log_event'] ?? null) === 'unable_to_build_request') {
                $this->metricsAggregator->tradeRequestUnableToBuild($evaluation->result, $decisionKey, $evaluation->extraContext);
            }

            $this->metricsAggregator->tradeRequestSkipped(
                $evaluation->result,
                $decisionKey,
                $evaluation->skipReason ?? 'unknown'
            );

            return $evaluation->result;
        }

        // Toutes les préconditions sont respectées, l'exécution peut commencer.
        $this->metricsAggregator->decisionPreconditionsPassed($evaluation->result, $decisionKey);
        $this->metricsAggregator->tradeRequestBuilt($evaluation->tradeRequest, $decisionKey);

        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Executing trade entry', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
                'side' => $symbolResult->signalSide,
                'decision_key' => $decisionKey,
            ]);

            $this->metricsAggregator->tradeEntryDispatch($symbolResult, $decisionKey, $mtfRunDto->dryRun);

            $execution = $mtfRunDto->dryRun
                ? $this->tradeEntryService->buildAndSimulate($evaluation->tradeRequest, $decisionKey)
                : $this->tradeEntryService->buildAndExecute($evaluation->tradeRequest, $decisionKey);

            $decision = $this->buildTradingDecision($execution, $decisionKey);

            $this->metricsAggregator->tradeEntryResult($symbolResult, $decisionKey, $execution);
            $this->metricsAggregator->tradeEntrySubmitted($symbolResult->symbol, $decisionKey, $execution);
            $this->metricsAggregator->recordAuditTradeEntrySuccess(
                $mtfRunDto->dryRun,
                $symbolResult,
                $evaluation->tradeRequest,
                $execution
            );

            $this->decisionService->applyPostExecutionGuards($symbolResult, $execution, $mtfRunDto->dryRun);

            $this->logPositionsFlowSubmission($symbolResult->symbol, $decision);

            return $this->withTradingDecision($symbolResult, $decision);
        } catch (\Throwable $e) {
            $this->logger->error('[Trading Decision] Trade entry execution failed', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);

            $this->positionsFlowLogger->error('[PositionsFlow] Trade entry failed', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);

            $this->metricsAggregator->tradeEntryFailed($symbolResult, $decisionKey, $e);
            $this->metricsAggregator->recordAuditTradeEntryFailure($symbolResult, $e);

            return $this->withTradingDecision($symbolResult, [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildTradingDecision(ExecutionResult $execution, string $decisionKey): array
    {
        return [
            'status' => $execution->status,
            'client_order_id' => $execution->clientOrderId,
            'exchange_order_id' => $execution->exchangeOrderId,
            'raw' => $execution->raw,
            'decision_key' => $decisionKey,
        ];
    }

    private function withTradingDecision(SymbolResultDto $symbolResult, array $decision): SymbolResultDto
    {
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
    }

    private function logPositionsFlowSubmission(string $symbol, array $decision): void
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

