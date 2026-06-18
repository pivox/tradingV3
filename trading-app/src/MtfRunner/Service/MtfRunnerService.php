<?php

declare(strict_types=1);

namespace App\MtfRunner\Service;

use App\Application\Runner\ExchangeStateSynchronizer;
use App\Application\Runner\OpenActivityFilter;
use App\Application\Runner\PostRunProjectionDispatcher;
use App\Application\Runner\RunResultAssembler;
use App\Application\Runner\SymbolUniverseResolver;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\PositionSide;
use App\MtfRunner\Dto\MtfRunnerRequestDto as RunnerRequestDto;
use App\MtfValidator\Application\TradeDecisionDispatcherInterface;
use App\MtfValidator\Repository\MtfLockRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Context\ExchangeContext;
use App\Config\TradeEntryConfigProvider;
use App\Config\TradeEntryModeContext;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Service\TpSlTwoTargetsService;
use App\TradeEntry\Types\Side as EntrySide;
use App\MtfValidator\Service\PerformanceProfiler;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SplQueue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Service Runner MTF - Centralise toutes les responsabilités d'exécution MTF
 *
 * Responsabilités :
 * - Résolution des symboles
 * - Filtrage des symboles avec ordres/positions ouverts
 * - Gestion des locks
 * - Gestion des switches
 * - Synchronisation des tables (position, futures_order, futures_order_trade)
 * - Exécution MTF séquentielle ou parallèle
 * - Recalcul TP/SL
 */
final class MtfRunnerService
{
    public function __construct(
        private readonly SymbolUniverseResolver $symbolUniverseResolver,
        private readonly OpenActivityFilter $openActivityFilter,
        private readonly ExchangeStateSynchronizer $exchangeStateSynchronizer,
        private readonly PostRunProjectionDispatcher $postRunProjectionDispatcher,
        private readonly RunResultAssembler $runResultAssembler,
        private readonly MtfLockRepository $mtfLockRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfValidatorInterface $mtfValidator,
        private readonly MainProviderInterface $mainProvider,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $mtfLogger,
        private readonly LoggerInterface $positionsLogger,
        private readonly TradeDecisionDispatcherInterface $tradeDecisionDispatcher,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ClockInterface $clock,
        private readonly ?TpSlTwoTargetsService $tpSlService = null,
        private readonly ?TradeEntryConfigProvider $tradeEntryConfigProvider = null,
        private readonly ?TradeEntryModeContext $tradeEntryModeContext = null,
    ) {
    }

    /**
     * Exécute un cycle MTF complet avec toutes les responsabilités
     *
     * @return array{
     *     summary: array,
     *     results: array,
     *     errors: array,
     *     summary_by_tf: array,
     *     rejected_by: array,
     *     last_validated: array,
     *     orders_placed: array,
     *     performance: array
     * }
     */
    public function run(RunnerRequestDto $request): array
    {
        $profiler = new PerformanceProfiler();
        $runId = Uuid::uuid4()->toString();
        $startTime = microtime(true);
        $openPositions = null;
        $openOrders = null;

        $this->mtfLogger->info('[MTF Runner] Starting execution', [
            'run_id' => $runId,
            'symbols_count' => count($request->symbols),
            'dry_run' => $request->dryRun,
            'workers' => $request->workers,
        ]);

        // SF-002b : fail-closed en live. Si aucune source d'état ouvert fiable n'est
        // disponible (pas de snapshot orchestrateur, sync_tables=false ET filtre désactivé),
        // on refuse le run plutôt que de trader à l'aveugle sur des symboles peut-être déjà
        // en position/ordre. Miroir du garde-fou CLI de SF-002a.
        $hasSnapshot = $this->snapshotIsUsable($request->openStateSnapshot);
        if (!$request->dryRun && !$hasSnapshot && !$request->syncTables && $request->skipOpenStateFilter) {
            $reason = 'no_reliable_open_state_source';
            $this->mtfLogger->error('[MTF Runner] Refusing live run without reliable open-state source', [
                'run_id' => $runId,
                'reason' => $reason,
                'has_snapshot' => false,
                'sync_tables' => false,
                'skip_open_state_filter' => true,
            ]);

            return $this->buildRejectedRun(
                $runId,
                'En live, refus du run sans source d\'état ouvert fiable '
                . '(open_state_snapshot absent, sync_tables=false et skip_open_state_filter=true). '
                . 'Le filtre protège contre le trading sur un symbole déjà en position/ordre.',
                $reason,
                count($request->symbols),
            );
        }

        try {
            // 1. Créer le contexte
            $context = $this->createContext($request);

            // 2. Résoudre les symboles
            $resolveStart = microtime(true);
            $symbols = $this->resolveSymbols($request->symbols, $request->profile, $context);
            $profiler->increment('runner', 'resolve_symbols', microtime(true) - $resolveStart);

            // 3. Source de l'état ouvert (priorité) :
            //    (1) snapshot orchestrateur (SF-002b) → aucun appel exchange par set ;
            //    (2) sinon syncTables() si sync_tables=true (upsert DB + fetch amont) ;
            //    (3) sinon null → le filtre d'activité refera son propre fetch si actif.
            if ($hasSnapshot) {
                /** @var array{open_positions?: array<int,mixed>, open_orders?: array<int,mixed>} $snapshot */
                $snapshot = $request->openStateSnapshot;
                $openPositions = array_values($snapshot['open_positions'] ?? []);
                $openOrders = array_values($snapshot['open_orders'] ?? []);
                $this->mtfLogger->info('[MTF Runner] Using orchestrator open-state snapshot (no exchange fetch per set)', [
                    'run_id' => $runId,
                    'positions_count' => count($openPositions),
                    'orders_count' => count($openOrders),
                ]);
            } elseif ($request->syncTables) {
                $syncStart = microtime(true);
                [
                    'open_positions' => $openPositions,
                    'open_orders' => $openOrders,
                ] = $this->syncTables($context);
                $profiler->increment('runner', 'sync_tables', microtime(true) - $syncStart);
            } else {
                // Portée limitée (SF-002a) : on saute uniquement l'upsert DB des
                // positions/ordres. Le filtre d'activité (OpenActivityFilter) peut
                // encore appeler l'exchange si skip_open_state_filter=false.
                $this->mtfLogger->debug('[MTF Runner] Skipping exchange table upsert (sync_tables=false)', [
                    'run_id' => $runId,
                ]);
            }

            // 4. Filtrer les symboles avec ordres/positions ouverts
            $excludedSymbols = [];
            if (!$request->skipOpenStateFilter) {
                $filterStart = microtime(true);
                $symbols = $this->filterSymbolsWithOpenOrdersOrPositions(
                    $symbols,
                    $runId,
                    $context,
                    $excludedSymbols,
                    $openPositions,
                    $openOrders
                );
                $profiler->increment('runner', 'filter_symbols', microtime(true) - $filterStart);
            }

            // 5. Gérer les locks
            $this->manageLocks($runId, $request->lockPerSymbol, $symbols);

            // 6. Gérer les switches
            $this->manageSwitches($symbols, $excludedSymbols, $runId);

            // 7. Exécuter MTF (séquentiel ou parallèle)
            $execStart = microtime(true);

            $result = $request->workers > 1
                ? $this->runParallel($symbols, $request, $context, $runId)
                : $this->runSequential($symbols, $request, $context);
            $profiler->increment('runner', 'mtf_execution', microtime(true) - $execStart);

            $this->postRunProjectionDispatcher->dispatch($result['results'] ?? [], $request, $runId);

            // 8. Mettre à jour les switches pour les symboles exclus (après traitement)
            if (!empty($excludedSymbols)) {
                $this->updateSwitchesForExcludedSymbols($excludedSymbols, $runId);
            }

            // 9. Recalcul TP/SL (si demandé, avec throttling)
            $shouldProcessTpSl = $request->processTpSl && $this->shouldRunTpSlNow();
            if ($shouldProcessTpSl) {
                try {
                    $tpSlStart = microtime(true);
                    $this->processTpSlRecalculation($request->dryRun, $context);
                    $profiler->increment('runner', 'tp_sl_recalculation', microtime(true) - $tpSlStart);
                } catch (\Throwable $e) {
                    $this->positionsLogger->warning('[MTF Runner] TP/SL recalculation failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                if ($request->processTpSl) {
                    $this->positionsLogger->debug('[MTF Runner] TP/SL recalculation skipped by throttling rule');
                }
            }

            // 10. Post-processing : enrichir les résultats
            $postProcessStart = microtime(true);
            $results = $result['results'] ?? [];
            $enriched = $this->runResultAssembler->enrich($results);
            $profiler->increment('runner', 'post_processing', microtime(true) - $postProcessStart);

            $executionTime = microtime(true) - $startTime;
            $performanceReport = $profiler->getReport();

            $this->mtfLogger->info('[MTF Runner] Execution completed', [
                'run_id' => $runId,
                'execution_time' => round($executionTime, 3),
                'symbols_processed' => count($results),
                'performance' => $performanceReport,
            ]);

            return $this->runResultAssembler->assemble($result, $results, $enriched, $performanceReport);

        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Execution failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Résout les symboles à traiter
     *
     * @param array<string> $inputSymbols Symboles fournis en entrée
     * @return array<string> Liste des symboles à traiter
     */
    /**
     * @param array<string> $inputSymbols Liste des symboles fournis en entrée
     * @param string|null $profile Profil de configuration à utiliser pour récupérer les contrats actifs
     * @return string[]
     */
    public function resolveSymbols(array $inputSymbols, ?string $profile = null, ?ExchangeContext $context = null): array
    {
        return $this->symbolUniverseResolver->resolve($inputSymbols, $profile, $context);
    }

    /**
     * Filtre les symboles ayant des ordres ou positions ouverts
     *
     * @param array<string> $symbols Liste des symboles à filtrer
     * @param string $runId ID du run pour les logs
     * @param array<string> $excludedSymbols Référence pour retourner les symboles exclus
     * @param ExchangeContext $context Contexte d'échange
     * @param array<array>|null $openPositions Positions ouvertes préchargées (optionnel)
     * @param array<array>|null $openOrders Ordres ouverts préchargés (optionnel)
     * @return array<string> Liste des symboles à traiter (sans ceux exclus)
     */
    // PHP 8.1: paramètres requis avant optionnels (voir n° de commit)
    public function filterSymbolsWithOpenOrdersOrPositions(
        array $symbols,
        string $runId,
        ExchangeContext $context,
        array &$excludedSymbols = [],
        ?array $openPositions = null,
        ?array $openOrders = null
    ): array {
        return $this->openActivityFilter->filter(
            $symbols,
            $runId,
            $context,
            $excludedSymbols,
            $openPositions,
            $openOrders,
        );
    }

    /**
     * Gère les locks (activation/désactivation)
     */
    public function manageLocks(string $runId, bool $lockPerSymbol, array $symbols): void
    {
        try {
            $lockKey = $lockPerSymbol ? 'mtf_execution_per_symbol' : 'mtf_execution';

            // Vérifier si un lock existe déjà
            $lockInfo = $this->mtfLockRepository->getLockInfo($lockKey);
            if ($lockInfo && $lockInfo['is_locked']) {
                $this->mtfLogger->warning('[MTF Runner] Lock already exists', [
                    'run_id' => $runId,
                    'lock_key' => $lockKey,
                ]);
                // Ne pas bloquer, juste logger
            }

            // Pour l'instant, on ne crée pas de lock automatiquement
            // Les locks au niveau MTF sont gérés côté orchestrateur (MtfRunOrchestrator)
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to manage locks', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gère les switches (activation/désactivation)
     */
    public function manageSwitches(array $symbols, array $excludedSymbols, string $runId): void
    {
        try {
            // Les switches sont déjà gérés dans filterSymbolsWithOpenOrdersOrPositions
            // Cette méthode peut être étendue pour d'autres logiques de switch
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to manage switches', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Met à jour les switches pour les symboles exclus (appelé APRÈS le traitement)
     */
    private function updateSwitchesForExcludedSymbols(array $excludedSymbols, string $runId): void
    {
        foreach ($excludedSymbols as $symbolUpper) {
            try {
                $isSwitchOff = !$this->mtfSwitchRepository->isSymbolSwitchOn($symbolUpper);

                if ($isSwitchOff) {
                    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, '1m');
                    $this->mtfLogger->info('[MTF Runner] Symbol switch extended (was OFF)', [
                        'run_id' => $runId,
                        'symbol' => $symbolUpper,
                        'duration' => '1 minute',
                        'reason' => 'has_open_orders_or_positions',
                    ]);
                } else {
                    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, '5m');
                    $this->mtfLogger->info('[MTF Runner] Symbol switch disabled', [
                        'run_id' => $runId,
                        'symbol' => $symbolUpper,
                        'duration' => '5 minutes',
                        'reason' => 'has_open_orders_or_positions',
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('[MTF Runner] Failed to update symbol switch', [
                    'run_id' => $runId,
                    'symbol' => $symbolUpper,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Synchronise les tables depuis l'exchange
     * - positions
     * - futures_order
     * - futures_order_trade
     */
    public function syncTables(ExchangeContext $context): array
    {
        return $this->exchangeStateSynchronizer->sync($context);
    }

    /**
     * Exécute MTF en mode séquentiel
     */
    private function runSequential(
        array $symbols,
        RunnerRequestDto $request,
        ExchangeContext $context
    ): array {
        $mtfRequest = new MtfRunRequestDto(
            symbols: $symbols,
            dryRun: $request->dryRun,
            forceRun: $request->forceRun,
            currentTf: $request->currentTf,
            forceTimeframeCheck: $request->forceTimeframeCheck,
            skipContextValidation: $request->skipContextValidation,
            lockPerSymbol: $request->lockPerSymbol,
            skipOpenStateFilter: true, // Déjà filtré
            userId: $request->userId,
            ipAddress: $request->ipAddress,
            exchange: $context->exchange,
            marketType: $context->marketType,
            profile: $request->profile,
            mode: $request->validationMode,
        );

        $response = $this->mtfValidator->run($mtfRequest);
        $this->tradeDecisionDispatcher->dispatchFromResponse($mtfRequest, $response);

        $resultsMap = [];
        foreach ($response->results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $symbol = $entry['symbol'] ?? null;
            $result = $entry['result'] ?? null;

            if (!is_string($symbol) || $symbol === '' || !$result instanceof MtfResultDto) {
                continue;
            }

            $status = $result->isTradable ? 'READY' : 'INVALID';
            $resultsMap[$symbol] = [
                'symbol' => $symbol,
                'status' => $status,
                'execution_tf' => $result->executionTimeframe,
                'signal_side' => $result->side,
                'reason' => $result->finalReason,
                'context' => $result->context->toArray(),
                'execution' => $result->execution->toArray(),
            ];
        }

        return [
            'summary' => $response->toArray(),
            'results' => $resultsMap,
            'errors' => $response->errors,
        ];
    }

    /**
     * Exécute MTF en mode parallèle via des workers Process
     *
     * @return array{summary: array, results: array, errors: array}
     */
    private function runParallel(
        array $symbols,
        RunnerRequestDto $request,
        ExchangeContext $context,
        string $runId
    ): array {
        $queue = new SplQueue();
        foreach ($symbols as $symbol) {
            $queue->enqueue($symbol);
        }

        /** @var array<string,Process> $active map symbol => running process */
        $active = [];
        $results = [];
        $errors = [];
        $startedAt = microtime(true);
        $workerStartTimes = [];
        $pollingTime = 0;
        $pollingCount = 0;

        $options = [
            'dry_run' => $request->dryRun,
            'force_run' => $request->forceRun,
            'current_tf' => $request->currentTf,
            'force_timeframe_check' => $request->forceTimeframeCheck,
            'skip_context' => $request->skipContextValidation,
            'lock_per_symbol' => $request->lockPerSymbol,
            'skip_open_filter' => true, // Déjà filtré en amont
            'user_id' => $request->userId,
            'ip_address' => $request->ipAddress,
            'exchange' => $context->exchange->value,
            'market_type' => $context->marketType->value,
            'profile' => $request->profile,
            'validation_mode' => $request->validationMode,
        ];

        $this->mtfLogger->info('[MTF Runner] Starting parallel execution', [
            'run_id' => $runId,
            'symbols_count' => count($symbols),
            'workers' => $request->workers,
        ]);

        while (!$queue->isEmpty() || $active !== []) {
            $pollStart = microtime(true);

            $hasRunning = false;
            $finished = [];

            // Vérifier les workers terminés (sans modifier $active pendant l'itération)
            foreach ($active as $symbol => $process) {
                if ($process->isRunning()) {
                    $hasRunning = true;
                    continue;
                }
                $finished[$symbol] = $process;
            }

            foreach ($finished as $symbol => $process) {
                $workerDuration = microtime(true) - ($workerStartTimes[$symbol] ?? microtime(true));
                unset($active[$symbol], $workerStartTimes[$symbol]);

                if ($process->isSuccessful()) {
                    $rawOutput = trim($process->getOutput());
                    if ($rawOutput === '') {
                        $errors[] = sprintf('Worker %s: empty output', $symbol);
                        continue;
                    }

                    try {
                        $payload = json_decode($rawOutput, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $exception) {
                        // Extraire le JSON même s'il y a des warnings PHP avant
                        $jsonStart = strpos($rawOutput, '{');
                        if ($jsonStart === false) {
                            $errors[] = sprintf('Worker %s: invalid JSON output (%s)', $symbol, $exception->getMessage());
                            continue;
                        }

                        $candidate = substr($rawOutput, $jsonStart);
                        $jsonEnd = strrpos($candidate, '}');
                        if ($jsonEnd === false) {
                            $errors[] = sprintf('Worker %s: invalid JSON output (%s)', $symbol, $exception->getMessage());
                            continue;
                        }

                        $candidate = substr($candidate, 0, $jsonEnd + 1);

                        try {
                            $payload = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $exception2) {
                            $errors[] = sprintf('Worker %s: invalid JSON output (%s)', $symbol, $exception2->getMessage());
                            continue;
                        }
                    }

                    $final = $payload['final'] ?? null;
                    $workerResults = is_array($final) ? ($final['results'] ?? []) : [];
                    if (empty($workerResults)) {
                        $errors[] = sprintf('Worker %s: no results returned', $symbol);
                        continue;
                    }

                    $hasSymbolResults = false;
                    foreach ($workerResults as $resultSymbol => $info) {
                        // Ignorer l'entrée synthétique "FINAL" renvoyée par le worker
                        if ($resultSymbol === 'FINAL') {
                            continue;
                        }
                        if (is_string($resultSymbol)) {
                            $results[$resultSymbol] = $info;
                            $hasSymbolResults = true;
                        }
                    }

                    if (!$hasSymbolResults) {
                        $errors[] = sprintf('Worker %s: no symbol results returned', $symbol);
                        continue;
                    }

                    $this->mtfLogger->debug('[MTF Runner] Worker completed', [
                        'run_id' => $runId,
                        'symbol' => $symbol,
                        'duration' => round($workerDuration, 3),
                    ]);
                } else {
                    $stderr = trim($process->getErrorOutput());
                    $stdout = trim($process->getOutput());
                    $msg = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'unknown error');
                    $errors[] = sprintf('Worker %s: %s', $symbol, $msg);
                    $this->mtfLogger->warning('[MTF Runner] Worker failed', [
                        'run_id' => $runId,
                        'symbol' => $symbol,
                        'error' => $msg,
                    ]);
                }
            }

            // Démarrer de nouveaux workers si on a de la place et des symboles en attente
            while (count($active) < $request->workers && !$queue->isEmpty()) {
                $symbol = $queue->dequeue();
                $workerStart = microtime(true);
                $process = new Process(
                    $this->buildWorkerCommand($symbol, $options),
                    $this->projectDir,
                    ['APP_DEBUG' => '0']
                );
                $process->start();
                $workerStartTimes[$symbol] = $workerStart;
                $active[$symbol] = $process;
                $this->mtfLogger->debug('[MTF Runner] Worker started', [
                    'run_id' => $runId,
                    'symbol' => $symbol,
                ]);
            }

            $pollDuration = microtime(true) - $pollStart;
            $pollingTime += $pollDuration;
            $pollingCount++;

            if ($hasRunning) {
                usleep(100_000); // 100ms
            }
        }

        $processed = count($results);
        $successCount = count(array_filter($results, function ($r) {
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['SUCCESS', 'COMPLETED', 'READY'], true);
        }));
        $failedCount = count(array_filter($results, function ($r) {
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['ERROR', 'INVALID'], true);
        }));
        $skippedCount = count(array_filter($results, function ($r) {
            $td = $r['trading_decision']['status'] ?? null;
            if (is_string($td) && strtolower($td) === 'skipped') {
                return true;
            }
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['SKIPPED', 'GRACE_WINDOW'], true);
        }));

        $totalExecutionTime = microtime(true) - $startedAt;
        $summary = [
            'run_id' => $runId,
            'execution_time_seconds' => round($totalExecutionTime, 3),
            'symbols_requested' => count($symbols),
            'symbols_processed' => $processed,
            'symbols_successful' => $successCount,
            'symbols_failed' => $failedCount,
            'symbols_skipped' => $skippedCount,
            'success_rate' => $processed > 0 ? round(($successCount / $processed) * 100, 2) : 0.0,
            'dry_run' => $request->dryRun,
            'force_run' => $request->forceRun,
            'current_tf' => $request->currentTf,
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => empty($errors) ? 'completed' : 'completed_with_errors',
            'polling_time_seconds' => round($pollingTime, 3),
            'polling_count' => $pollingCount,
        ];

        $this->mtfLogger->info('[MTF Runner] Parallel execution completed', [
            'run_id' => $runId,
            'symbols_processed' => $processed,
            'execution_time' => round($totalExecutionTime, 3),
            'polling_time' => round($pollingTime, 3),
        ]);

        return [
            'summary' => $summary,
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Construit la commande pour un worker Process
     *
     * @param string $symbol Symbole à traiter
     * @param array{
     *     dry_run: bool,
     *     force_run: bool,
     *     current_tf: ?string,
     *     force_timeframe_check: bool,
     *     skip_context: bool,
     *     lock_per_symbol: bool,
     *     skip_open_filter: bool,
     *     user_id: ?string,
     *     ip_address: ?string,
     *     exchange: string,
     *     market_type: string,
     *     profile: ?string,
     *     validation_mode: ?string,
     * } $options
     * @return string[]
     */
    private function buildWorkerCommand(string $symbol, array $options): array
    {
        // Toujours utiliser 'php' directement pour éviter d'utiliser php-fpm dans Docker
        // PHP_BINARY peut pointer vers php-fpm dans un environnement FPM, ce qui ne fonctionne pas pour les commandes CLI
        $command = [
            'php',
            '-d',
            'memory_limit=512M',
            $this->projectDir . '/bin/console',
            'mtf:run-worker',
            '--symbols=' . $symbol,
            '--dry-run=' . ($options['dry_run'] ? '1' : '0'),
            '--skip-open-filter', // Le filtrage est fait en amont
        ];

        if ($options['force_run']) {
            $command[] = '--force-run';
        }
        if (!empty($options['current_tf'])) {
            $command[] = '--tf=' . $options['current_tf'];
        }
        if ($options['force_timeframe_check']) {
            $command[] = '--force-timeframe-check';
        }
        if ($options['skip_context']) {
            $command[] = '--skip-context';
        }
        if ($options['lock_per_symbol']) {
            $command[] = '--lock-per-symbol';
        }
        if (!empty($options['user_id'])) {
            $command[] = '--user-id=' . $options['user_id'];
        }
        if (!empty($options['ip_address'])) {
            $command[] = '--ip-address=' . $options['ip_address'];
        }
        if (!empty($options['exchange'])) {
            $command[] = '--exchange=' . $options['exchange'];
        }
        if (!empty($options['market_type'])) {
            $command[] = '--market-type=' . $options['market_type'];
        }
        if (!empty($options['profile'])) {
            $command[] = '--trade-profile=' . $options['profile'];
        }
        if (!empty($options['validation_mode'])) {
            $command[] = '--validation-mode=' . $options['validation_mode'];
        }

        return $command;
    }

    /**
     * Traite le recalcul des TP/SL pour les positions avec exactement 1 ordre TP
     */
    public function processTpSlRecalculation(bool $dryRun, ?ExchangeContext $context = null): void
    {
        try {
            $provider = $this->mainProvider;
            if ($provider !== null && $context !== null) {
                $provider = $provider->forContext($context);
            }

            $accountProvider = $provider?->getAccountProvider();
            $orderProvider = $provider?->getOrderProvider();

            if ($accountProvider === null || $orderProvider === null) {
                $this->positionsLogger->warning('[MTF Runner] TP/SL recalculation skipped: missing providers');
                return;
            }

            if ($this->tpSlService === null) {
                $this->positionsLogger->warning('[MTF Runner] TP/SL recalculation skipped: TpSlTwoTargetsService not available');
                return;
            }

            // Charger la config des guards une seule fois pour toutes les positions
            $recalcConfig = $this->tradeEntryConfigProvider !== null && $this->tradeEntryModeContext !== null
                ? $this->tradeEntryConfigProvider
                    ->getConfigForMode($this->tradeEntryModeContext->resolve(null))
                    ->getTpSlRecalcConfig()
                : ['min_position_age_sec' => 0, 'tp_proximity_skip_pct' => 0.0, 'skip_if_tp_partially_filled' => false];

            // Récupérer toutes les positions ouvertes
            $openPositions = $accountProvider->getOpenPositions();
            $this->positionsLogger->info('[MTF Runner] TP/SL recalculation: checking positions', [
                'count' => count($openPositions),
                'dry_run' => $dryRun,
            ]);

            foreach ($openPositions as $position) {
                try {
                    $symbol = strtoupper($position->symbol);
                    $positionSide = $position->side;

                    // Convertir PositionSide en EntrySide
                    $entrySide = $positionSide === PositionSide::LONG
                        ? EntrySide::Long
                        : EntrySide::Short;

                    // Validation: s'assurer que le side est valide
                    if ($positionSide !== PositionSide::LONG && $positionSide !== PositionSide::SHORT) {
                        $this->positionsLogger->warning('[MTF Runner] TP/SL recalculation skipped: invalid position side', [
                            'symbol' => $symbol,
                            'side' => $positionSide->value ?? 'unknown',
                        ]);
                        continue;
                    }

                    // Récupérer les ordres ouverts pour ce symbole
                    $openOrders = $orderProvider->getOpenOrders($symbol);

                    // Déterminer le côté de fermeture
                    $closeSide = $entrySide === EntrySide::Long ? OrderSide::SELL : OrderSide::BUY;

                    // Filtrer les ordres de fermeture
                    $closingOrders = array_filter($openOrders, fn(OrderDto $o) => $o->side === $closeSide);

                    if (empty($closingOrders)) {
                        continue; // Pas d'ordres de fermeture, passer
                    }

                    // Identifier les SL et TP
                    $entryPrice = (float)$position->entryPrice->__toString();
                    $slOrders = [];
                    $tpOrders = [];

                    foreach ($closingOrders as $order) {
                        if ($order->price === null) {
                            continue;
                        }

                        $orderPrice = (float)$order->price->__toString();

                        // Un SL est un ordre avec prix < entryPrice (long) ou prix > entryPrice (short)
                        $isSl = $entrySide === EntrySide::Long
                            ? ($orderPrice < $entryPrice)
                            : ($orderPrice > $entryPrice);

                        if ($isSl) {
                            $slOrders[] = $order;
                        } else {
                            $tpOrders[] = $order;
                        }
                    }

                    // Critère: recalculer seulement si exactement 1 ordre TP
                    if (count($tpOrders) !== 1) {
                        $this->positionsLogger->debug('[MTF Runner] TP/SL recalculation skipped', [
                            'symbol' => $symbol,
                            'tp_count' => count($tpOrders),
                            'reason' => count($tpOrders) === 0 ? 'no_tp_orders' : 'multiple_tp_orders',
                        ]);
                        continue;
                    }

                    // Guard 1 — Âge minimum de la position
                    $positionAgeSec = $this->clock->now()->getTimestamp() - $position->openedAt->getTimestamp();
                    if ($positionAgeSec < $recalcConfig['min_position_age_sec']) {
                        $this->positionsLogger->info('tp_sl_recalc_skipped', [
                            'symbol' => $symbol,
                            'reason' => 'position_too_young',
                            'age_sec' => $positionAgeSec,
                        ]);
                        continue;
                    }

                    // Guard 2 — TP trop proche du prix courant
                    $existingTp = reset($tpOrders) ?: null;
                    $currentPrice = (float)$position->markPrice->__toString();
                    if ($existingTp !== null && $recalcConfig['tp_proximity_skip_pct'] > 0.0 && $currentPrice > 0.0 && $existingTp->price !== null) {
                        $tpPrice = (float)$existingTp->price->__toString();
                        $proximity = abs($currentPrice - $tpPrice) / $currentPrice;
                        if ($proximity < $recalcConfig['tp_proximity_skip_pct']) {
                            $this->positionsLogger->info('tp_sl_recalc_skipped', [
                                'symbol' => $symbol,
                                'reason' => 'tp_too_close',
                                'proximity_ratio' => $proximity,
                            ]);
                            continue;
                        }
                    }

                    // Guard 3 — TP partiellement fillé
                    if ($recalcConfig['skip_if_tp_partially_filled'] && $existingTp !== null) {
                        if ($existingTp->filledQuantity->isGreaterThan(0)) {
                            $this->positionsLogger->info('tp_sl_recalc_skipped', [
                                'symbol' => $symbol,
                                'reason' => 'tp_partially_filled',
                                'filled_qty' => (float)$existingTp->filledQuantity->__toString(),
                            ]);
                            continue;
                        }
                    }

                    // Recalculer les TP/SL
                    $this->positionsLogger->info('[MTF Runner] TP/SL recalculation: processing', [
                        'symbol' => $symbol,
                        'side' => $entrySide->value,
                        'entry_price' => $entryPrice,
                        'size' => (float)$position->size->__toString(),
                        'dry_run' => $dryRun,
                    ]);

                    $tpSlRequest = new TpSlTwoTargetsRequest(
                        symbol: $symbol,
                        side: $entrySide,
                        entryPrice: $entryPrice,
                        size: (int)(float)$position->size->__toString(),
                        dryRun: $dryRun,
                        cancelExistingStopLossIfDifferent: true,
                        cancelExistingTakeProfits: true,
                    );

                    $result = $this->tpSlService->__invoke($tpSlRequest, 'mtf_runner_' . time());

                    $this->positionsLogger->info('[MTF Runner] TP/SL recalculation: completed', [
                        'symbol' => $symbol,
                        'sl' => $result['sl'],
                        'tp1' => $result['tp1'],
                        'tp2' => $result['tp2'],
                        'submitted_count' => count($result['submitted']),
                        'cancelled_count' => count($result['cancelled']),
                        'dry_run' => $dryRun,
                    ]);

                } catch (\Throwable $e) {
                    $this->logger->error('[MTF Runner] TP/SL recalculation failed for position', [
                        'symbol' => $position->symbol ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continuer avec les autres positions
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] TP/SL recalculation process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Ne pas faire échouer le run MTF complet
        }
    }

    /**
     * Détermine si le recalcul TP/SL doit être exécuté maintenant.
     *
     * Règle actuelle : exécuter uniquement lorsque la minute courante
     * est un multiple de 3 (0, 3, 6, ..., 57).
     */
    private function shouldRunTpSlNow(): bool
    {
        $minute = (int) $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('i');

        return $minute % 3 === 0;
    }

    /**
     * Crée le contexte d'échange depuis la requête
     */
    private function createContext(RunnerRequestDto $request): ExchangeContext
    {
        $exchange = $request->exchange ?? Exchange::BITMART;
        $marketType = $request->marketType ?? MarketType::PERPETUAL;

        return new ExchangeContext($exchange, $marketType);
    }

    /**
     * Indique si un instantané d'état ouvert orchestrateur est exploitable.
     * Un snapshot vide mais bien formé (open_positions/open_orders = []) reste une
     * source FIABLE : l'orchestrateur a bien interrogé l'exchange, rien n'était ouvert.
     * En revanche un snapshot mal formé (clés manquantes ou non-tableaux, ex: {}) n'est
     * PAS fiable : on exige les deux clés sous forme de tableaux pour que le garde
     * fail-closed en live ne soit pas contourné par un payload vide/incomplet.
     *
     * @param array{open_positions?: array<int,mixed>, open_orders?: array<int,mixed>}|null $snapshot
     */
    private function snapshotIsUsable(?array $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        return is_array($snapshot['open_positions'] ?? null)
            && is_array($snapshot['open_orders'] ?? null);
    }

    /**
     * Construit un résultat de run rejeté (fail-closed) sans toucher l'exchange.
     *
     * @return array{
     *     summary: array<string,mixed>,
     *     results: array<string,mixed>,
     *     errors: array<int,string>,
     *     summary_by_tf: array<string,mixed>,
     *     rejected_by: array<string,mixed>,
     *     last_validated: array<string,mixed>,
     *     orders_placed: array<string,mixed>,
     *     performance: array<string,mixed>
     * }
     */
    private function buildRejectedRun(string $runId, string $message, string $reason, int $symbolsRequested): array
    {
        return [
            'summary' => [
                'run_id' => $runId,
                'status' => 'rejected',
                'reason' => $reason,
                'message' => $message,
                'symbols_requested' => $symbolsRequested,
                'symbols_processed' => 0,
                'timestamp' => date('Y-m-d H:i:s'),
            ],
            'results' => [],
            'errors' => [$message],
            'summary_by_tf' => [],
            'rejected_by' => [],
            'last_validated' => [],
            'orders_placed' => [
                'count' => ['total' => 0, 'submitted' => 0, 'simulated' => 0],
                'orders' => [],
            ],
            'performance' => [],
        ];
    }

}
