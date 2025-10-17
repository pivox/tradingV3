<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Position;
use App\Repository\PositionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class PositionsOpenController extends AbstractController
{
    public function __construct(private readonly PositionRepository $positions) {}

    #[Route('/api/positions/open', name: 'api_positions_open', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $symbol = $request->query->get('symbol'); // ex: BTCUSDT
        $side   = $request->query->get('side');   // ex: LONG | SHORT | 1 | 2

        $qb = $this->positions->createQueryBuilder('p')
            ->andWhere('p.status = :st')->setParameter('st', Position::STATUS_OPEN)
            ->orderBy('p.openedAt', 'DESC')
            ->setMaxResults(200);

        if ($symbol) {
            // IDENTITY(p.contract) retourne la PK (symbol) de la relation ManyToOne
            $qb->andWhere('IDENTITY(p.contract) = :sym')->setParameter('sym', strtoupper((string) $symbol));
        }

        if ($side) {
            $mapped = $this->normalizeSide((string)$side);
            if ($mapped) {
                $qb->andWhere('p.side = :side')->setParameter('side', $mapped);
            }
        }

        $rows = $qb->getQuery()->getResult();

        $out = [];
        foreach ($rows as $p) {
            if (!$p instanceof Position) { continue; }
            $out[] = [
                'id'          => $p->getId(),
                'symbol'      => $p->getContract()->getSymbol(),
                'side'        => $p->getSide(),
                'status'      => $p->getStatus(),
                'entryPrice'  => $p->getEntryPrice(),
                'qtyContract' => $p->getQtyContract(),
                'leverage'    => $p->getLeverage(),
                'openedAt'    => $p->getOpenedAt()?->format('c'),
                'updatedAt'   => $p->getUpdatedAt()->format('c'),
            ];
        }

        return $this->json(['count' => \count($out), 'data' => $out]);
    }

    private function normalizeSide(string $side): ?string
    {
        $side = strtoupper($side);
        return match ($side) {
            '1', 'LONG'  => Position::SIDE_LONG,
            '2', 'SHORT' => Position::SIDE_SHORT,
            default      => null,
        };
    }
}

