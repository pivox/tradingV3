<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\OrderPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MtfOrderPlanController extends AbstractController
{
    public function __construct(
        private readonly OrderPlanRepository $orderPlanRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/mtf/order-plans', name: 'mtf_order_plans_index')]
    public function index(): Response
    {
        // KPIs globaux
        $kpis = [
            'total' => (int)$this->orderPlanRepository->count([]),
            'planned' => $this->orderPlanRepository->count(['status' => 'PLANNED']),
            'executed' => $this->orderPlanRepository->count(['status' => 'EXECUTED']),
            'cancelled' => $this->orderPlanRepository->count(['status' => 'CANCELLED']),
            'failed' => $this->orderPlanRepository->count(['status' => 'FAILED']),
            'long' => 0,
            'short' => 0,
        ];

        // Comptes LONG/SHORT via DQL rapide
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(o.id)')->from('App\\Entity\\OrderPlan', 'o')->where('o.side = :s')->setParameter('s', 'LONG');
            $kpis['long'] = (int)$qb->getQuery()->getSingleScalarResult();
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(o.id)')->from('App\\Entity\\OrderPlan', 'o')->where('o.side = :s')->setParameter('s', 'SHORT');
            $kpis['short'] = (int)$qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable $e) {
            // best-effort
        }

        return $this->render('order_plan/index.html.twig', [
            'orderPlans' => [],
            'kpis' => $kpis,
        ]);
    }

    #[Route('/mtf/order-plans/data', name: 'mtf_order_plans_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        $draw = (int)($request->query->get('draw') ?? 0);
        $start = (int)($request->query->get('start') ?? 0);
        $length = (int)($request->query->get('length') ?? 25);
        $searchValue = (string)($request->query->all('search')['value'] ?? '');
        $order = $request->query->all('order');

        $orderCol = 2; // date plan desc par défaut
        $orderDir = 'desc';
        if (is_array($order) && isset($order[0])) {
            $orderCol = (int)($order[0]['column'] ?? 2);
            $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        }

        $symbol = $request->query->get('symbol');
        $side = $request->query->get('side');
        $status = $request->query->get('status');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        $qb = $this->orderPlanRepository->createQueryBuilder('o');

        // Total count
        $total = (int)(clone $qb)->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();

        if (is_string($symbol) && $symbol !== '') {
            $qb->andWhere('o.symbol = :symbol')->setParameter('symbol', $symbol);
        }
        if (is_string($side) && $side !== '') {
            $qb->andWhere('o.side = :side')->setParameter('side', $side);
        }
        if (is_string($status) && $status !== '') {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        if (is_string($dateFrom) && $dateFrom !== '') {
            $qb->andWhere('o.planTime >= :df')->setParameter('df', new \DateTimeImmutable($dateFrom . ' 00:00:00', new \DateTimeZone('UTC')));
        }
        if (is_string($dateTo) && $dateTo !== '') {
            $qb->andWhere('o.planTime <= :dt')->setParameter('dt', new \DateTimeImmutable($dateTo . ' 23:59:59', new \DateTimeZone('UTC')));
        }
        if ($searchValue !== '') {
            $qb->andWhere('o.symbol LIKE :q OR o.status LIKE :q')
               ->setParameter('q', '%' . $searchValue . '%');
        }

        $filtered = (int)(clone $qb)->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();

        $orderMap = [
            0 => 'o.id',
            1 => 'o.symbol',
            2 => 'o.planTime',
            3 => 'o.side',
            4 => 'o.status',
        ];
        $qb->orderBy($orderMap[$orderCol] ?? 'o.planTime', $orderDir);
        $qb->setFirstResult($start)->setMaxResults($length);

        $rows = $qb->getQuery()->getResult();

        $data = [];
        foreach ($rows as $plan) {
            $data[] = [
                'id' => $plan->getId(),
                'symbol' => $plan->getSymbol(),
                'plan_time' => $plan->getPlanTime()->format('Y-m-d H:i:s'),
                'side' => $plan->getSide()->value,
                'status' => $plan->getStatus(),
                'has_risk' => !empty($plan->getRiskJson()),
                'has_context' => !empty($plan->getContextJson()),
                'has_exec' => !empty($plan->getExecJson()),
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }

    #[Route('/mtf/order-plans/{id}/{type}', name: 'mtf_order_plan_json', requirements: ['id' => '\\d+', 'type' => 'risk|context|exec'], methods: ['GET'])]
    public function jsonField(int $id, string $type): JsonResponse
    {
        $plan = $this->orderPlanRepository->find($id);
        if (!$plan) {
            return new JsonResponse(['error' => 'Plan not found'], 404);
        }
        $map = [
            'risk' => $plan->getRiskJson(),
            'context' => $plan->getContextJson(),
            'exec' => $plan->getExecJson(),
        ];
        return new JsonResponse($map[$type] ?? []);
    }
}
