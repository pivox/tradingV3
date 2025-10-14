<?php

namespace App\Controller\Web;

use App\Entity\SignalEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SignalsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/signals', name: 'signals_index')]
    public function index(Request $request): Response
    {
        $symbol = $request->query->get('symbol');
        $tf = $request->query->get('tf');
        $side = $request->query->get('side');
        $passed = $request->query->get('passed');
        $dateFrom = $request->query->get('date_from');

        $signals = $this->getSignalsWithFilters($symbol, $tf, $side, $passed, $dateFrom);
        $stats = $this->getSignalsStats();

        return $this->render('signals/index.html.twig', [
            'signals' => $signals,
            'stats' => $stats,
        ]);
    }

    #[Route('/signals/{id}', name: 'signals_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $signal = $this->entityManager->getRepository(SignalEvent::class)->find($id);

        if (!$signal) {
            throw $this->createNotFoundException('Signal non trouvé');
        }

        return $this->render('signals/show.html.twig', [
            'signal' => $signal,
        ]);
    }

    private function getSignalsWithFilters(?string $symbol, ?string $tf, ?string $side, ?string $passed, ?string $dateFrom): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('s')
            ->from(SignalEvent::class, 's')
            ->orderBy('s.atUtc', 'DESC')
            ->setMaxResults(1000); // Limiter pour les performances

        if ($symbol) {
            $qb->andWhere('s.symbol LIKE :symbol')
                ->setParameter('symbol', '%' . $symbol . '%');
        }

        if ($tf) {
            $qb->andWhere('s.tf = :tf')
                ->setParameter('tf', $tf);
        }

        if ($side) {
            $qb->andWhere('s.side = :side')
                ->setParameter('side', $side);
        }

        if ($passed !== null && $passed !== '') {
            $qb->andWhere('s.passed = :passed')
                ->setParameter('passed', $passed === 'true');
        }

        if ($dateFrom) {
            $date = new \DateTime($dateFrom);
            $qb->andWhere('s.atUtc >= :dateFrom')
                ->setParameter('dateFrom', $date);
        }

        return $qb->getQuery()->getResult();
    }

    private function getSignalsStats(): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        // Total des signaux
        $qb->select('COUNT(s.id)')
            ->from(SignalEvent::class, 's');
        $totalSignals = $qb->getQuery()->getSingleScalarResult();

        // Signaux passés
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(s.id)')
            ->from(SignalEvent::class, 's')
            ->where('s.passed = true');
        $passedSignals = $qb->getQuery()->getSingleScalarResult();

        // Signaux LONG
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(s.id)')
            ->from(SignalEvent::class, 's')
            ->where('s.side = :side')
            ->setParameter('side', 'LONG');
        $longSignals = $qb->getQuery()->getSingleScalarResult();

        // Signaux SHORT
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(s.id)')
            ->from(SignalEvent::class, 's')
            ->where('s.side = :side')
            ->setParameter('side', 'SHORT');
        $shortSignals = $qb->getQuery()->getSingleScalarResult();

        return [
            'total_signals' => $totalSignals,
            'passed_signals' => $passedSignals,
            'long_signals' => $longSignals,
            'short_signals' => $shortSignals,
        ];
    }
}
