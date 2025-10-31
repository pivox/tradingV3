<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\MtfAuditRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MtfAuditController extends AbstractController
{
    public function __construct(
        private readonly MtfAuditRepository $mtfAuditRepository,
    ) {
    }

    #[Route('/mtf/audit', name: 'mtf_audit_index')]
    public function index(Request $request): Response
    {
        return $this->render('mtf_audit/index.html.twig', [
            // Server-side DataTables: no preloaded rows
            'audits' => [],
            'runId' => null,
        ]);
    }

    #[Route('/mtf/audit/run/{runId}', name: 'mtf_audit_run', requirements: ['runId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function byRun(string $runId): Response
    {
        return $this->render('mtf_audit/index.html.twig', [
            'audits' => [],
            'runId' => $runId,
        ]);
    }

    #[Route('/mtf/audit/data', name: 'mtf_audit_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        $draw = (int)($request->query->get('draw') ?? 0);
        $start = (int)($request->query->get('start') ?? 0);
        $length = (int)($request->query->get('length') ?? 25);
        $searchValue = (string)($request->query->all('search')['value'] ?? '');
        $order = $request->query->all('order');

        $orderCol = 6; // default: created_at desc
        $orderDir = 'desc';
        if (is_array($order) && isset($order[0])) {
            $orderCol = (int)($order[0]['column'] ?? 6);
            $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        }

        // Custom filters
        $symbol = $request->query->get('symbol');
        $step = $request->query->get('step');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $runId = $request->query->get('run_id');

        $qb = $this->mtfAuditRepository->createQueryBuilder('a');

        // Total count
        $totalQb = clone $qb;
        $total = (int)$totalQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        // Filters
        if (is_string($symbol) && $symbol !== '') {
            $qb->andWhere('a.symbol = :symbol')->setParameter('symbol', $symbol);
        }
        if (is_string($step) && $step !== '') {
            $qb->andWhere('a.step LIKE :step')->setParameter('step', '%' . $step . '%');
        }
        if (is_string($dateFrom) && $dateFrom !== '') {
            $qb->andWhere('a.createdAt >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom . ' 00:00:00', new \DateTimeZone('UTC')));
        }
        if (is_string($dateTo) && $dateTo !== '') {
            $qb->andWhere('a.createdAt <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo . ' 23:59:59', new \DateTimeZone('UTC')));
        }
        if ($searchValue !== '') {
            $qb->andWhere('a.symbol LIKE :q OR a.step LIKE :q OR a.cause LIKE :q')
               ->setParameter('q', '%' . $searchValue . '%');
        }
        if (is_string($runId) && $runId !== '') {
            $qb->andWhere('a.runId = :rid')->setParameter('rid', $runId);
        }

        // Filtered count
        $filteredQb = clone $qb;
        $filtered = (int)$filteredQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        // Ordering map: columns indices -> fields
        $orderMap = [
            0 => 'a.id',
            1 => 'a.symbol',
            2 => 'a.step',
            3 => 'a.timeframe',
            4 => 'a.cause',
            6 => 'a.createdAt',
        ];
        $orderField = $orderMap[$orderCol] ?? 'a.createdAt';
        $qb->orderBy($orderField, $orderDir);

        // Paging
        $qb->setFirstResult(max(0, $start))->setMaxResults(max(1, $length));

        $rows = $qb->getQuery()->getResult();

        $data = [];
        foreach ($rows as $audit) {
            $data[] = [
                'id' => $audit->getId(),
                'symbol' => $audit->getSymbol(),
                'step' => $audit->getStep(),
                'timeframe' => $audit->getTimeframe()?->value,
                'cause' => $audit->getCause(),
                'has_details' => !empty($audit->getDetails()),
                'created_at' => $audit->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }

    #[Route('/mtf/audit/{id}/details', name: 'mtf_audit_details', requirements: ['id' => '\\d+'])]
    public function details(int $id): JsonResponse
    {
        $audit = $this->mtfAuditRepository->find($id);
        if (!$audit) {
            return new JsonResponse(['error' => 'Audit non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $audit->getId(),
            'symbol' => $audit->getSymbol(),
            'run_id' => $audit->getRunId()->toString(),
            'step' => $audit->getStep(),
            'timeframe' => $audit->getTimeframe()?->value,
            'cause' => $audit->getCause(),
            'created_at' => $audit->getCreatedAt()->format('Y-m-d H:i:s'),
            'candle_close_ts' => $audit->getCandleCloseTs()?->format('Y-m-d H:i:s'),
            'severity' => $audit->getSeverity(),
            'details' => $audit->getDetails(),
        ]);
    }
}
