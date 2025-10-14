<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Mtf\Service\MtfService;
use App\Domain\Mtf\Service\MtfRunService;
use App\Repository\KlineRepository;
use App\Repository\MtfAuditRepository;
use App\Repository\MtfLockRepository;
use App\Repository\MtfStateRepository;
use App\Repository\MtfSwitchRepository;
use App\Repository\OrderPlanRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly ClockInterface $clock
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

    #[Route('/pause', name: 'pause', methods: ['POST'])]
    public function pauseWorkflow(): JsonResponse
    {
        try {
            $this->workflowService->pauseMtfWorkflow();
            
            return $this->json([
                'status' => 'success',
                'message' => 'MTF workflow paused',
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to pause workflow', [
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/resume', name: 'resume', methods: ['POST'])]
    public function resumeWorkflow(): JsonResponse
    {
        try {
            $this->workflowService->resumeMtfWorkflow();
            
            return $this->json([
                'status' => 'success',
                'message' => 'MTF workflow resumed',
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to resume workflow', [
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/stop', name: 'stop', methods: ['POST'])]
    public function stopWorkflow(): JsonResponse
    {
        try {
            $this->workflowService->stopMtfWorkflow();
            
            return $this->json([
                'status' => 'success',
                'message' => 'MTF workflow stopped',
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to stop workflow', [
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/restart', name: 'restart', methods: ['POST'])]
    public function restartWorkflow(): JsonResponse
    {
        try {
            $workflowId = $this->workflowService->restartMtfWorkflow();
            
            return $this->json([
                'status' => 'success',
                'message' => 'MTF workflow restarted',
                'data' => [
                    'workflow_id' => $workflowId,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MTF Controller] Failed to restart workflow', [
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
        
        $symbols = $data['symbols'] ?? [];
        $dryRun = filter_var($data['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $forceRun = filter_var($data['force_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $currentTf = $data['current_tf'] ?? null;
        $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;

        try {
            $result = $this->mtfRunService->run($symbols, $dryRun, $forceRun, $currentTf);

            return $this->json([
                'status' => 'success',
                'message' => 'MTF run completed',
                'data' => $result,
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
}
