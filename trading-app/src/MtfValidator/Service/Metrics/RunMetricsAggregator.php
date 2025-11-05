<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Metrics;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Service\Dto\Internal\InternalRunSummaryDto;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\TradeEntryRequest;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RunMetricsAggregator
{
    private ?string $currentRunId = null;
    private ?MtfRunDto $runContext = null;
    private int $requestedSymbols = 0;
    private int $processedSymbols = 0;
    private int $successCount = 0;
    private int $failedCount = 0;
    private int $skippedCount = 0;
    private int $contractsProcessed = 0;
    private ?string $lastSuccessfulTimeframe = null;
    /** @var array<string, array> */
    private array $results = [];

    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $orderJourneyLogger,
    ) {
    }

    public function startRun(UuidInterface $runId, MtfRunDto $dto): void
    {
        $this->reset();
        $this->currentRunId = $runId->toString();
        $this->runContext = $dto;
        $this->requestedSymbols = count($dto->symbols);

        $this->orderJourneyLogger->info('order_journey.orchestrator.run_start', [
            'run_id' => $this->currentRunId,
            'symbols_count' => $this->requestedSymbols,
            'dry_run' => $dto->dryRun,
            'reason' => 'mtf_cycle_begin',
        ]);
    }

    public function runBlocked(string $reason, array $context = []): void
    {
        $payload = array_merge([
            'run_id' => $this->currentRunId,
            'reason' => $reason,
        ], $context);

        if ($reason === 'lock_acquisition_failed') {
            $this->orderJourneyLogger->warning('order_journey.orchestrator.run_blocked', $payload);
        } else {
            $this->orderJourneyLogger->info('order_journey.orchestrator.run_blocked', $payload);
        }
    }

    public function symbolStarted(string $symbol, int $position, int $total): void
    {
        $this->orderJourneyLogger->info('order_journey.orchestrator.symbol_start', [
            'run_id' => $this->currentRunId,
            'symbol' => $symbol,
            'position' => $position,
            'total' => $total,
            'reason' => 'symbol_cycle_begin',
        ]);
    }

    public function symbolMtfResult(SymbolResultDto $result): void
    {
        $this->orderJourneyLogger->info('order_journey.orchestrator.symbol_mtf_result', [
            'run_id' => $this->currentRunId,
            'symbol' => $result->symbol,
            'status' => $result->status,
            'execution_tf' => $result->executionTf,
            'signal_side' => $result->signalSide,
            'reason' => 'mtf_processing_completed',
        ]);
    }

    public function decisionLifecycleStart(SymbolResultDto $result): void
    {
        $this->orderJourneyLogger->info('order_journey.orchestrator.symbol_decision_start', [
            'run_id' => $this->currentRunId,
            'symbol' => $result->symbol,
            'status' => $result->status,
            'reason' => 'invoke_trading_decision',
        ]);
    }

    public function decisionLifecycleEnd(SymbolResultDto $result): void
    {
        $this->orderJourneyLogger->info('order_journey.orchestrator.symbol_decision_done', [
            'run_id' => $this->currentRunId,
            'symbol' => $result->symbol,
            'status' => $result->status,
            'reason' => 'trading_decision_completed',
        ]);
    }

    public function decisionSignalReady(SymbolResultDto $result, string $decisionKey): void
    {
        $this->orderJourneyLogger->info('order_journey.signal_ready', [
            'symbol' => $result->symbol,
            'execution_tf' => $result->executionTf,
            'side' => $result->signalSide,
            'decision_key' => $decisionKey,
            'reason' => 'mtf_signal_ready',
        ]);
    }

    public function decisionPreconditionBlocked(SymbolResultDto $result, string $decisionKey, string $reason): void
    {
        $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
            'symbol' => $result->symbol,
            'decision_key' => $decisionKey,
            'reason' => $reason,
        ]);
    }

    public function decisionPreconditionsPassed(SymbolResultDto $result, string $decisionKey): void
    {
        $this->orderJourneyLogger->debug('order_journey.preconditions.passed', [
            'symbol' => $result->symbol,
            'decision_key' => $decisionKey,
            'execution_tf' => $result->executionTf,
            'signal_side' => $result->signalSide,
        ]);
    }

    public function tradeRequestUnableToBuild(SymbolResultDto $result, string $decisionKey, array $context = []): void
    {
        $this->orderJourneyLogger->warning('order_journey.trade_request.unable_to_build', array_merge([
            'symbol' => $result->symbol,
            'decision_key' => $decisionKey,
            'reason' => 'builder_returned_null',
        ], $context));
    }

    public function tradeRequestSkipped(SymbolResultDto $result, string $decisionKey, string $reason): void
    {
        $this->orderJourneyLogger->info('order_journey.trade_request.skipped', [
            'symbol' => $result->symbol,
            'decision_key' => $decisionKey,
            'reason' => $reason,
        ]);
    }

    public function tradeRequestBuilt(TradeEntryRequest $request, string $decisionKey): void
    {
        $this->orderJourneyLogger->info('order_journey.trade_request.built', [
            'symbol' => $request->symbol,
            'decision_key' => $decisionKey,
            'order_type' => $request->orderType,
            'order_mode' => $request->orderMode,
            'risk_pct' => $request->riskPct,
            'initial_margin_usdt' => $request->initialMarginUsdt,
            'stop_from' => $request->stopFrom,
            'reason' => 'mtf_defaults_applied',
        ]);
    }

    public function tradeEntryDispatch(SymbolResultDto $result, string $decisionKey, bool $dryRun): void
    {
        $this->orderJourneyLogger->info('order_journey.trade_entry.dispatch', [
            'symbol' => $result->symbol,
            'decision_key' => $decisionKey,
            'dry_run' => $dryRun,
            'reason' => $dryRun ? 'dry_run_simulation' : 'live_execution',
        ]);
    }

    public function tradeEntryResult(SymbolResultDto $result, string $decisionKey, ExecutionResult $execution): void
    {
        $this->orderJourneyLogger->info('order_journey.trade_entry.result', [
            'symbol' => $result->symbol,
            'decision_key' => $decisionKey,
            'status' => $execution->status,
            'client_order_id' => $execution->clientOrderId,
            'exchange_order_id' => $execution->exchangeOrderId,
            'reason' => 'trade_entry_service_completed',
        ]);
    }

    public function tradeEntrySubmitted(string $symbol, string $decisionKey, ExecutionResult $execution): void
    {
        $this->orderJourneyLogger->info('order_journey.trade_entry.submitted', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'status' => $execution->status,
            'client_order_id' => $execution->clientOrderId,
            'exchange_order_id' => $execution->exchangeOrderId,
            'reason' => 'order_sent_to_exchange',
        ]);
    }

    public function tradeEntryFailed(SymbolResultDto $result, string $decisionKey, \Throwable $exception): void
    {
        $this->orderJourneyLogger->error('order_journey.trade_entry.failed', [
            'symbol' => $result->symbol,
            'decision_key' => $decisionKey,
            'reason' => 'exception_during_trade_entry',
            'error' => $exception->getMessage(),
        ]);
    }

    public function recordAuditTradeEntrySuccess(
        bool $dryRun,
        SymbolResultDto $result,
        TradeEntryRequest $request,
        ExecutionResult $execution
    ): void {
        $this->auditLogger->logAction(
            $dryRun ? 'TRADE_ENTRY_SIMULATED' : 'TRADE_ENTRY_EXECUTED',
            'TRADE_ENTRY',
            $result->symbol,
            [
                'status' => $execution->status,
                'client_order_id' => $execution->clientOrderId,
                'exchange_order_id' => $execution->exchangeOrderId,
                'execution_tf' => $result->executionTf,
                'order_type' => $request->orderType,
            ]
        );
    }

    public function recordAuditTradeEntryFailure(SymbolResultDto $result, \Throwable $exception): void
    {
        $this->auditLogger->logAction(
            'TRADE_ENTRY_FAILED',
            'TRADE_ENTRY',
            $result->symbol,
            [
                'error' => $exception->getMessage(),
                'execution_tf' => $result->executionTf,
            ]
        );
    }

    public function recordSymbolResult(SymbolResultDto $result): void
    {
        $this->processedSymbols++;
        $status = strtoupper($result->status);
        $this->results[$result->symbol] = $result->toArray();

        if (!$result->isSkipped()) {
            $this->contractsProcessed++;
        }

        if ($result->isSuccess() && $result->executionTf !== null) {
            $this->lastSuccessfulTimeframe = $result->executionTf;
        }

        if ($status === 'SUCCESS') {
            $this->successCount++;
        } elseif ($status === 'ERROR') {
            $this->failedCount++;
        } elseif ($status === 'SKIPPED') {
            $this->skippedCount++;
        }
    }

    public function completeRun(float $durationSeconds): InternalRunSummaryDto
    {
        $summary = new InternalRunSummaryDto(
            runId: $this->currentRunId ?? 'unknown',
            executionTimeSeconds: round($durationSeconds, 3),
            symbolsRequested: $this->requestedSymbols,
            symbolsProcessed: $this->processedSymbols,
            symbolsSuccessful: $this->successCount,
            symbolsFailed: $this->failedCount,
            symbolsSkipped: $this->skippedCount,
            successRate: $this->processedSymbols > 0
                ? round(($this->successCount / $this->processedSymbols) * 100, 2)
                : 0.0,
            contractsProcessed: $this->contractsProcessed,
            lastSuccessfulTimeframe: $this->lastSuccessfulTimeframe,
            dryRun: $this->runContext?->dryRun ?? false,
            forceRun: $this->runContext?->forceRun ?? false,
            currentTf: $this->runContext?->currentTf,
            timestamp: new \DateTimeImmutable(),
            status: 'completed'
        );

        $this->auditLogger->logAction(
            'MTF_RUN_COMPLETED',
            'MTF_RUN',
            $summary->runId,
            $summary->toArray()
        );

        $this->orderJourneyLogger->info('order_journey.orchestrator.run_completed', [
            'run_id' => $summary->runId,
            'symbols_processed' => $summary->symbolsProcessed,
            'duration_seconds' => $summary->executionTimeSeconds,
            'reason' => 'mtf_cycle_completed',
        ]);

        return $summary;
    }

    public function completeWithStatus(string $status, float $durationSeconds): InternalRunSummaryDto
    {
        $summary = new InternalRunSummaryDto(
            runId: $this->currentRunId ?? 'unknown',
            executionTimeSeconds: round($durationSeconds, 3),
            symbolsRequested: $this->requestedSymbols,
            symbolsProcessed: 0,
            symbolsSuccessful: 0,
            symbolsFailed: 0,
            symbolsSkipped: 0,
            successRate: 0.0,
            contractsProcessed: 0,
            lastSuccessfulTimeframe: null,
            dryRun: $this->runContext?->dryRun ?? false,
            forceRun: $this->runContext?->forceRun ?? false,
            currentTf: $this->runContext?->currentTf,
            timestamp: new \DateTimeImmutable(),
            status: $status
        );

        $this->orderJourneyLogger->info('order_journey.orchestrator.run_completed', [
            'run_id' => $summary->runId,
            'symbols_processed' => 0,
            'duration_seconds' => $summary->executionTimeSeconds,
            'reason' => $status,
        ]);

        return $summary;
    }

    public function lockReleased(string $lockKey): void
    {
        $this->orderJourneyLogger->debug('order_journey.orchestrator.lock_released', [
            'run_id' => $this->currentRunId,
            'lock_key' => $lockKey,
        ]);
    }

    /**
     * @return array<string, array>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function reset(): void
    {
        $this->currentRunId = null;
        $this->runContext = null;
        $this->requestedSymbols = 0;
        $this->processedSymbols = 0;
        $this->successCount = 0;
        $this->failedCount = 0;
        $this->skippedCount = 0;
        $this->contractsProcessed = 0;
        $this->lastSuccessfulTimeframe = null;
        $this->results = [];
    }
}

