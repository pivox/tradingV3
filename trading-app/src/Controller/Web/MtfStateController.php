<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\MtfStateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MtfStateController extends AbstractController
{
    public function __construct(
        private readonly MtfStateRepository $stateRepository,
    ) {
    }

    #[Route('/mtf/states', name: 'mtf_states_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('mtf_state/index.html.twig', [
            'mtfStates' => [],
        ]);
    }

    #[Route('/mtf/states/data', name: 'mtf_states_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        $draw = (int)($request->query->get('draw') ?? 0);
        $start = (int)($request->query->get('start') ?? 0);
        $length = (int)($request->query->get('length') ?? 25);
        $searchValue = (string)($request->query->all('search')['value'] ?? '');
        $order = $request->query->all('order');

        $orderCol = 8; // updated_at desc by default
        $orderDir = 'desc';
        if (is_array($order) && isset($order[0])) {
            $orderCol = (int)($order[0]['column'] ?? 8);
            $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        }

        $symbol = $request->query->get('symbol');

        $qb = $this->stateRepository->createQueryBuilder('s');

        // Total
        $total = (int)(clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();

        // Filters
        if (is_string($symbol) && $symbol !== '') {
            $qb->andWhere('s.symbol = :symbol')->setParameter('symbol', $symbol);
        }
        if ($searchValue !== '') {
            $qb->andWhere('s.symbol LIKE :q')
               ->setParameter('q', '%' . $searchValue . '%');
        }

        // Filtered count
        $filtered = (int)(clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();

        // Order mapping
        $map = [
            0 => 's.id',
            1 => 's.symbol',
            2 => 's.k4hTime',
            3 => 's.k1hTime',
            4 => 's.k15mTime',
            5 => 's.k5mTime',
            6 => 's.k1mTime',
            8 => 's.updatedAt',
        ];
        $qb->orderBy($map[$orderCol] ?? 's.updatedAt', $orderDir);
        $qb->setFirstResult($start)->setMaxResults($length);

        $rows = $qb->getQuery()->getResult();

        $data = [];
        foreach ($rows as $state) {
            $data[] = [
                'id' => $state->getId(),
                'symbol' => $state->getSymbol(),
                'k4h_time' => $state->getK4hTime()?->format('Y-m-d H:i:s'),
                'k1h_time' => $state->getK1hTime()?->format('Y-m-d H:i:s'),
                'k15m_time' => $state->getK15mTime()?->format('Y-m-d H:i:s'),
                'k5m_time' => $state->getK5mTime()?->format('Y-m-d H:i:s'),
                'k1m_time' => $state->getK1mTime()?->format('Y-m-d H:i:s'),
                'sides' => $state->getSides(),
                'updated_at' => $state->getUpdatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }

    #[Route('/mtf/states/stats', name: 'mtf_states_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $countTotal = (int)$this->stateRepository->createQueryBuilder('s')->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $count4h = (int)$this->stateRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.k4hTime IS NOT NULL')->getQuery()->getSingleScalarResult();
        $count1h = (int)$this->stateRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.k1hTime IS NOT NULL')->getQuery()->getSingleScalarResult();
        $count15m = (int)$this->stateRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.k15mTime IS NOT NULL')->getQuery()->getSingleScalarResult();
        $count5m = (int)$this->stateRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.k5mTime IS NOT NULL')->getQuery()->getSingleScalarResult();
        $count1m = (int)$this->stateRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.k1mTime IS NOT NULL')->getQuery()->getSingleScalarResult();

        return new JsonResponse([
            'total' => $countTotal,
            'k4h' => $count4h,
            'k1h' => $count1h,
            'k15m' => $count15m,
            'k5m' => $count5m,
            'k1m' => $count1m,
        ]);
    }
}
