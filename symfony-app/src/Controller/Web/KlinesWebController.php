<?php

namespace App\Controller\Web;

use App\Entity\Kline;
use App\Repository\KlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class KlinesWebController extends AbstractController
{
    public function __construct(
        private readonly KlineRepository $klineRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/klines', name: 'klines_index')]
    public function index(Request $request): Response
    {
        $contract = $request->query->get('contract');
        $step = $request->query->get('step');
        $exchange = $request->query->get('exchange');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        $klines = $this->klineRepository->findWithFilters($contract, $step, $exchange, $dateFrom, $dateTo);
        $stats = $this->getKlinesStats();

        return $this->render('klines/index.html.twig', [
            'klines' => $klines,
            'stats' => $stats,
        ]);
    }

    #[Route('/klines/{id}', name: 'klines_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $kline = $this->klineRepository->find($id);

        if (!$kline) {
            throw $this->createNotFoundException('Kline non trouvée');
        }

        return $this->render('klines/show.html.twig', [
            'kline' => $kline,
        ]);
    }

    private function getKlinesStats(): array
    {
        $totalKlines = $this->klineRepository->count([]);

        // Nombre de contrats uniques
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(DISTINCT k.contract)')
            ->from(Kline::class, 'k');
        $uniqueContracts = $qb->getQuery()->getSingleScalarResult();

        // Date la plus récente
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('MAX(k.timestamp)')
            ->from(Kline::class, 'k');
        $latestDate = $qb->getQuery()->getSingleScalarResult();

        // Date la plus ancienne
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('MIN(k.timestamp)')
            ->from(Kline::class, 'k');
        $oldestDate = $qb->getQuery()->getSingleScalarResult();

        return [
            'total_klines' => $totalKlines,
            'unique_contracts' => $uniqueContracts,
            'latest_date' => $latestDate ? $latestDate->format('d/m/Y') : 'N/A',
            'oldest_date' => $oldestDate ? $oldestDate->format('d/m/Y') : 'N/A',
        ];
    }
}
