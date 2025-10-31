<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Repository\MtfSwitchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MtfSwitchWebController extends AbstractController
{
    public function __construct(
        private readonly MtfSwitchRepository $switchRepository,
    ) {
    }

    #[Route('/mtf/switches', name: 'mtf_switches_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('mtf_switch/index.html.twig', [
            'switches' => [],
        ]);
    }

    #[Route('/mtf/switches/data', name: 'mtf_switches_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        $draw = (int)($request->query->get('draw') ?? 0);
        $start = (int)($request->query->get('start') ?? 0);
        $length = (int)($request->query->get('length') ?? 25);
        $searchValue = (string)($request->query->all('search')['value'] ?? '');
        $order = $request->query->all('order');

        $orderCol = 6; // updatedAt desc
        $orderDir = 'desc';
        if (is_array($order) && isset($order[0])) {
            $orderCol = (int)($order[0]['column'] ?? 6);
            $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        }

        $symbol = $request->query->get('symbol');
        $type = $request->query->get('type'); // GLOBAL | SYMBOL | SYMBOL_TF

        $qb = $this->switchRepository->createQueryBuilder('m');

        $total = (int)(clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        if (is_string($symbol) && $symbol !== '') {
            $qb->andWhere('m.switchKey LIKE :sym1 OR m.switchKey LIKE :sym2')
               ->setParameter('sym1', 'SYMBOL:' . $symbol)
               ->setParameter('sym2', 'SYMBOL_TF:' . $symbol . ':%');
        }
        if ($type === 'GLOBAL') {
            $qb->andWhere('m.switchKey = :g')->setParameter('g', 'GLOBAL');
        } elseif ($type === 'SYMBOL') {
            $qb->andWhere("m.switchKey LIKE 'SYMBOL:%' AND m.switchKey NOT LIKE 'SYMBOL_TF:%'");
        } elseif ($type === 'SYMBOL_TF') {
            $qb->andWhere("m.switchKey LIKE 'SYMBOL_TF:%'");
        }
        if ($searchValue !== '') {
            $qb->andWhere('m.switchKey LIKE :q OR m.description LIKE :q')
               ->setParameter('q', '%' . $searchValue . '%');
        }

        $filtered = (int)(clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        $map = [
            0 => 'm.id',
            1 => 'm.switchKey',
            2 => 'm.isOn',
            5 => 'm.expiresAt',
            6 => 'm.updatedAt',
        ];
        $qb->orderBy($map[$orderCol] ?? 'm.updatedAt', $orderDir);
        $qb->setFirstResult($start)->setMaxResults($length);

        $rows = $qb->getQuery()->getResult();

        $data = [];
        foreach ($rows as $sw) {
            $data[] = [
                'id' => $sw->getId(),
                'switch_key' => $sw->getSwitchKey(),
                'is_on' => $sw->isOn(),
                'symbol' => $sw->getSymbol(),
                'timeframe' => $sw->getTimeframe(),
                'expires_at' => $sw->getExpiresAt()?->format('Y-m-d H:i:s'),
                'updated_at' => $sw->getUpdatedAt()->format('Y-m-d H:i:s'),
                'description' => $sw->getDescription(),
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }
}

