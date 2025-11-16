<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller;

use App\Contract\Provider\MainProviderInterface;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\MtfValidator\Service\MtfService;
use App\MtfValidator\Service\MtfRunService;
use App\MtfValidator\Service\PerformanceProfiler;
use App\MtfValidator\Service\Helper\OrdersExtractor;
use App\Provider\Repository\ContractRepository;
use App\Provider\Repository\KlineRepository;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\MtfValidator\Repository\MtfLockRepository;
use App\Repository\MtfStateRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\MtfRunner\Service\MtfRunnerService;
use Ramsey\Uuid\Uuid;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;

#[Route('', name: 'mtf_')]
class MtfController extends AbstractController
{
    public function __construct(
        private readonly MtfService $mtfService,
        private readonly KlineRepository $klineRepository,
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfAuditRepository $mtfAuditRepository,
        private readonly MtfLockRepository $mtfLockRepository,
        private readonly LoggerInterface $logger,
        private readonly MtfRunService $mtfRunService,
        private readonly MtfRunnerService $mtfRunnerService,
        private readonly ClockInterface $clock,
        private readonly ContractRepository $contractRepository,
        private readonly MainProviderInterface $mainProvider,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?\App\MtfValidator\Service\Persistence\RunSinkInterface $runSink = null,
    ) {
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function getStatus(): JsonResponse
    {
        try {
            $workflowStatus = 'Temporal service not available';

            return $this->json([
                'status' => 'success',
                'data' => [
                    'workflow' => $workflowStatus,
                    'global_switch' => 'Database connection issue - using default',
                    'symbols_count' => 0,
                    'order_plans_count' => 0,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'message' => 'MTF endpoint is working! Database connection needs to be fixed.'
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to get status', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/lock/status', name: 'lock_status', methods: ['GET'])]
    public function getLockStatus(): JsonResponse
    {
        try {
            $lockKey = 'mtf_execution';
            $lockInfo = $this->mtfLockRepository->getLockInfo($lockKey);
            $activeLocks = $this->mtfLockRepository->getActiveLocks();

            return $this->json([
                'status' => 'success',
                'data' => [
                    'mtf_execution_lock' => $lockInfo,
                    'all_active_locks' => $activeLocks,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to get lock status', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/lock/force-release', name: 'lock_force_release', methods: ['POST'])]
    public function forceReleaseLock(Request $request): JsonResponse
    {
        try {
            $lockKey = $request->request->get('lock_key', 'mtf_execution');
            $reason = $request->request->get('reason', 'Manual force release');

            $this->logger->warning('[MTF Controller] Force releasing lock', [
                'lock_key' => $lockKey,
                'reason' => $reason
            ]);

            $released = $this->mtfLockRepository->forceReleaseLock($lockKey);

            if ($released) {
                return $this->json([
                    'status' => 'success',
                    'message' => 'Lock force released successfully',
                    'data' => [
                        'lock_key' => $lockKey,
                        'reason' => $reason,
                        'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                return $this->json([
                    'status' => 'error',
                    'message' => 'Lock not found or already released',
                    'data' => [
                        'lock_key' => $lockKey
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to force release lock', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/lock/cleanup', name: 'lock_cleanup', methods: ['POST'])]
    public function cleanupExpiredLocks(): JsonResponse
    {
        try {
            $cleanedCount = $this->mtfLockRepository->cleanupExpiredLocks();

            $this->logger->info('[MTF Controller] Cleaned up expired locks', [
                'cleaned_count' => $cleanedCount
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'Expired locks cleaned up',
                'data' => [
                    'cleaned_count' => $cleanedCount,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to cleanup expired locks', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function startWorkflow(): JsonResponse
    {
        try {
            $workflowId = 'Temporal service not available';

            return $this->json([
                'status' => 'success',
                'message' => 'MTF workflow started',
                'data' => [
                    'workflow_id' => $workflowId,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to start workflow', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }



    #[Route('/switches', name: 'switches', methods: ['GET'])]
    public function getSwitches(): JsonResponse
    {
        try {
            $switches = $this->mtfSwitchRepository->findAll();
            $switchesData = [];

            foreach ($switches as $switch) {
                $switchesData[] = [
                    'id' => $switch->getId(),
                    'key' => $switch->getSwitchKey(),
                    'is_on' => $switch->isOn(),
                    'description' => $switch->getDescription(),
                    'updated_at' => $switch->getUpdatedAt()->format('Y-m-d H:i:s')
                ];
            }

            return $this->json([
                'status' => 'success',
                'data' => $switchesData
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to get switches', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/switches/{id}/toggle', name: 'toggle_switch', methods: ['POST'])]
    public function toggleSwitch(int $id): JsonResponse
    {
        try {
            $switch = $this->mtfSwitchRepository->find($id);
            if (!$switch) {
                return $this->json([
                    'status' => 'error',
                    'message' => 'Switch not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $switch->setIsOn(!$switch->isOn());
            $this->mtfSwitchRepository->getEntityManager()->flush();

            return $this->json([
                'status' => 'success',
                'message' => 'Switch toggled',
                'data' => [
                    'id' => $switch->getId(),
                    'key' => $switch->getSwitchKey(),
                    'is_on' => $switch->isOn(),
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to toggle switch', [
                'switch_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/states', name: 'states', methods: ['GET'])]
    public function getStates(): JsonResponse
    {
        try {
            $states = $this->mtfStateRepository->findAll();
            $statesData = [];

            foreach ($states as $state) {
                $statesData[] = [
                    'id' => $state->getId(),
                    'symbol' => $state->getSymbol(),
                    'k4h_time' => $state->getK4hTime()?->format('Y-m-d H:i:s'),
                    'k1h_time' => $state->getK1hTime()?->format('Y-m-d H:i:s'),
                    'k15m_time' => $state->getK15mTime()?->format('Y-m-d H:i:s'),
                    'sides' => $state->getSides(),
                    'updated_at' => $state->getUpdatedAt()->format('Y-m-d H:i:s')
                ];
            }

            return $this->json([
                'status' => 'success',
                'data' => $statesData
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to get states', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/audit', name: 'audit', methods: ['GET'])]
    public function getAudit(Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->query->get('limit', 100);
            $symbol = $request->query->get('symbol');
            $step = $request->query->get('step');

            $queryBuilder = $this->mtfAuditRepository->createQueryBuilder('a')
                ->orderBy('a.createdAt', 'DESC')
                ->setMaxResults($limit);

            if ($symbol) {
                $queryBuilder->andWhere('a.symbol = :symbol')
                    ->setParameter('symbol', $symbol);
            }

            if ($step) {
                $queryBuilder->andWhere('a.step = :step')
                    ->setParameter('step', $step);
            }

            $audits = $queryBuilder->getQuery()->getResult();
            $auditsData = [];

            foreach ($audits as $audit) {
                $auditsData[] = [
                    'id' => $audit->getId(),
                    'symbol' => $audit->getSymbol(),
                    'run_id' => $audit->getRunId()->toString(),
                    'step' => $audit->getStep(),
                    'timeframe' => $audit->getTimeframe()?->value,
                    'cause' => $audit->getCause(),
                    'details' => $audit->getDetails(),
                    'created_at' => $audit->getCreatedAt()->format('Y-m-d H:i:s')
                ];
            }

            return $this->json([
                'status' => 'success',
                'data' => $auditsData
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to get audit', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/execute-cycle', name: 'execute_cycle', methods: ['POST'])]
    public function executeCycle(): JsonResponse
    {
        try {
            $runId = \Ramsey\Uuid\Uuid::uuid4();
            $result = $this->mtfService->executeMtfCycle($runId);

            return $this->json([
                'status' => 'success',
                'message' => 'MTF cycle executed',
                'data' => [
                    'run_id' => $runId->toString(),
                    'result' => $result,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to execute cycle', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/sync-contracts', name: 'sync_contracts', methods: ['POST', 'GET'])]
    public function syncContracts(Request $request): JsonResponse
    {
        try {
            // Parse JSON request body for POST or query parameters for GET
            $data = [];
            if ($request->getMethod() === 'POST') {
                $data = json_decode($request->getContent(), true) ?? [];
            } else {
                // For GET requests, get parameters from query string
                $data = $request->query->all();
            }

            // Parse symbols parameter (can be array or comma-separated string)
            $symbolsInput = $data['symbols'] ?? null;
            $symbols = null;

            if ($symbolsInput !== null) {
                if (is_string($symbolsInput)) {
                    $symbols = array_filter(array_map('trim', explode(',', $symbolsInput)));
                } elseif (is_array($symbolsInput)) {
                    $symbols = array_filter(array_map('trim', $symbolsInput));
                }
            }

            try {
                $context = $this->resolveExchangeContext($data);
            } catch (\InvalidArgumentException $e) {
                return $this->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->logger->info('[MTF Controller] Contract synchronization started', [
                'symbols' => $symbols,
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s'),
                'exchange' => $context->exchange->value,
                'market_type' => $context->marketType->value,
            ]);

            // Call the provider sync method
            $provider = $this->mainProvider->forContext($context)->getContractProvider();
            $result = $provider->syncContracts($symbols);

            $status = empty($result['errors']) ? 'success' : 'partial_success';

            return $this->json([
                'status' => $status,
                'message' => 'Contract synchronization completed',
                'data' => [
                    'upserted' => $result['upserted'],
                    'total_fetched' => $result['total_fetched'],
                    'symbols_requested' => $symbols ?? 'all',
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s'),
                ],
                'errors' => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Controller] Failed to synchronize contracts', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/run', name: 'run', methods: ['POST', 'GET'])]
    public function runMtfCycle(Request $request): JsonResponse
    {
        $profiler = new PerformanceProfiler();
        $apiStartTime = microtime(true);

        // Parse JSON request body for POST or query parameters for GET
        $data = [];
        if ($request->getMethod() === 'POST') {
            $data = json_decode($request->getContent(), true) ?? [];
        } else {
            // For GET requests, get parameters from query string
            $data = $request->query->all();
        }
        try {
            $context = $this->resolveExchangeContext($data);
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $symbolsInput = $data['symbols'] ?? [];
        $dryRun = filter_var($data['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $forceRun = filter_var($data['force_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $forceTimeframeCheck = filter_var($data['force_timeframe_check'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $currentTf = $data['current_tf'] ?? null;
        $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;
        $workers = max(1, (int)($data['workers'] ?? 1));

        // Normaliser les symboles fournis sans appliquer de fallback/queue
        $resolveStart = microtime(true);
        $symbols = [];
        if (is_string($symbolsInput)) {
            $symbols = array_filter(array_map('trim', explode(',', $symbolsInput)));
        } elseif (is_array($symbolsInput)) {
            foreach ($symbolsInput as $value) {
                if (is_string($value) && $value !== '') {
                    $symbols[] = trim($value);
                }
            }
        }
        $symbols = array_values(array_unique($symbols));
        $profiler->increment('controller', 'resolve_symbols', microtime(true) - $resolveStart);

        try {
            // Construire la requête Runner (le Runner gère la résolution des symboles, le filtrage open state, les switches et TP/SL)
            $runnerRequest = MtfRunnerRequestDto::fromArray([
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'force_timeframe_check' => $forceTimeframeCheck,
                'skip_context' => (bool)($data['skip_context'] ?? false),
                'lock_per_symbol' => (bool)($data['lock_per_symbol'] ?? false),
                'skip_open_state_filter' => (bool)($data['skip_open_state_filter'] ?? false),
                'user_id' => $data['user_id'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'exchange' => $context->exchange->value,
                'market_type' => $context->marketType->value,
                'workers' => $workers,
                'sync_tables' => filter_var($data['sync_tables'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'process_tp_sl' => filter_var($data['process_tp_sl'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ]);

            $runnerStart = microtime(true);
            $result = $this->mtfRunnerService->run($runnerRequest);
            $profiler->increment('controller', 'runner_run', microtime(true) - $runnerStart);

            $results = $result['results'] ?? [];
            $errors = $result['errors'] ?? [];
            $runSummary = $result['summary'] ?? [];

            $status = 'success';
            if (!empty($errors)) {
                $status = 'partial_success';
            }
            if (is_array($runSummary) && isset($runSummary['status']) && is_string($runSummary['status'])) {
                // Garder le statut métier détaillé retourné par le Runner si disponible
                $status = $runSummary['status'];
            }

            // Calculer un résumé par TF à partir des résultats (blocking_tf > execution_tf > N/A)
            $summaryByTfVrac = $this->buildSummaryByTimeframe($results);

            $summaryByTf = [];
            foreach (['1m', '5m', '15m', '1h', '4h'] as $tf) {
                $summaryByTf[$tf] = $summaryByTfVrac[$tf] ?? [];
            }

            // Extraire les symboles rejetés (tous les statuts non-SUCCESS)
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

            $apiTotalTime = microtime(true) - $apiStartTime;
            $performanceReport = $profiler->getReport();

            $ordersPlaced = OrdersExtractor::extractPlacedOrders($results);
            $ordersCount = OrdersExtractor::countOrdersByStatus($results);

            $this->logger->info('[MTF Controller] Performance Analysis', [
                'total_api_time' => round($apiTotalTime, 3),
                'symbols_count' => count($symbols),
                'workers' => $workers,
                'performance_report' => $performanceReport,
                'orders_placed_count' => $ordersCount,
            ]);

            try {
                if (is_array($runSummary) && isset($runSummary['run_id']) && is_string($runSummary['run_id'])) {
                    $this->runSink?->onMetrics($runSummary['run_id'], $performanceReport);
                }
            } catch (\Throwable) {
            }

            return $this->json([
                'status' => $status,
                'message' => 'MTF run completed',
                'data' => [
                    'run' => $runSummary,
                    'symbols' => $results,
                    'errors' => $errors,
                    'workers' => $workers,
                    'summary_by_tf' => $summaryByTf,
                    'rejected_by' => $rejectedBy,
                    'last_validated' => $lastValidated,
                    'performance' => $performanceReport,
                    'orders_placed' => [
                        'count' => $ordersCount,
                        'orders' => $ordersPlaced,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Controller] Failed to run MTF cycle', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Construit un résumé groupé par dernier timeframe atteint
     * @param array<string, array<string, mixed>> $results
     * @return array<string, string[]>
     */
    private function buildSummaryByTimeframe(array $results): array
    {
        $groups = [];
        foreach ($results as $symbol => $info) {
            if (!is_array($info)) { continue; }
            $lastTf = $info['blocking_tf'] ?? $info['failed_timeframe'] ?? ($info['execution_tf'] ?? null); // Support transition
            $key = is_string($lastTf) && $lastTf !== '' ? $lastTf : 'N/A';
            if (!isset($groups[$key])) { $groups[$key] = []; }
            $groups[$key][] = (string)$symbol;
        }

        // Optionnel: trier les TF de 4h -> 1m, puis N/A
        $order = ['4h' => 5, '1h' => 4, '15m' => 3, '5m' => 2, '1m' => 1, 'N/A' => 0];
        uksort($groups, function($a, $b) use ($order) {
            return ($order[$b] ?? 0) - ($order[$a] ?? 0);
        });

        return $groups;
    }

    /**
     * @param mixed $symbolsInput
     * @return string[]
     */
    private function resolveSymbols(mixed $symbolsInput): array
    {
        $queuedSymbols = $this->consumeSymbolsFromSwitchQueue();
        $symbols = [];
        if (is_string($symbolsInput)) {
            $symbols = array_filter(array_map('trim', explode(',', $symbolsInput)));
        } elseif (is_array($symbolsInput)) {
            foreach ($symbolsInput as $value) {
                if (is_string($value) && $value !== '') {
                    $symbols[] = trim($value);
                }
            }
        }

        $symbols = array_values(array_unique(array_filter($symbols)));
        if ($symbols === []) {
            try {
                $fetched = $this->contractRepository->allActiveSymbolNames();
                if (!empty($fetched)) {
                    $symbols = array_values(array_unique(array_map('strval', $fetched)));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MTF Controller] Failed to load active symbols, using fallback', [
                    'error' => $e->getMessage(),
                ]);
                $symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
            }
        }

        if ($queuedSymbols !== []) {
            $symbols = array_values(array_unique(array_merge($symbols, $queuedSymbols)));
        }

        if ($symbols === []) {
            return ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
        }

        return $symbols;
    }

    /**
     * @return string[]
     */
    private function consumeSymbolsFromSwitchQueue(): array
    {
        try {
            $symbols = $this->mtfSwitchRepository->consumeSymbolsWithFutureExpiration();
            if ($symbols !== []) {
                $this->logger->info('[MTF Controller] Added symbols from switch queue', [
                    'count' => count($symbols),
                    'symbols' => array_slice($symbols, 0, 20),
                ]);
            }

            return $symbols;
        } catch (\Throwable $e) {
            $this->logger->warning('[MTF Controller] Failed to consume symbols from switch queue', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{summary: array, results: array, errors: array}
     */
    private function runSequential(
        array $symbols,
        bool $dryRun,
        bool $forceRun,
        ?string $currentTf,
        bool $forceTimeframeCheck,
        ExchangeContext $context
    ): array {
        $request = new MtfRunRequestDto(
            symbols: $symbols,
            dryRun: $dryRun,
            forceRun: $forceRun,
            currentTf: $currentTf,
            forceTimeframeCheck: $forceTimeframeCheck,
            lockPerSymbol: true,
            skipOpenStateFilter: true,
            exchange: $context->exchange,
            marketType: $context->marketType,
        );

        $response = $this->mtfRunService->run($request);

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
     * @return array{summary: array, results: array, errors: array}
     */
    private function runParallelViaWorkers(
        array $symbols,
        bool $dryRun,
        bool $forceRun,
        ?string $currentTf,
        bool $forceTimeframeCheck,
        int $workers,
        PerformanceProfiler $profiler,
        ExchangeContext $context
    ): array {
        $queue = new \SplQueue();
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
            'dry_run' => $dryRun,
            'force_run' => $forceRun,
            'current_tf' => $currentTf,
            'force_timeframe_check' => $forceTimeframeCheck,
            'exchange' => $context->exchange->value,
            'market_type' => $context->marketType->value,
        ];

        while (!$queue->isEmpty() || $active !== []) {
            $pollStart = microtime(true);

            while (count($active) < $workers && !$queue->isEmpty()) {
                $symbol = $queue->dequeue();
                $workerStart = microtime(true);
                $process = new Process(
                    $this->buildWorkerCommand($symbol, $options),
                    $this->projectDir,
                    // activer les traces détaillées côté worker
                    ['APP_DEBUG' => '1']
                );
                $process->start();
                $workerStartTimes[$symbol] = $workerStart;
                $active[] = ['symbol' => $symbol, 'process' => $process];
                $profiler->increment('controller', 'worker_start', microtime(true) - $workerStart, $symbol);
            }

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
                        if (is_string($resultSymbol)) {
                            $results[$resultSymbol] = $info;
                        }
                    }

                    $profiler->increment('controller', 'worker_complete', $workerDuration, $symbol);
                } else {
                    $stderr = trim($process->getErrorOutput());
                    $stdout = trim($process->getOutput());
                    $msg = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'unknown error');
                    $errors[] = sprintf('Worker %s: %s', $symbol, $msg);
                    $profiler->increment('controller', 'worker_error', $workerDuration, $symbol);
                }
            }

            $pollDuration = microtime(true) - $pollStart;
            $pollingTime += $pollDuration;
            $pollingCount++;

            if ($hasRunning) {
                usleep(100_000);
            }
        }

        $profiler->increment('controller', 'polling_total', $pollingTime);
        $profiler->increment('controller', 'polling_count', 0, null, null, ['count' => $pollingCount]);

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
            if (is_string($td) && strtolower($td) === 'skipped') { return true; }
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['SKIPPED', 'GRACE_WINDOW'], true);
        }));

        $totalExecutionTime = microtime(true) - $startedAt;
        $summary = [
            'run_id' => Uuid::uuid4()->toString(),
            'execution_time_seconds' => round($totalExecutionTime, 3),
            'symbols_requested' => count($symbols),
            'symbols_processed' => $processed,
            'symbols_successful' => $successCount,
            'symbols_failed' => $failedCount,
            'symbols_skipped' => $skippedCount,
            'success_rate' => $processed > 0 ? round(($successCount / $processed) * 100, 2) : 0.0,
            'dry_run' => $dryRun,
            'force_run' => $forceRun,
            'current_tf' => $currentTf,
            'timestamp' => $this->clock->now()->format('Y-m-d H:i:s'),
            'status' => empty($errors) ? 'completed' : 'completed_with_errors',
            'polling_time_seconds' => round($pollingTime, 3),
            'polling_count' => $pollingCount,
        ];

        $profiler->increment('controller', 'parallel_execution_total', $totalExecutionTime);

        return [
            'summary' => $summary,
            'results' => $results,
            'errors' => $errors,
        ];
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

    /**
     * @param array{
     *     dry_run: bool,
     *     force_run: bool,
     *     current_tf: ?string,
     *     force_timeframe_check: bool,
     *     exchange: string,
     *     market_type: string,
     * } $options
     * @return string[]
     */
    private function buildWorkerCommand(string $symbol, array $options): array
    {
        $php = $this->detectPhpCliBinary();
        $command = [
            $php,
            'bin/console',
            'mtf:run-worker',
            '--symbols=' . $symbol,
            '--dry-run=' . ($options['dry_run'] ? '1' : '0'),
            '--skip-open-filter', // Le filtrage est fait dans le contrôleur, pas dans les workers
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
        if (!empty($options['exchange'])) {
            $command[] = '--exchange=' . $options['exchange'];
        }
        if (!empty($options['market_type'])) {
            $command[] = '--market-type=' . $options['market_type'];
        }

        return $command;
    }

    private function detectPhpCliBinary(): string
    {
        $candidates = [];
        $env = getenv('PHP_CLI_BIN');
        if (is_string($env) && $env !== '') { $candidates[] = $env; }
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        $candidates[] = 'php';
        foreach ($candidates as $bin) {
            // If absolute path, ensure it exists; otherwise rely on PATH
            if ($bin[0] === '/' && !is_file($this->projectDir . '/bin/console')) {
                // nothing
            }
            return $bin;
        }
        return 'php';
    }

    /**
     * Filtre les symboles ayant des ordres ou positions ouverts
     * Cette méthode est appelée AVANT le traitement des workers pour exclure ces symboles
     *
     * @param array<string> $symbols Liste des symboles à filtrer
     * @param string $runIdString ID du run pour les logs
     * @param array<string> $excludedSymbols Référence pour retourner les symboles exclus
     * @return array<string> Liste des symboles à traiter (sans ceux exclus)
     */
    private function filterSymbolsWithOpenOrdersOrPositions(
        array $symbols,
        string $runIdString,
        array &$excludedSymbols = [],
        ?MainProviderInterface $provider = null
    ): array
    {
        $excludedSymbols = [];
        $provider ??= $this->mainProvider;

        if (empty($symbols) || (!$provider?->getAccountProvider() && !$provider?->getOrderProvider())) {
            return $symbols;
        }

        $symbolsToProcess = [];

        // Récupérer les symboles avec positions ouvertes depuis l'exchange
        $openPositionSymbols = [];
        $accountProvider = $provider?->getAccountProvider();
        if ($accountProvider) {
            try {
                $openPositions = $accountProvider->getOpenPositions();
                $this->logger->info('[MTF Controller] Fetched open positions', [
                    'run_id' => $runIdString,
                    'count' => count($openPositions),
                ]);

                foreach ($openPositions as $position) {
                    $positionSymbol = strtoupper($position->symbol ?? '');
                    if ($positionSymbol !== '' && !in_array($positionSymbol, $openPositionSymbols, true)) {
                        $openPositionSymbols[] = $positionSymbol;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MTF Controller] Failed to fetch open positions from exchange', [
                    'run_id' => $runIdString,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Récupérer les symboles avec ordres ouverts depuis l'exchange
        $openOrderSymbols = [];
        $orderProvider = $provider?->getOrderProvider();
        if ($orderProvider) {
            try {
                $openOrders = $orderProvider->getOpenOrders();
                $this->logger->info('[MTF Controller] Fetched open orders', [
                    'run_id' => $runIdString,
                    'count' => count($openOrders),
                ]);

                foreach ($openOrders as $order) {
                    $orderSymbol = strtoupper($order->symbol ?? '');
                    if ($orderSymbol !== '' && !in_array($orderSymbol, $openOrderSymbols, true)) {
                        $openOrderSymbols[] = $orderSymbol;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MTF Controller] Failed to fetch open orders from exchange', [
                    'run_id' => $runIdString,
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
                $this->logger->info('[MTF Controller] Reactivated switches for inactive symbols', [
                    'run_id' => $runIdString,
                    'reactivated_count' => $reactivatedCount,
                    'reason' => 'no_open_orders_or_positions',
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Controller] Failed to reactivate switches for inactive symbols', [
                'run_id' => $runIdString,
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
            $this->logger->info('[MTF Controller] Filtered symbols with open orders/positions', [
                'run_id' => $runIdString,
                'excluded_count' => count($excludedSymbols),
                'excluded_symbols' => array_slice($excludedSymbols, 0, 10),
                'remaining_count' => count($symbolsToProcess),
            ]);
        }

        return $symbolsToProcess;
    }

    /**
     * Met à jour les switches pour les symboles exclus (appelé APRÈS le traitement)
     *
     * @param array<string> $excludedSymbols Liste des symboles exclus (avec ordres/positions ouverts)
     * @param string $runIdString ID du run pour les logs
     */
    private function updateSwitchesForExcludedSymbols(array $excludedSymbols, string $runIdString): void
    {
        // Mettre à jour les switches pour les symboles exclus
        foreach ($excludedSymbols as $symbolUpper) {
            try {
                $isSwitchOff = !$this->mtfSwitchRepository->isSymbolSwitchOn($symbolUpper);

                if ($isSwitchOff) {
                    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, '1m');
                    $this->logger->info('[MTF Controller] Symbol switch extended (was OFF)', [
                        'run_id' => $runIdString,
                        'symbol' => $symbolUpper,
                        'duration' => '1 minute',
                        'reason' => 'has_open_orders_or_positions',
                    ]);
                } else {
                    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, duration: '5m');
                    $this->logger->info('[MTF Controller] Symbol switch disabled', [
                        'run_id' => $runIdString,
                        'symbol' => $symbolUpper,
                        'duration' => '15 minutes',
                        'reason' => 'has_open_orders_or_positions',
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('[MTF Controller] Failed to update symbol switch', [
                    'run_id' => $runIdString,
                    'symbol' => $symbolUpper,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveExchangeContext(array $data): ExchangeContext
    {
        $exchangeInput = $data['exchange'] ?? $data['cex'] ?? null;
        $marketInput = $data['market_type'] ?? $data['type_contract'] ?? null;

        $exchange = Exchange::BITMART;
        if ($exchangeInput !== null) {
            if (!is_string($exchangeInput) || $exchangeInput === '') {
                throw new \InvalidArgumentException('Invalid exchange parameter.');
            }

            $exchange = match (strtolower(trim($exchangeInput))) {
                'bitmart' => Exchange::BITMART,
                default => throw new \InvalidArgumentException(sprintf('Unsupported exchange "%s".', $exchangeInput)),
            };
        }

        $marketType = MarketType::PERPETUAL;
        if ($marketInput !== null) {
            if (!is_string($marketInput) || $marketInput === '') {
                throw new \InvalidArgumentException('Invalid market_type parameter.');
            }

            $marketType = match (strtolower(trim($marketInput))) {
                'perpetual', 'perp', 'future', 'futures' => MarketType::PERPETUAL,
                'spot' => MarketType::SPOT,
                default => throw new \InvalidArgumentException(sprintf('Unsupported market type "%s".', $marketInput)),
            };
        }

        return new ExchangeContext($exchange, $marketType);
    }
}
