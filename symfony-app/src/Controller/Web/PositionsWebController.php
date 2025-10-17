<?php

namespace App\Controller\Web;

use App\Entity\Position;
use App\Repository\PositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PositionsWebController extends AbstractController
{
    public function __construct(
        private readonly PositionRepository $positionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/positions', name: 'positions_index')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $side = $request->query->get('side');
        $contract = $request->query->get('contract');
        $exchange = $request->query->get('exchange');

        $positions = $this->positionRepository->findWithFilters($status, $side, $contract, $exchange);
        $stats = $this->getPositionsStats();

        return $this->render('positions/index.html.twig', [
            'positions' => $positions,
            'stats' => $stats,
        ]);
    }

    #[Route('/positions/{id}', name: 'positions_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $position = $this->positionRepository->find($id);

        if (!$position) {
            throw $this->createNotFoundException('Position non trouvée');
        }

        return $this->render('positions/show.html.twig', [
            'position' => $position,
        ]);
    }

    private function getPositionsStats(): array
    {
        $openPositions = $this->positionRepository->count(['status' => 'OPEN']);
        $pendingPositions = $this->positionRepository->count(['status' => 'PENDING']);
        $closedPositions = $this->positionRepository->count(['status' => 'CLOSED']);

        // Calcul du PnL total
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('SUM(p.pnlUsdt)')
            ->from(Position::class, 'p')
            ->where('p.pnlUsdt IS NOT NULL');

        $totalPnl = $qb->getQuery()->getSingleScalarResult() ?: 0;

        return [
            'open_positions' => $openPositions,
            'pending_positions' => $pendingPositions,
            'closed_positions' => $closedPositions,
            'total_pnl' => number_format($totalPnl, 2),
        ];
    }
}
