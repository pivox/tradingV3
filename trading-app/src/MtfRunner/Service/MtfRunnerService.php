<?php

declare(strict_types=1);

namespace App\MtfRunner\Service;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
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
use App\Provider\Context\ExchangeContext;
use App\Provider\Repository\ContractRepository;
use App\Repository\PositionRepository;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Service\TpSlTwoTargetsService;
use App\TradeEntry\Types\Side as EntrySide;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

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
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ?TpSlTwoTargetsService $tpSlService = null, // PHP 8.1: optional params moved after required (voir n° de commit)
    ) {
    }

    /**
     * Exécute un cycle MTF complet avec toutes les responsabilités
     * 
     * @return array{summary: array, results: array, errors: array}
     */
    public function run(RunnerRequestDto $request): array
    {
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
            $symbols = $this->resolveSymbols($request->symbols);

            // 2. Créer le contexte
            $context = $this->createContext($request);

            // 3. Synchroniser les tables depuis l'exchange (si demandé)
            if ($request->syncTables) {
                $this->syncTables($context);
            }

            // 4. Filtrer les symboles avec ordres/positions ouverts
            $excludedSymbols = [];
            if (!$request->skipOpenStateFilter) {
                $symbols = $this->filterSymbolsWithOpenOrdersOrPositions(
                    $symbols,
                    $runId,
                    $context,
                    $excludedSymbols
                );
            }

            // 5. Gérer les locks
            $this->manageLocks($runId, $request->lockPerSymbol, $symbols);

            // 6. Gérer les switches
            $this->manageSwitches($symbols, $excludedSymbols, $runId);

            // 7. Exécuter MTF (séquentiel ou parallèle)
            $result = $request->workers > 1
                ? $this->runParallel($symbols, $request, $context, $runId)
                : $this->runSequential($symbols, $request, $context);

            // 8. Mettre à jour les switches pour les symboles exclus (après traitement)
            if (!empty($excludedSymbols)) {
                $this->updateSwitchesForExcludedSymbols($excludedSymbols, $runId);
            }

            // 9. Recalcul TP/SL (si demandé)
            if ($request->processTpSl) {
                try {
                    $this->processTpSlRecalculation($request->dryRun, $context);
                } catch (\Throwable $e) {
                    $this->logger->warning('[MTF Runner] TP/SL recalculation failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $executionTime = microtime(true) - $startTime;
            $this->logger->info('[MTF Runner] Execution completed', [
                'run_id' => $runId,
                'execution_time' => round($executionTime, 3),
                'symbols_processed' => count($result['results'] ?? []),
            ]);

            return $result;

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
            // Le lock sera géré par le MtfRunService si nécessaire
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

            if (!is_string($symbol) || $symbol === '' || !is_array($result)) {
                continue;
            }

            $resultsMap[$symbol] = $result;
        }

        return [
            'summary' => $response->toArray(),
            'results' => $resultsMap,
            'errors' => $response->errors,
        ];
    }

    /**
     * Exécute MTF en mode parallèle
     * 
     * Note: Pour l'instant, on retourne une structure similaire au séquentiel
     * L'implémentation parallèle complète sera ajoutée plus tard
     */
    private function runParallel(
        array $symbols,
        RunnerRequestDto $request,
        ExchangeContext $context,
        string $runId
    ): array {
        // Pour l'instant, on utilise le mode séquentiel même si workers > 1
        // L'implémentation parallèle complète sera ajoutée plus tard
        $this->logger->info('[MTF Runner] Parallel execution not yet implemented, using sequential', [
            'run_id' => $runId,
            'workers' => $request->workers,
        ]);

        return $this->runSequential($symbols, $request, $context);
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
}

