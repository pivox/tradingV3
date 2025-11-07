<?php

namespace App\Controller\Web;

use App\Provider\Entity\Contract;
use App\Provider\Entity\Kline;
use App\Entity\Signal;
use App\Provider\Repository\ContractRepository;
use App\Provider\Repository\KlineRepository;
use App\Repository\SignalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
        private readonly SignalRepository $signalRepository,
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

        // Statistiques des Klines
        $klinesCount = $this->klineRepository->count([]);

        // Statistiques des signaux
        $signalsCount = $this->signalRepository->count([]);
        $longSignalsCount = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Signal::class, 's')
            ->where('s.side = :side')
            ->setParameter('side', 'LONG')
            ->getQuery()
            ->getSingleScalarResult();

        $shortSignalsCount = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Signal::class, 's')
            ->where('s.side = :side')
            ->setParameter('side', 'SHORT')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'contracts_count' => $contractsCount,
            'trading_contracts_count' => $tradingContractsCount,
            'klines_count' => $klinesCount,
            'signals_count' => $signalsCount,
            'long_signals_count' => $longSignalsCount,
            'short_signals_count' => $shortSignalsCount,
        ];
    }
}