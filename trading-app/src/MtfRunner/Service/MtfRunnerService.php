<?php

declare(strict_types=1);

namespace App\MtfRunner\Service;

use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\PositionSide;
use App\Entity\Position;
use App\MtfRunner\Dto\MtfRunnerRequestDto as RunnerRequestDto;
use App\MtfRunner\Service\FuturesOrderSyncService;
use App\MtfValidator\Repository\MtfLockRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\Provider\Context\ExchangeContext;
use App\Provider\Repository\ContractRepository;
use App\Repository\PositionRepository;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Service\TpSlTwoTargetsService;
use App\TradeEntry\Types\Side as EntrySide;
use App\MtfValidator\Service\PerformanceProfiler;
use App\MtfValidator\Service\Helper\OrdersExtractor;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly ContractRepository $contractRepository,
        private readonly PositionRepository $positionRepository,
        private readonly MtfLockRepository $mtfLockRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly FuturesOrderSyncService $futuresOrderSyncService,
        private readonly MtfValidatorInterface $mtfValidator,
        private readonly MainProviderInterface $mainProvider,
        private readonly IndicatorProviderInterface $indicatorProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?TpSlTwoTargetsService $tpSlService = null,
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

        $this->logger->info('[MTF Runner] Starting execution', [
            'run_id' => $runId,
            'symbols_count' => count($request->symbols),
            'dry_run' => $request->dryRun,
            'workers' => $request->workers,
        ]);

        try {
            // 1. Résoudre les symboles
            $resolveStart = microtime(true);
            $symbols = $this->resolveSymbols($request->symbols);
            $profiler->increment('runner', 'resolve_symbols', microtime(true) - $resolveStart);

            // 2. Créer le contexte
            $context = $this->createContext($request);

            // 3. Synchroniser les tables depuis l'exchange (si demandé)
            if ($request->syncTables) {
                $syncStart = microtime(true);
                $this->syncTables($context);
                $profiler->increment('runner', 'sync_tables', microtime(true) - $syncStart);
            }

            // 4. Filtrer les symboles avec ordres/positions ouverts
            $excludedSymbols = [];
            if (!$request->skipOpenStateFilter) {
                $filterStart = microtime(true);
                $symbols = $this->filterSymbolsWithOpenOrdersOrPositions(
                    $symbols,
                    $runId,
                    $context,
                    $excludedSymbols
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

            $this->persistIndicatorSnapshots($result['results'] ?? [], $request);

            // 8. Mettre à jour les switches pour les symboles exclus (après traitement)
            if (!empty($excludedSymbols)) {
                $this->updateSwitchesForExcludedSymbols($excludedSymbols, $runId);
            }

            // 9. Recalcul TP/SL (si demandé)
            if ($request->processTpSl) {
                try {
                    $tpSlStart = microtime(true);
                    $this->processTpSlRecalculation($request->dryRun, $context);
                    $profiler->increment('runner', 'tp_sl_recalculation', microtime(true) - $tpSlStart);
                } catch (\Throwable $e) {
                    $this->logger->warning('[MTF Runner] TP/SL recalculation failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 10. Post-processing : enrichir les résultats
            $postProcessStart = microtime(true);
            $results = $result['results'] ?? [];
            $enriched = $this->enrichResults($results);
            $profiler->increment('runner', 'post_processing', microtime(true) - $postProcessStart);

            $executionTime = microtime(true) - $startTime;
            $performanceReport = $profiler->getReport();

            $this->logger->info('[MTF Runner] Execution completed', [
                'run_id' => $runId,
                'execution_time' => round($executionTime, 3),
                'symbols_processed' => count($results),
                'performance' => $performanceReport,
            ]);

            return [
                'summary' => $result['summary'] ?? [],
                'results' => $results,
                'errors' => $result['errors'] ?? [],
                'summary_by_tf' => $enriched['summary_by_tf'],
                'rejected_by' => $enriched['rejected_by'],
                'last_validated' => $enriched['last_validated'],
                'orders_placed' => $enriched['orders_placed'],
                'performance' => $performanceReport,
            ];

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
    public function resolveSymbols(array $inputSymbols): array
    {
        $symbols = [];
        
        // Normaliser les symboles fournis
        foreach ($inputSymbols as $symbol) {
            if (is_string($symbol) && $symbol !== '') {
                $symbols[] = strtoupper(trim($symbol));
            }
        }

        $symbols = array_values(array_unique(array_filter($symbols)));

        // Si aucun symbole fourni, récupérer depuis la base de données
        if (empty($symbols)) {
            try {
                $fetched = $this->contractRepository->allActiveSymbolNames();
                if (!empty($fetched)) {
                    $symbols = array_values(array_unique(array_map('strval', $fetched)));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MTF Runner] Failed to load active symbols, using fallback', [
                    'error' => $e->getMessage(),
                ]);
                $symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
            }
        }

        // Consommer les symboles depuis la queue des switches
        $queuedSymbols = $this->consumeSymbolsFromSwitchQueue();
        if (!empty($queuedSymbols)) {
            $symbols = array_values(array_unique(array_merge($symbols, $queuedSymbols)));
            $this->logger->info('[MTF Runner] Added symbols from switch queue', [
                'count' => count($queuedSymbols),
            ]);
        }

        // Fallback si toujours vide
        if (empty($symbols)) {
            $symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
        }

        return $symbols;
    }

    /**
     * Filtre les symboles ayant des ordres ou positions ouverts
     * 
     * @param array<string> $symbols Liste des symboles à filtrer
     * @param string $runId ID du run pour les logs
     * @param array<string> $excludedSymbols Référence pour retourner les symboles exclus
     * @param ExchangeContext $context Contexte d'échange
     * @return array<string> Liste des symboles à traiter (sans ceux exclus)
     */
    // PHP 8.1: paramètres requis avant optionnels (voir n° de commit)
    public function filterSymbolsWithOpenOrdersOrPositions(
        array $symbols,
        string $runId,
        ExchangeContext $context,
        array &$excludedSymbols = []
    ): array {
        $excludedSymbols = [];
        $provider = $this->mainProvider->forContext($context);

        if (empty($symbols) || (!$provider->getAccountProvider() && !$provider->getOrderProvider())) {
            return $symbols;
        }

        $symbolsToProcess = [];

        // Récupérer les symboles avec positions ouvertes depuis l'exchange
        $openPositionSymbols = [];
        $accountProvider = $provider->getAccountProvider();
        if ($accountProvider) {
            try {
                $openPositions = $accountProvider->getOpenPositions();
                $this->logger->info('[MTF Runner] Fetched open positions', [
                    'run_id' => $runId,
                    'count' => count($openPositions),
                ]);

                foreach ($openPositions as $position) {
                    $positionSymbol = strtoupper($position->symbol ?? '');
                    if ($positionSymbol !== '' && !in_array($positionSymbol, $openPositionSymbols, true)) {
                        $openPositionSymbols[] = $positionSymbol;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MTF Runner] Failed to fetch open positions from exchange', [
                    'run_id' => $runId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Récupérer les symboles avec ordres ouverts depuis l'exchange
        $openOrderSymbols = [];
        $orderProvider = $provider->getOrderProvider();
        if ($orderProvider) {
            try {
                $openOrders = $orderProvider->getOpenOrders();
                $this->logger->info('[MTF Runner] Fetched open orders', [
                    'run_id' => $runId,
                    'count' => count($openOrders),
                ]);

                foreach ($openOrders as $order) {
                    $orderSymbol = strtoupper($order->symbol ?? '');
                    if ($orderSymbol !== '' && !in_array($orderSymbol, $openOrderSymbols, true)) {
                        $openOrderSymbols[] = $orderSymbol;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MTF Runner] Failed to fetch open orders from exchange', [
                    'run_id' => $runId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Combiner les symboles à exclure
        $symbolsWithActivity = array_unique(array_merge($openPositionSymbols, $openOrderSymbols));

        // Réactiver les switches des symboles qui n'ont plus d'ordres/positions ouverts
        try {
            $reactivatedCount = $this->mtfSwitchRepository->reactivateSwitchesForInactiveSymbols($symbolsWithActivity);
            if ($reactivatedCount > 0) {
                $this->logger->info('[MTF Runner] Reactivated switches for inactive symbols', [
                    'run_id' => $runId,
                    'reactivated_count' => $reactivatedCount,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to reactivate switches for inactive symbols', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }

        // Filtrer les symboles
        foreach ($symbols as $symbol) {
            $symbolUpper = strtoupper($symbol);

            if (in_array($symbolUpper, $symbolsWithActivity, true)) {
                $excludedSymbols[] = $symbolUpper;
            } else {
                $symbolsToProcess[] = $symbol;
            }
        }

        if (!empty($excludedSymbols)) {
            $this->logger->info('[MTF Runner] Filtered symbols with open orders/positions', [
                'run_id' => $runId,
                'excluded_count' => count($excludedSymbols),
                'excluded_symbols' => array_slice($excludedSymbols, 0, 10),
                'remaining_count' => count($symbolsToProcess),
            ]);
        }

        return $symbolsToProcess;
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
                $this->logger->warning('[MTF Runner] Lock already exists', [
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
                    $this->logger->info('[MTF Runner] Symbol switch extended (was OFF)', [
                        'run_id' => $runId,
                        'symbol' => $symbolUpper,
                        'duration' => '1 minute',
                        'reason' => 'has_open_orders_or_positions',
                    ]);
                } else {
                    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, '5m');
                    $this->logger->info('[MTF Runner] Symbol switch disabled', [
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
    public function syncTables(ExchangeContext $context): void
    {
        try {
            $provider = $this->mainProvider->forContext($context);
            $accountProvider = $provider->getAccountProvider();
            $orderProvider = $provider->getOrderProvider();

            if (!$accountProvider && !$orderProvider) {
                $this->logger->warning('[MTF Runner] Cannot sync tables: missing providers');
                return;
            }

            // 1. Synchroniser les positions
            if ($accountProvider) {
                $this->syncPositions($accountProvider);
            }

            // 2. Synchroniser les ordres
            if ($orderProvider) {
                $this->syncOrders($orderProvider);
            }

        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to sync tables', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Synchronise les positions depuis l'exchange vers la table positions
     */
    private function syncPositions($accountProvider): void
    {
        try {
            $openPositions = $accountProvider->getOpenPositions();
            $this->logger->info('[MTF Runner] Syncing positions', [
                'count' => count($openPositions),
            ]);

            foreach ($openPositions as $positionDto) {
                try {
                    $symbol = strtoupper($positionDto->symbol);
                    $side = strtoupper($positionDto->side->value);

                    // Chercher ou créer la position
                    $position = $this->positionRepository->findOneBySymbolSide($symbol, $side);
                    if (!$position) {
                        $position = new Position($symbol, $side);
                    }

                    // Mettre à jour les données
                    $position->setSize($positionDto->size->__toString());
                    $position->setAvgEntryPrice($positionDto->entryPrice->__toString());
                    $position->setLeverage((int)$positionDto->leverage->__toString());
                    $position->setUnrealizedPnl($positionDto->unrealizedPnl->__toString());
                    $position->setStatus('OPEN');
                    $position->mergePayload([
                        'mark_price' => $positionDto->markPrice->__toString(),
                        'margin' => $positionDto->margin->__toString(),
                        'realized_pnl' => $positionDto->realizedPnl->__toString(),
                    ]);

                    $this->positionRepository->upsert($position);
                } catch (\Throwable $e) {
                    $this->logger->error('[MTF Runner] Failed to sync position', [
                        'symbol' => $positionDto->symbol ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to sync positions', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Synchronise les ordres depuis l'exchange vers futures_order et futures_order_trade
     */
    private function syncOrders($orderProvider): void
    {
        try {
            $openOrders = $orderProvider->getOpenOrders();
            // getOpenOrders() synchronise déjà les ordres via FuturesOrderSyncService
            $this->logger->info('[MTF Runner] Syncing orders via provider', [
                'count' => count($openOrders),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to sync orders', [
                'error' => $e->getMessage(),
            ]);
        }
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
        );

        $response = $this->mtfValidator->run($mtfRequest);

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
        ];

        $this->logger->info('[MTF Runner] Starting parallel execution', [
            'run_id' => $runId,
            'symbols_count' => count($symbols),
            'workers' => $request->workers,
        ]);

        while (!$queue->isEmpty() || $active !== []) {
            $pollStart = microtime(true);

            // Démarrer de nouveaux workers si on a de la place et des symboles en attente
            while (count($active) < $request->workers && !$queue->isEmpty()) {
                $symbol = $queue->dequeue();
                $workerStart = microtime(true);
                $process = new Process(
                    $this->buildWorkerCommand($symbol, $options),
                    $this->projectDir,
                    ['APP_DEBUG' => '1']
                );
                $process->start();
                $workerStartTimes[$symbol] = $workerStart;
                $active[] = ['symbol' => $symbol, 'process' => $process];
                $this->logger->debug('[MTF Runner] Worker started', [
                    'run_id' => $runId,
                    'symbol' => $symbol,
                ]);
            }

            // Vérifier les workers terminés
            $hasRunning = false;
            foreach ($active as $index => $worker) {
                $process = $worker['process'];
                if ($process->isRunning()) {
                    $hasRunning = true;
                    continue;
                }

                $symbol = $worker['symbol'];
                $workerDuration = microtime(true) - ($workerStartTimes[$symbol] ?? microtime(true));
                unset($active[$index], $workerStartTimes[$symbol]);
                $active = array_values($active);

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

                    foreach ($workerResults as $resultSymbol => $info) {
                        // Ignorer l'entrée synthétique "FINAL" renvoyée par le worker
                        if ($resultSymbol === 'FINAL') {
                            continue;
                        }
                        if (is_string($resultSymbol)) {
                            $results[$resultSymbol] = $info;
                        }
                    }

                    $this->logger->debug('[MTF Runner] Worker completed', [
                        'run_id' => $runId,
                        'symbol' => $symbol,
                        'duration' => round($workerDuration, 3),
                    ]);
                } else {
                    $stderr = trim($process->getErrorOutput());
                    $stdout = trim($process->getOutput());
                    $msg = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'unknown error');
                    $errors[] = sprintf('Worker %s: %s', $symbol, $msg);
                    $this->logger->warning('[MTF Runner] Worker failed', [
                        'run_id' => $runId,
                        'symbol' => $symbol,
                        'error' => $msg,
                    ]);
                }
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

        $this->logger->info('[MTF Runner] Parallel execution completed', [
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
     * } $options
     * @return string[]
     */
    private function buildWorkerCommand(string $symbol, array $options): array
    {
        // Toujours utiliser 'php' directement pour éviter d'utiliser php-fpm dans Docker
        // PHP_BINARY peut pointer vers php-fpm dans un environnement FPM, ce qui ne fonctionne pas pour les commandes CLI
        $command = [
            'php',
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
                $this->logger->warning('[MTF Runner] TP/SL recalculation skipped: missing providers');
                return;
            }

            if ($this->tpSlService === null) {
                $this->logger->warning('[MTF Runner] TP/SL recalculation skipped: TpSlTwoTargetsService not available');
                return;
            }

            // Récupérer toutes les positions ouvertes
            $openPositions = $accountProvider->getOpenPositions();
            $this->logger->info('[MTF Runner] TP/SL recalculation: checking positions', [
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
                        $this->logger->warning('[MTF Runner] TP/SL recalculation skipped: invalid position side', [
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
                        $this->logger->debug('[MTF Runner] TP/SL recalculation skipped', [
                            'symbol' => $symbol,
                            'tp_count' => count($tpOrders),
                            'reason' => count($tpOrders) === 0 ? 'no_tp_orders' : 'multiple_tp_orders',
                        ]);
                        continue;
                    }

                    // Recalculer les TP/SL
                    $this->logger->info('[MTF Runner] TP/SL recalculation: processing', [
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

                    $this->logger->info('[MTF Runner] TP/SL recalculation: completed', [
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
     * @param array<string,mixed> $results
     */
    private function persistIndicatorSnapshots(array $results, RunnerRequestDto $request): void
    {
        $timeframes = $this->resolvePersistenceTimeframes($request);
        if ($timeframes === []) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($results as $symbol => $_) {
            if (!is_string($symbol) || $symbol === '' || strtoupper($symbol) === 'FINAL') {
                continue;
            }

            try {
                $this->indicatorProvider->getIndicatorsForSymbolAndTimeframes($symbol, $timeframes, $now);
            } catch (\Throwable $e) {
                $this->logger->debug('[MTF Runner] Failed to persist indicator snapshots', [
                    'symbol' => $symbol,
                    'timeframes' => $timeframes,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return string[]
     */
    private function resolvePersistenceTimeframes(RunnerRequestDto $request): array
    {
        if (is_string($request->currentTf) && $request->currentTf !== '') {
            return [$request->currentTf];
        }

        return ['4h', '1h', '15m', '5m', '1m'];
    }

    /**
     * Consomme les symboles depuis la queue des switches
     * 
     * @return array<string>
     */
    private function consumeSymbolsFromSwitchQueue(): array
    {
        try {
            return $this->mtfSwitchRepository->consumeSymbolsWithFutureExpiration();
        } catch (\Throwable $e) {
            $this->logger->warning('[MTF Runner] Failed to consume symbols from switch queue', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
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
     * Enrichit les résultats avec summary_by_tf, rejected_by, last_validated, orders_placed
     * 
     * @param array<string, array<string, mixed>> $results
     * @return array{
     *     summary_by_tf: array<string, array<string>>,
     *     rejected_by: array<string>,
     *     last_validated: array<int, array{symbol: string, side: mixed, timeframe: string|null}>,
     *     orders_placed: array{count: array{total: int, submitted: int, simulated: int}, orders: array}
     * }
     */
    private function enrichResults(array $results): array
    {
        // 1. Calculer summary_by_tf
        $summaryByTfVrac = $this->buildSummaryByTimeframe($results);
        $summaryByTf = [];
        foreach (['1m', '5m', '15m', '1h', '4h'] as $tf) {
            $summaryByTf[$tf] = $summaryByTfVrac[$tf] ?? [];
        }

        // 2. Extraire rejected_by et last_validated
        $rejectedBy = [];
        $lastValidated = [];

        foreach ($results as $symbol => $symbolResult) {
            if ($symbol === 'FINAL' || !is_string($symbol) || $symbol === '') {
                continue;
            }

            if (!is_array($symbolResult)) {
                continue;
            }

            $resultStatus = strtoupper((string)($symbolResult['status'] ?? ''));

            if (!in_array($resultStatus, ['SUCCESS', 'COMPLETED', 'READY'], true)) {
                $rejectedBy[] = $symbol;
            }

            if ($resultStatus === 'SUCCESS' || $resultStatus === 'COMPLETED') {
                $executionTf = $symbolResult['execution_tf'] ?? null;
                $signalSide = $symbolResult['signal_side'] ?? null;

                $timeframe = $this->getPreviousTimeframe($executionTf);

                $lastValidated[] = [
                    'symbol' => $symbol,
                    'side' => $signalSide,
                    'timeframe' => $timeframe,
                ];
            }
        }

        sort($rejectedBy);
        usort($lastValidated, function ($a, $b) {
            return strcmp($a['symbol'] ?? '', $b['symbol'] ?? '');
        });

        // 3. Extraire orders_placed
        $ordersPlaced = OrdersExtractor::extractPlacedOrders($results);
        $ordersCount = OrdersExtractor::countOrdersByStatus($results);

        return [
            'summary_by_tf' => $summaryByTf,
            'rejected_by' => $rejectedBy,
            'last_validated' => $lastValidated,
            'orders_placed' => [
                'count' => $ordersCount,
                'orders' => $ordersPlaced,
            ],
        ];
    }

    /**
     * Construit un résumé groupé par dernier timeframe atteint
     * 
     * @param array<string, array<string, mixed>> $results
     * @return array<string, array<string>>
     */
    private function buildSummaryByTimeframe(array $results): array
    {
        $groups = [];
        foreach ($results as $symbol => $info) {
            if (!is_array($info)) {
                continue;
            }
            $lastTf = $info['blocking_tf'] ?? $info['failed_timeframe'] ?? ($info['execution_tf'] ?? null);
            $key = is_string($lastTf) && $lastTf !== '' ? $lastTf : 'N/A';
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = (string)$symbol;
        }

        // Trier les TF de 4h -> 1m, puis N/A
        $order = ['4h' => 5, '1h' => 4, '15m' => 3, '5m' => 2, '1m' => 1, 'N/A' => 0];
        uksort($groups, function($a, $b) use ($order) {
            return ($order[$b] ?? 0) - ($order[$a] ?? 0);
        });

        return $groups;
    }

    /**
     * Calcule le timeframe précédent (tf-1) pour un timeframe donné.
     * Retourne 'READY' pour '1m', null pour les timeframes non reconnus ou manquants.
     *
     * Mapping :
     * - '15m' → '1h'
     * - '5m' → '15m'
     * - '1m' → 'READY'
     * - '1h' → '4h'
     * - '4h' → null (pas de timeframe supérieur)
     *
     * @param string|null $timeframe Le timeframe d'exécution
     * @return string|null Le timeframe précédent ou 'READY' pour 1m
     */
    private function getPreviousTimeframe(?string $timeframe): ?string
    {
        if ($timeframe === null || $timeframe === '') {
            return null;
        }

        $normalized = strtolower(trim($timeframe));

        return match ($normalized) {
            '15m' => '1h',
            '5m' => '15m',
            '1m' => 'READY',
            '1h' => '4h',
            '4h' => null,
            default => null,
        };
    }
}
