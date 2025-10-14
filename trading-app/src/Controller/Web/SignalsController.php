<?php

namespace App\Controller\Web;

use App\Entity\Signal;
use App\Repository\SignalRepository;
use App\Repository\ContractRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SignalsController extends AbstractController
{
    public function __construct(
        private readonly SignalRepository $signalRepository,
        private readonly ContractRepository $contractRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/signals', name: 'signals_index')]
    public function index(Request $request): Response
    {
        $symbol = $request->query->get('symbol');
        $timeframe = $request->query->get('timeframe');
        $side = $request->query->get('side');

        $signals = $this->getSignalsWithFilters($symbol, $timeframe, $side);
        $stats = $this->getSignalsStats();
        
        // Données pour le tableau des derniers signaux par contrat
        $lastSignalsByContract = $this->signalRepository->findLastSignalsGrouped();
        $activeContracts = $this->contractRepository->allActiveSymbolNames();
        $timeframes = ['1m', '5m', '15m', '1h', '4h'];

        return $this->render('signals/index.html.twig', [
            'signals' => $signals,
            'stats' => $stats,
            'lastSignalsByContract' => $lastSignalsByContract,
            'activeContracts' => $activeContracts,
            'timeframes' => $timeframes,
        ]);
    }

    #[Route('/signals/{id}', name: 'signals_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $signal = $this->signalRepository->find($id);

        if (!$signal) {
            throw $this->createNotFoundException('Signal non trouvé');
        }

        return $this->render('signals/show.html.twig', [
            'signal' => $signal,
        ]);
    }

    private function getSignalsWithFilters(?string $symbol, ?string $timeframe, ?string $side): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('s')
            ->from(Signal::class, 's')
            ->orderBy('s.insertedAt', 'DESC')
            ->setMaxResults(1000); // Limiter pour les performances

        if ($symbol) {
            $qb->andWhere('s.symbol LIKE :symbol')
                ->setParameter('symbol', '%' . $symbol . '%');
        }

        if ($timeframe) {
            $qb->andWhere('s.timeframe = :timeframe')
                ->setParameter('timeframe', $timeframe);
        }

        if ($side) {
            $qb->andWhere('s.side = :side')
                ->setParameter('side', $side);
        }

        return $qb->getQuery()->getResult();
    }

    private function getSignalsStats(): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        // Total des signaux
        $qb->select('COUNT(s.id)')
            ->from(Signal::class, 's');
        $totalSignals = $qb->getQuery()->getSingleScalarResult();

        // Signaux LONG
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(s.id)')
            ->from(Signal::class, 's')
            ->where('s.side = :side')
            ->setParameter('side', 'LONG');
        $longSignals = $qb->getQuery()->getSingleScalarResult();

        // Signaux SHORT
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(s.id)')
            ->from(Signal::class, 's')
            ->where('s.side = :side')
            ->setParameter('side', 'SHORT');
        $shortSignals = $qb->getQuery()->getSingleScalarResult();

        return [
            'total_signals' => $totalSignals,
            'long_signals' => $longSignals,
            'short_signals' => $shortSignals,
        ];
    }
}