<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Runner;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\Contract\Runtime\FeatureSwitchInterface;
use App\Contract\Runtime\LockManagerInterface;
use App\Config\MtfValidationConfig;
use App\MtfValidator\Service\Dto\MtfRunResultDto;
use App\MtfValidator\Service\Dto\RunSummaryDto;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\MtfValidator\Service\Dto\SymbolResultDto;
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
        private readonly TradingDecisionHandler $tradingDecisionHandler,
        private readonly LockManagerInterface $lockManager,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly FeatureSwitchInterface $featureSwitch,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly MtfValidationConfig $mtfConfig
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

        // Vérifier les commutateurs globaux
        if (!$this->checkGlobalSwitches($mtfRunDto, $runIdString)) {
            yield from $this->yieldBlockedResult($mtfRunDto, $runId, $startTime, 'global_switch_off');
            return;
        }

        // Acquérir le verrou
        $lockKey = $this->determineLockKey($mtfRunDto);
        if (!$this->acquireExecutionLock($lockKey, $runIdString)) {
            yield from $this->yieldBlockedResult($mtfRunDto, $runId, $startTime, 'lock_acquisition_failed');
            return;
        }

        try {
            // Utiliser la liste de symboles fournie par le DTO
            $symbols = $mtfRunDto->symbols;
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

                $symbolResult = $this->symbolProcessor->processSymbol(
                    $symbol,
                    $runId,
                    $mtfRunDto,
                    $this->clock->now()
                );

                // Gérer la décision de trading uniquement pour READY ou SUCCESS
                $effective = $symbolResult;
                if ($effective->isSuccess() || strtoupper($effective->status) === 'READY') {
                    $effective = $this->tradingDecisionHandler->handleTradingDecision(
                        $effective,
                        $mtfRunDto
                    );
                    $symbolResult = $effective; // reflect decision changes
                }

                $results[$symbol] = $symbolResult->toArray();

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

            // Créer le résumé
            $summary = $this->createRunSummary($runId, $results, $startTime, $mtfRunDto);
            
            // Logger l'audit
            $this->auditLogger->logAction(
                'MTF_RUN_COMPLETED',
                'MTF_RUN',
                $runIdString,
                $summary->toArray()
            );

            yield from $this->yieldFinalResult($summary, $results, $startTime, $runId);

        } finally {
            $this->lockManager->releaseLock($lockKey);
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

    

    private function createRunSummary(
        UuidInterface $runId,
        array $results,
        float $startTime,
        MtfRunDto $mtfRunDto
    ): RunSummaryDto {
        $executionTime = microtime(true) - $startTime;
        $successful = count(array_filter($results, fn($r) => strtoupper($r['status'] ?? '') === 'SUCCESS'));
        $failed = count(array_filter($results, fn($r) => strtoupper($r['status'] ?? '') === 'ERROR'));
        $skipped = count(array_filter($results, fn($r) => strtoupper($r['status'] ?? '') === 'SKIPPED'));

        return new RunSummaryDto(
            runId: $runId->toString(),
            executionTimeSeconds: round($executionTime, 3),
            symbolsRequested: count($results),
            symbolsProcessed: count($results),
            symbolsSuccessful: $successful,
            symbolsFailed: $failed,
            symbolsSkipped: $skipped,
            successRate: count($results) > 0 ? round(($successful / count($results)) * 100, 2) : 0.0,
            dryRun: $mtfRunDto->dryRun,
            forceRun: $mtfRunDto->forceRun,
            currentTf: $mtfRunDto->currentTf,
            timestamp: $this->clock->now(),
            status: 'completed'
        );
    }

    private function yieldBlockedResult(MtfRunDto $mtfRunDto, UuidInterface $runId, float $startTime, string $reason): \Generator
    {
        $summary = new RunSummaryDto(
            runId: $runId->toString(),
            executionTimeSeconds: round(microtime(true) - $startTime, 3),
            symbolsRequested: 0,
            symbolsProcessed: 0,
            symbolsSuccessful: 0,
            symbolsFailed: 0,
            symbolsSkipped: 0,
            successRate: 0.0,
            dryRun: $mtfRunDto->dryRun,
            forceRun: $mtfRunDto->forceRun,
            currentTf: $mtfRunDto->currentTf,
            timestamp: $this->clock->now(),
            status: $reason
        );

        yield from $this->yieldFinalResult($summary, [], $startTime, $runId);
    }

    private function yieldEmptyResult(MtfRunDto $mtfRunDto, UuidInterface $runId, float $startTime): \Generator
    {
        $summary = new RunSummaryDto(
            runId: $runId->toString(),
            executionTimeSeconds: round(microtime(true) - $startTime, 3),
            symbolsRequested: 0,
            symbolsProcessed: 0,
            symbolsSuccessful: 0,
            symbolsFailed: 0,
            symbolsSkipped: 0,
            successRate: 0.0,
            dryRun: $mtfRunDto->dryRun,
            forceRun: $mtfRunDto->forceRun,
            currentTf: $mtfRunDto->currentTf,
            timestamp: $this->clock->now(),
            status: 'no_active_symbols'
        );

        yield from $this->yieldFinalResult($summary, [], $startTime, $runId);
    }

    private function yieldFinalResult(RunSummaryDto $summary, array $results, float $startTime, UuidInterface $runId): \Generator
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
