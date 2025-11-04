<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller;

use App\Contract\Provider\MainProviderInterface;
use App\MtfValidator\Service\MtfService;
use App\MtfValidator\Service\MtfRunService;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Repository\MtfAuditRepository;
use App\Repository\MtfLockRepository;
use App\Repository\MtfStateRepository;
use App\Repository\MtfSwitchRepository;
use App\Repository\OrderPlanRepository;
use Ramsey\Uuid\Uuid;
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
        private readonly OrderPlanRepository $orderPlanRepository,
        private readonly LoggerInterface $logger,
        private readonly MtfRunService $mtfRunService,
        private readonly ClockInterface $clock,
        private readonly ContractRepository $contractRepository,
        private readonly MainProviderInterface $mainProvider,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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

    // TODO: Implémenter les méthodes de workflow si nécessaire

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

    #[Route('/order-plans', name: 'order_plans', methods: ['GET'])]
    public function getOrderPlans(Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->query->get('limit', 50);
            $symbol = $request->query->get('symbol');
            $status = $request->query->get('status');
            
            $queryBuilder = $this->orderPlanRepository->createQueryBuilder('op')
                ->orderBy('op.planTime', 'DESC')
                ->setMaxResults($limit);
            
            if ($symbol) {
                $queryBuilder->andWhere('op.symbol = :symbol')
                    ->setParameter('symbol', $symbol);
            }
            
            if ($status) {
                $queryBuilder->andWhere('op.status = :status')
                    ->setParameter('status', $status);
            }
            
            $orderPlans = $queryBuilder->getQuery()->getResult();
            $orderPlansData = [];
            
            foreach ($orderPlans as $orderPlan) {
                $orderPlansData[] = [
                    'id' => $orderPlan->getId(),
                    'symbol' => $orderPlan->getSymbol(),
                    'side' => $orderPlan->getSide()->value,
                    'status' => $orderPlan->getStatus(),
                    'plan_time' => $orderPlan->getPlanTime()->format('Y-m-d H:i:s'),
                    'context' => $orderPlan->getContextJson(),
                    'risk' => $orderPlan->getRiskJson(),
                    'exec' => $orderPlan->getExecJson()
                ];
            }
            
            return $this->json([
                'status' => 'success',
                'data' => $orderPlansData
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to get order plans', [
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

            $this->logger->info('[MTF Controller] Contract synchronization started', [
                'symbols' => $symbols,
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);

            // Call the provider sync method
            $provider = $this->mainProvider->getContractProvider();
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
        // Parse JSON request body for POST or query parameters for GET
        $data = [];
        if ($request->getMethod() === 'POST') {
            $data = json_decode($request->getContent(), true) ?? [];
        } else {
            // For GET requests, get parameters from query string
            $data = $request->query->all();
        }
        
        $symbolsInput = $data['symbols'] ?? [];
        $dryRun = filter_var($data['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $forceRun = filter_var($data['force_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $forceTimeframeCheck = filter_var($data['force_timeframe_check'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $currentTf = $data['current_tf'] ?? null;
        $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;
        $workers = max(1, (int)($data['workers'] ?? 1));

        $symbols = $this->resolveSymbols($symbolsInput);

        try {
            if ($workers > 1) {
                $result = $this->runParallelViaWorkers(
                    $symbols,
                    $dryRun,
                    $forceRun,
                    $currentTf,
                    $forceTimeframeCheck,
                    $workers
                );
            } else {
                $result = $this->runSequential($symbols, $dryRun, $forceRun, $currentTf, $forceTimeframeCheck);
            }

            $status = empty($result['errors']) ? 'success' : 'partial_success';

            // Extraire les symboles rejetés (tous les statuts non-SUCCESS)
            $rejectedBy = [];
            $lastValidated = [];
            $results = $result['results'] ?? [];
            
            foreach ($results as $symbol => $symbolResult) {
                $resultStatus = strtoupper((string)($symbolResult['status'] ?? ''));
                
                // Collecter les rejetés
                if ($resultStatus !== 'SUCCESS') {
                    $rejectedBy[] = $symbol;
                }
                
                // Collecter les derniers validés (SUCCESS uniquement)
                if ($resultStatus === 'SUCCESS') {
                    $executionTf = $symbolResult['execution_tf'] ?? null;
                    $signalSide = $symbolResult['signal_side'] ?? null;
                    
                    // Calculer le timeframe précédent (tf-1) ou 'READY' pour 1m
                    $timeframe = $this->getPreviousTimeframe($executionTf);
                    
                    // Ajouter seulement si on a au moins le symbole
                    // Exemples JSON pour cas limites :
                    // - Si execution_tf manquant : timeframe sera null, signal_side peut être null
                    // - Si signal_side manquant : side sera null
                    // Exemple: {"symbol": "BTCUSDT", "side": null, "timeframe": null}
                    // Exemple: {"symbol": "ETHUSDT", "side": "LONG", "timeframe": "15m"}
                    // Exemple: {"symbol": "ADAUSDT", "side": "SHORT", "timeframe": "READY"}
                    $lastValidated[] = [
                        'symbol' => $symbol,
                        'side' => $signalSide,
                        'timeframe' => $timeframe,
                    ];
                }
            }

            return $this->json([
                'status' => $status,
                'message' => 'MTF run completed',
                'data' => [
                    'summary' => $result['summary'] ?? [],
                  //  'results' => $results,
                    'errors' => $result['errors'] ?? [],
                    'workers' => $workers,
                    'rejected_by' => $rejectedBy,
                    'last_validated' => $lastValidated,
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
     * @param mixed $symbolsInput
     * @return string[]
     */
    private function resolveSymbols(mixed $symbolsInput): array
    {
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
        if ($symbols !== []) {
            return $symbols;
        }

        try {
            $fetched = $this->contractRepository->allActiveSymbolNames();
            if (!empty($fetched)) {
                return array_values(array_unique(array_map('strval', $fetched)));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[MTF Controller] Failed to load active symbols, using fallback', [
                'error' => $e->getMessage(),
            ]);
        }

        return ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
    }

    /**
     * @return array{summary: array, results: array, errors: array}
     */
    private function runSequential(array $symbols, bool $dryRun, bool $forceRun, ?string $currentTf, bool $forceTimeframeCheck): array
    {
        $generator = $this->mtfRunService->run($symbols, $dryRun, $forceRun, $currentTf, $forceTimeframeCheck);

        if (!$generator instanceof \Generator) {
            return [
                'summary' => is_array($generator) ? ($generator['summary'] ?? []) : [],
                'results' => is_array($generator) ? ($generator['results'] ?? []) : [],
                'errors' => [],
            ];
        }

        $summary = [];
        $results = [];

        foreach ($generator as $yielded) {
            $symbol = $yielded['symbol'] ?? null;
            $result = $yielded['result'] ?? null;
            if (is_string($symbol) && $symbol !== 'FINAL' && is_array($result)) {
                $results[$symbol] = $result;
            }
        }

        $final = $generator->getReturn();
        if (is_array($final)) {
            $summary = $final['summary'] ?? $summary;
            $results = $final['results'] ?? $results;
        }

        return [
            'summary' => $summary,
            'results' => $results,
            'errors' => [],
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
        int $workers
    ): array {
        $queue = new \SplQueue();
        foreach ($symbols as $symbol) {
            $queue->enqueue($symbol);
        }

        $active = [];
        $results = [];
        $errors = [];
        $startedAt = microtime(true);

        $options = [
            'dry_run' => $dryRun,
            'force_run' => $forceRun,
            'current_tf' => $currentTf,
            'force_timeframe_check' => $forceTimeframeCheck,
        ];

        while (!$queue->isEmpty() || $active !== []) {
            while (count($active) < $workers && !$queue->isEmpty()) {
                $symbol = $queue->dequeue();
                $process = new Process(
                    $this->buildWorkerCommand($symbol, $options),
                    $this->projectDir
                );
                $process->start();
                $active[] = ['symbol' => $symbol, 'process' => $process];
            }

            foreach ($active as $index => $worker) {
                $process = $worker['process'];
                if ($process->isRunning()) {
                    continue;
                }

                $symbol = $worker['symbol'];
                unset($active[$index]);
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
                        $errors[] = sprintf('Worker %s: invalid JSON output (%s)', $symbol, $exception->getMessage());
                        continue;
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
                } else {
                    $errorOutput = trim($process->getErrorOutput());
                    $errors[] = sprintf('Worker %s: %s', $symbol, $errorOutput !== '' ? $errorOutput : 'unknown error');
                }
            }

            usleep(100_000);
        }

        $processed = count($results);
        $successCount = count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'SUCCESS'));
        $failedCount = count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'ERROR'));
        $skippedCount = count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'SKIPPED'));

        $summary = [
            'run_id' => Uuid::uuid4()->toString(),
            'execution_time_seconds' => round(microtime(true) - $startedAt, 3),
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
        ];

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
     * } $options
     * @return string[]
     */
    private function buildWorkerCommand(string $symbol, array $options): array
    {
        $command = [
            'php',
            'bin/console',
            'mtf:run-worker',
            '--symbols=' . $symbol,
            '--dry-run=' . ($options['dry_run'] ? '1' : '0'),
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

        return $command;
    }
}
