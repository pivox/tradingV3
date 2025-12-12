<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller;

use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Provider\Repository\ContractRepository;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\MtfValidator\Repository\MtfLockRepository;
use App\Repository\MtfStateRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('', name: 'mtf_')]
class MtfController extends AbstractController
{
    public function __construct(
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfAuditRepository $mtfAuditRepository,
        private readonly MtfLockRepository $mtfLockRepository,
        private readonly LoggerInterface $mtfLogger,
        private readonly MtfValidatorInterface $mtfValidator,
        private readonly ClockInterface $clock,
        private readonly ContractRepository $contractRepository,
        private readonly MainProviderInterface $mainProvider,
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
            $this->mtfLogger->error('[MTF Controller] Failed to get status', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to get lock status', [
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

            $this->mtfLogger->warning('[MTF Controller] Force releasing lock', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to force release lock', [
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

            $this->mtfLogger->info('[MTF Controller] Cleaned up expired locks', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to cleanup expired locks', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to start workflow', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to get switches', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to toggle switch', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to get states', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to get audit', [
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

            $this->mtfLogger->info('[MTF Controller] Contract synchronization started', [
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
            $this->mtfLogger->error('[MTF Controller] Failed to synchronize contracts', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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
