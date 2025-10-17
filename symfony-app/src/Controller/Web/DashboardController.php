<?php

namespace App\Controller\Web;

use App\Entity\Contract;
use App\Entity\Exchange;
use App\Entity\Kline;
use App\Entity\Position;
use App\Repository\ContractRepository;
use App\Repository\ExchangeRepository;
use App\Repository\KlineRepository;
use App\Repository\PositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContractRepository $contractRepository,
        private readonly PositionRepository $positionRepository,
        private readonly KlineRepository $klineRepository,
        private readonly ExchangeRepository $exchangeRepository,
    ) {
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute('dashboard');
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function index(): Response
    {
        $stats = $this->getDashboardStats();

        return $this->render('dashboard/index.html.twig', [
            'stats' => $stats,
        ]);
    }

    private function getDashboardStats(): array
    {
        // Statistiques des contrats
        $contractsCount = $this->contractRepository->count([]);
        $tradingContractsCount = $this->contractRepository->count(['status' => 'Trading']);

        // Statistiques des positions
        $positionsCount = $this->positionRepository->count([]);
        $openPositionsCount = $this->positionRepository->count(['status' => 'OPEN']);
        $pendingPositionsCount = $this->positionRepository->count(['status' => 'PENDING']);
        $closedPositionsCount = $this->positionRepository->count(['status' => 'CLOSED']);

        // Statistiques des Klines
        $klinesCount = $this->klineRepository->count([]);

        // Statistiques des exchanges
        $exchangesCount = $this->exchangeRepository->count([]);

        // Calcul du PnL total (approximatif)
        $totalPnl = $this->calculateTotalPnl();

        return [
            'contracts_count' => $contractsCount,
            'trading_contracts_count' => $tradingContractsCount,
            'positions_count' => $positionsCount,
            'positions_open' => $openPositionsCount,
            'positions_pending' => $pendingPositionsCount,
            'positions_closed' => $closedPositionsCount,
            'klines_count' => $klinesCount,
            'exchanges_count' => $exchangesCount,
            'total_pnl' => $totalPnl,
        ];
    }

    private function calculateTotalPnl(): float
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('SUM(p.pnlUsdt)')
            ->from(Position::class, 'p')
            ->where('p.pnlUsdt IS NOT NULL');

        $result = $qb->getQuery()->getSingleScalarResult();
        return $result ? (float) $result : 0.0;
    }
}
