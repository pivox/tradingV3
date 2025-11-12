<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Runner;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\Contract\Runtime\FeatureSwitchInterface;
use App\Contract\Runtime\LockManagerInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\AccountProviderInterface;
use App\Config\MtfValidationConfig;
use App\MtfValidator\Service\Dto\MtfRunResultDto;
use App\MtfValidator\Service\Dto\RunSummaryDto;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Repository\MtfSwitchRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $mtfLogger,
        private readonly ClockInterface $clock,
        private readonly MtfValidationConfig $mtfConfig,
        private readonly ?AccountProviderInterface $accountProvider = null,
        private readonly ?OrderProviderInterface $orderProvider = null,
        private readonly ?MtfSwitchRepository $mtfSwitchRepository = null,
    ) {}

    /**
     * Exécute un cycle MTF complet
     */
    public function execute(MtfRunDto $mtfRunDto, UuidInterface $runId): \Generator
    {
        $startTime = microtime(true);
        $runIdString = $runId->toString();

        $this->mtfLogger->info('[MTF Orchestrator] Starting execution', [
            'run_id' => $runIdString,
            'symbols_count' => count($mtfRunDto->symbols),
            'dry_run' => $mtfRunDto->dryRun
        ]);
        $this->mtfLogger->info('order_journey.orchestrator.run_start', [
            'run_id' => $runIdString,
            'symbols_count' => count($mtfRunDto->symbols),
            'dry_run' => $mtfRunDto->dryRun,
            'reason' => 'mtf_cycle_begin',
        ]);

        // Vérifier les commutateurs globaux
        if (!$this->checkGlobalSwitches($mtfRunDto, $runIdString)) {
            $this->mtfLogger->info('order_journey.orchestrator.run_blocked', [
                'run_id' => $runIdString,
                'reason' => 'global_switch_off',
            ]);
            yield from $this->yieldBlockedResult($mtfRunDto, $runId, $startTime, 'global_switch_off');
            return;
        }

        // Acquérir le verrou
        $lockKey = $this->determineLockKey($mtfRunDto);
        if (!$this->acquireExecutionLock($lockKey, $runIdString)) {
            $this->mtfLogger->warning('order_journey.orchestrator.run_blocked', [
                'run_id' => $runIdString,
                'lock_key' => $lockKey,
                'reason' => 'lock_acquisition_failed',
            ]);
            yield from $this->yieldBlockedResult($mtfRunDto, $runId, $startTime, 'lock_acquisition_failed');
            return;
        }

        try {
            // Utiliser la liste de symboles fournie par le DTO
            // Note: Le filtrage des symboles avec ordres/positions ouverts est maintenant fait
            // dans le contrôleur AVANT d'envoyer les symboles aux workers
            $symbols = $mtfRunDto->symbols;
            if (empty($symbols)) {
                yield from $this->yieldEmptyResult($mtfRunDto, $runId, $startTime);
                return;
            }

            // Traiter les symboles
            $results = [];
            $totalSymbols = count($symbols);

            foreach ($symbols as $index => $symbol) {
                $this->mtfLogger->debug('[MTF Orchestrator] Processing symbol', [
                    'run_id' => $runIdString,
                    'symbol' => $symbol,
                    'position' => $index + 1,
                    'total' => $totalSymbols
                ]);
                $this->mtfLogger->info('order_journey.orchestrator.symbol_start', [
                    'run_id' => $runIdString,
                    'symbol' => $symbol,
                    'position' => $index + 1,
                    'total' => $totalSymbols,
                    'reason' => 'symbol_cycle_begin',
                ]);

                $symbolResult = $this->symbolProcessor->processSymbol(
                    $symbol,
                    $runId,
                    $mtfRunDto,
                    $this->clock->now()
                );

                $this->mtfLogger->info('order_journey.orchestrator.symbol_mtf_result', [
                    'run_id' => $runIdString,
                    'symbol' => $symbol,
                    'status' => $symbolResult->status,
                    'execution_tf' => $symbolResult->executionTf,
                    'signal_side' => $symbolResult->signalSide,
                    'reason' => 'mtf_processing_completed',
                ]);

                // Gérer la décision de trading uniquement pour READY ou SUCCESS
                $effective = $symbolResult;
                if ($effective->isSuccess() || strtoupper($effective->status) === 'READY') {
                    $this->mtfLogger->info('order_journey.orchestrator.symbol_decision_start', [
                        'run_id' => $runIdString,
                        'symbol' => $symbol,
                        'status' => $effective->status,
                        'reason' => 'invoke_trading_decision',
                    ]);
                    $effective = $this->tradingDecisionHandler->handleTradingDecision(
                        $effective,
                        $mtfRunDto
                    );
                    $symbolResult = $effective; // reflect decision changes
                    $this->mtfLogger->info('order_journey.orchestrator.symbol_decision_done', [
                        'run_id' => $runIdString,
                        'symbol' => $symbol,
                        'status' => $effective->status,
                        'reason' => 'trading_decision_completed',
                    ]);
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

            $this->mtfLogger->info('order_journey.orchestrator.run_completed', [
                'run_id' => $runIdString,
                'symbols_processed' => count($results),
                'duration_seconds' => round(microtime(true) - $startTime, 3),
                'reason' => 'mtf_cycle_completed',
            ]);

            yield from $this->yieldFinalResult($summary, $results, $startTime, $runId);

        } finally {
            $this->lockManager->releaseLock($lockKey);
            // Extraire le symbol du lock_key si disponible
            $symbol = null;
            if (strpos($lockKey, ':') !== false) {
                $parts = explode(':', $lockKey);
                $symbol = $parts[1] ?? null;
            }
            $logContext = [
                'run_id' => $runIdString,
                'lock_key' => $lockKey,
            ];
            if ($symbol) {
                $logContext['symbol'] = $symbol;
            }
            $this->mtfLogger->debug('order_journey.orchestrator.lock_released', $logContext);
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
            $this->mtfLogger->debug('[MTF Orchestrator] Global switch OFF', [
                'run_id' => $runIdString
            ]);
            $this->mtfLogger->debug('order_journey.orchestrator.global_switch_off', [
                'run_id' => $runIdString,
            ]);
            return false;
        }

        $this->mtfLogger->debug('[MTF Orchestrator] Global switch ON', [
            'run_id' => $runIdString
        ]);
        $this->mtfLogger->debug('order_journey.orchestrator.global_switch_on', [
            'run_id' => $runIdString,
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

        // Yield le summary pour qu'il soit accessible via foreach
        yield ['summary' => $summary->toArray(), 'results' => $results];
    }

    /**
     * Filtre les symboles ayant des ordres ou positions ouverts
     * Désactive le switch pour ces symboles (1 min si déjà OFF, 15 min sinon)
     * Retourne la liste des symboles à exclure de la boucle
     */
    public function filterSymbolsWithOpenOrdersOrPositions(array $symbols, string $runIdString): array
    {
        if (empty($symbols) || (!$this->accountProvider && !$this->orderProvider)) {
            return $symbols;
        }

        $symbolsToExclude = [];
        $symbolsToProcess = [];

        // Récupérer les symboles avec positions ouvertes depuis l'exchange
        $openPositionSymbols = [];
        if ($this->accountProvider) {
            try {
                $openPositions = $this->accountProvider->getOpenPositions();
                $this->mtfLogger->debug('[MTF Orchestrator] Fetched open positions', [
                    'run_id' => $runIdString,
                    'count' => count($openPositions),
                ]);
                
                foreach ($openPositions as $position) {
                    // PositionDto a une propriété symbol
                    $positionSymbol = strtoupper($position->symbol ?? '');
                    if ($positionSymbol !== '' && !in_array($positionSymbol, $openPositionSymbols, true)) {
                        $openPositionSymbols[] = $positionSymbol;
                        $this->mtfLogger->debug('[MTF Orchestrator] Found open position', [
                            'run_id' => $runIdString,
                            'symbol' => $positionSymbol,
                            'size' => $position->size->toFloat(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $this->mtfLogger->warning('[MTF Orchestrator] Failed to fetch open positions from exchange', [
                    'run_id' => $runIdString,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Récupérer les symboles avec ordres ouverts depuis l'exchange (ordres normaux uniquement, pas TP/SL)
        $openOrderSymbols = [];
        if ($this->orderProvider) {
            try {
                // getOpenOrders() récupère uniquement les ordres normaux, pas les TP/SL (plan orders)
                $openOrders = $this->orderProvider->getOpenOrders();
                $this->mtfLogger->debug('[MTF Orchestrator] Fetched open orders', [
                    'run_id' => $runIdString,
                    'count' => count($openOrders),
                ]);
                
                foreach ($openOrders as $order) {
                    // OrderDto a une propriété symbol
                    $orderSymbol = strtoupper($order->symbol ?? '');
                    if ($orderSymbol !== '' && !in_array($orderSymbol, $openOrderSymbols, true)) {
                        $openOrderSymbols[] = $orderSymbol;
                        $this->mtfLogger->debug('[MTF Orchestrator] Found open order', [
                            'run_id' => $runIdString,
                            'symbol' => $orderSymbol,
                            'order_id' => $order->orderId ?? 'N/A',
                            'type' => $order->type->value ?? 'N/A',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $this->mtfLogger->warning('[MTF Orchestrator] Failed to fetch open orders from exchange', [
                    'run_id' => $runIdString,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Combiner les symboles à exclure
        $symbolsWithActivity = array_unique(array_merge($openPositionSymbols, $openOrderSymbols));

        // Réactiver les switches des symboles qui n'ont plus d'ordres/positions ouverts
        if ($this->mtfSwitchRepository) {
            try {
                $reactivatedCount = $this->mtfSwitchRepository->reactivateSwitchesForInactiveSymbols($symbolsWithActivity);
                if ($reactivatedCount > 0) {
                    $this->mtfLogger->info('[MTF Orchestrator] Reactivated switches for inactive symbols', [
                        'run_id' => $runIdString,
                        'reactivated_count' => $reactivatedCount,
                        'reason' => 'no_open_orders_or_positions',
                    ]);
                }
            } catch (\Throwable $e) {
                $this->mtfLogger->error('[MTF Orchestrator] Failed to reactivate switches for inactive symbols', [
                    'run_id' => $runIdString,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Traiter chaque symbole
        foreach ($symbols as $symbol) {
            $symbolUpper = strtoupper($symbol);
            
            if (in_array($symbolUpper, $symbolsWithActivity, true)) {
                // Symbol a des ordres/positions ouverts → exclure de la boucle
                $symbolsToExclude[] = $symbolUpper;
                
                // Désactiver le switch selon l'état actuel
                if ($this->mtfSwitchRepository) {
                    try {
                        $isSwitchOff = !$this->mtfSwitchRepository->isSymbolSwitchOn($symbolUpper);
                        
                        if ($isSwitchOff) {
                            // Switch déjà OFF → désactiver pour 1 minute
                            $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, '1m');
                            $this->mtfLogger->info('[MTF Orchestrator] Symbol switch extended (was OFF)', [
                                'run_id' => $runIdString,
                                'symbol' => $symbolUpper,
                                'duration' => '1 minute',
                                'reason' => 'has_open_orders_or_positions',
                            ]);
                        } else {
                            // Switch ON → désactiver pour 5 minutes
                            $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, duration: '5m');
                            $this->mtfLogger->info('[MTF Orchestrator] Symbol switch disabled', [
                                'run_id' => $runIdString,
                                'symbol' => $symbolUpper,
                                'duration' => '5 minutes',
                                'reason' => 'has_open_orders_or_positions',
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->mtfLogger->error('[MTF Orchestrator] Failed to disable symbol switch', [
                            'run_id' => $runIdString,
                            'symbol' => $symbolUpper,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                // Symbol n'a pas d'ordres/positions → inclure dans la boucle
                $symbolsToProcess[] = $symbol;
            }
        }

        if (!empty($symbolsToExclude)) {
            $this->mtfLogger->info('[MTF Orchestrator] Filtered symbols with open orders/positions', [
                'run_id' => $runIdString,
                'excluded_count' => count($symbolsToExclude),
                'excluded_symbols' => array_slice($symbolsToExclude, 0, 10), // Log first 10
                'remaining_count' => count($symbolsToProcess),
            ]);
            $this->mtfLogger->info('order_journey.orchestrator.symbols_filtered', [
                'run_id' => $runIdString,
                'excluded_count' => count($symbolsToExclude),
                'remaining_count' => count($symbolsToProcess),
                'reason' => 'open_orders_or_positions_exclusion',
            ]);
        }

        return $symbolsToProcess;
    }
}
