<?php

namespace App\Controller\Web;

use App\Provider\Entity\Kline;
use App\Provider\Repository\KlineRepository;
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
        $symbol = $request->query->get('symbol');
        $timeframe = $request->query->get('timeframe');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        $klines = $this->klineRepository->findWithFilters($symbol, $timeframe, $dateFrom, $dateTo);
        $stats = $this->getKlinesStats();

        return $this->render('Provider/klines/index.html.twig', [
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

        return $this->render('Provider/klines/show.html.twig', [
            'kline' => $kline,
        ]);
    }

    private function getKlinesStats(): array
    {
        $totalKlines = $this->klineRepository->count([]);

        // Nombre de symboles uniques
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(DISTINCT k.symbol)')
            ->from(Kline::class, 'k');
        $uniqueSymbols = $qb->getQuery()->getSingleScalarResult();

        // Date la plus récente
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('MAX(k.openTime)')
            ->from(Kline::class, 'k');
        $latestDate = $qb->getQuery()->getSingleScalarResult();


        // Date la plus ancienne
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('MIN(k.openTime)')
            ->from(Kline::class, 'k');
        $oldestDate = $qb->getQuery()->getSingleScalarResult();

        return [
            'total_klines' => $totalKlines,
            'unique_symbols' => $uniqueSymbols,
            'latest_date' => $latestDate ? $latestDate: 'N/A',
            'oldest_date' => $oldestDate ? $oldestDate : 'N/A',
        ];
    }
}