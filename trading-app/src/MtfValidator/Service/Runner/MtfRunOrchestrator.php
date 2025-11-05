<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Runner;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\FeatureSwitchInterface;
use App\Contract\Runtime\LockManagerInterface;
use App\MtfValidator\Service\Application\PositionsSnapshotService;
use App\MtfValidator\Service\Dto\Internal\InternalRunSummaryDto;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\MtfValidator\Service\Metrics\RunMetricsAggregator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * Orchestrateur principal pour l'exécution des cycles MTF
 * Optimisé pour les contraintes de performance
 */
final class MtfRunOrchestrator
{
    public function __construct(
        private readonly SymbolProcessor $symbolProcessor,
        private readonly PositionsSnapshotService $positionsSnapshotService,
        private readonly TradingDecisionHandler $tradingDecisionHandler,
        private readonly LockManagerInterface $lockManager,
        private readonly FeatureSwitchInterface $featureSwitch,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly RunMetricsAggregator $metricsAggregator,
    ) {}

    /**
     * Exécute un cycle MTF complet
     */
    public function execute(MtfRunDto $mtfRunDto, UuidInterface $runId): \Generator
    {
        $startTime = microtime(true);
        $runIdString = $runId->toString();

        $this->logger->info('[MTF Orchestrator] Starting execution', [
            'run_id' => $runIdString,
            'symbols_count' => count($mtfRunDto->symbols),
            'dry_run' => $mtfRunDto->dryRun
        ]);
        $this->metricsAggregator->startRun($runId, $mtfRunDto);

        // Vérifier les commutateurs globaux
        if (!$this->checkGlobalSwitches($mtfRunDto, $runIdString)) {
            $this->metricsAggregator->runBlocked('global_switch_off');
            yield from $this->yieldBlockedResult($mtfRunDto, $runId, $startTime, 'global_switch_off');
            return;
        }

        // Acquérir le verrou
        $lockKey = $this->determineLockKey($mtfRunDto);
        if (!$this->acquireExecutionLock($lockKey, $runIdString)) {
            $this->metricsAggregator->runBlocked('lock_acquisition_failed', ['lock_key' => $lockKey]);
            yield from $this->yieldBlockedResult($mtfRunDto, $runId, $startTime, 'lock_acquisition_failed');
            return;
        }

        $snapshot = null;
        $snapshotRefreshed = false;

        try {
            $snapshot = $this->positionsSnapshotService->buildSnapshot($mtfRunDto);
            $symbols = $this->positionsSnapshotService->filterSymbols($mtfRunDto, $snapshot);
            if (empty($symbols)) {
                yield from $this->yieldEmptyResult($mtfRunDto, $runId, $startTime);
                return;
            }

            // Traiter les symboles
            $results = [];
            $totalSymbols = count($symbols);

            foreach ($symbols as $index => $symbol) {
                $this->logger->debug('[MTF Orchestrator] Processing symbol', [
                    'run_id' => $runIdString,
                    'symbol' => $symbol,
                    'position' => $index + 1,
                    'total' => $totalSymbols
                ]);
                $this->metricsAggregator->symbolStarted($symbol, $index + 1, $totalSymbols);

                $symbolResult = $this->symbolProcessor->processSymbol(
                    $symbol,
                    $runId,
                    $mtfRunDto,
                    $this->clock->now(),
                    $snapshot
                );

                $this->metricsAggregator->symbolMtfResult($symbolResult);

                // Gérer la décision de trading uniquement pour READY ou SUCCESS
                $effective = $symbolResult;
                if ($effective->isSuccess() || $effective->isReady()) {
                    $this->metricsAggregator->decisionLifecycleStart($effective);
                    $effective = $this->tradingDecisionHandler->handleTradingDecision(
                        $effective,
                        $mtfRunDto
                    );
                    $symbolResult = $effective; // reflect decision changes
                    $this->metricsAggregator->decisionLifecycleEnd($effective);
                }

                $results[$symbol] = $symbolResult->toArray();
                $this->metricsAggregator->recordSymbolResult($symbolResult);

                $this->positionsSnapshotService->applySymbolOutcome(
                    $symbol,
                    $snapshot->getSymbolContext($symbol),
                    $symbolResult->toArray()
                );

                // Yield progress
                yield [
                    'symbol' => $symbol,
                    'result' => $symbolResult->toArray(),
                    'progress' => [
                        'current' => $index + 1,
                        'total' => $totalSymbols,
                        'percentage' => round((($index + 1) / $totalSymbols) * 100, 2),
                        'symbol' => $symbol,
                        'status' => $symbolResult->status,
                    ],
                ];
            }

            $summary = $this->metricsAggregator->completeRun(microtime(true) - $startTime);

            $this->positionsSnapshotService->refreshAfterRun();
            $snapshotRefreshed = true;

            yield from $this->yieldFinalResult($summary, $results, $startTime, $runId);

        } finally {
            if ($snapshot !== null && !$snapshotRefreshed) {
                try {
                    $this->positionsSnapshotService->refreshAfterRun();
                } catch (\Throwable $exception) {
                    $this->logger->warning('[MTF Orchestrator] Failed to refresh snapshot after run', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->lockManager->releaseLock($lockKey);
            $this->metricsAggregator->lockReleased($lockKey);
            $this->metricsAggregator->reset();
        }
    }

    // Note: temporary force-ready testing path removed to restore normal gating

    private function checkGlobalSwitches(MtfRunDto $mtfRunDto, string $runIdString): bool
    {
        if ($mtfRunDto->forceRun) {
            return true;
        }

        // Par défaut: table vide ⇒ ON. On définit l'état par défaut à ON avant vérification.
        $this->featureSwitch->setDefaultState('mtf_global_switch', true);

        if (!$this->featureSwitch->isEnabled('mtf_global_switch')) {
            $this->logger->debug('[MTF Orchestrator] Global switch OFF', [
                'run_id' => $runIdString
            ]);
            return false;
        }

        $this->logger->debug('[MTF Orchestrator] Global switch ON', [
            'run_id' => $runIdString
        ]);

        return true;
    }

    private function determineLockKey(MtfRunDto $mtfRunDto): string
    {
        if ($mtfRunDto->lockPerSymbol && count($mtfRunDto->symbols) === 1) {
            return 'mtf_execution:' . strtoupper($mtfRunDto->symbols[0]);
        }
        return 'mtf_execution';
    }

    private function acquireExecutionLock(string $lockKey, string $runIdString): bool
    {
        $lockTimeout = 600; // 10 minutes
        return $this->lockManager->acquireLockWithRetry($lockKey, $lockTimeout, 3, 100);
    }



    private function yieldBlockedResult(MtfRunDto $mtfRunDto, UuidInterface $runId, float $startTime, string $reason): \Generator
    {
        $summary = $this->metricsAggregator->completeWithStatus($reason, microtime(true) - $startTime);


        yield from $this->yieldFinalResult($summary, [], $startTime, $runId);
    }

    private function yieldEmptyResult(MtfRunDto $mtfRunDto, UuidInterface $runId, float $startTime): \Generator
    {
        $summary = $this->metricsAggregator->completeWithStatus('no_active_symbols', microtime(true) - $startTime);

        yield from $this->yieldFinalResult($summary, [], $startTime, $runId);
    }

    private function yieldFinalResult(InternalRunSummaryDto $summary, array $results, float $startTime, UuidInterface $runId): \Generator
    {
        yield [
            'symbol' => 'FINAL',
            'result' => $summary->toArray(),
            'progress' => [
                'current' => count($results),
                'total' => count($results),
                'percentage' => 100.0,
                'symbol' => 'FINAL',
                'status' => 'completed',
                'execution_time' => round(microtime(true) - $startTime, 3),
            ],
        ];

        return ['summary' => $summary->toArray(), 'results' => $results];
    }
}
