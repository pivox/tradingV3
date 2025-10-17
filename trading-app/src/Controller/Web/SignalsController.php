<?php

namespace App\Controller\Web;

use App\Entity\Signal;
use App\Repository\SignalRepository;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Repository\IndicatorSnapshotRepository;
use App\Domain\Common\Enum\Timeframe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SignalsController extends AbstractController
{
    public function __construct(
        private readonly SignalRepository $signalRepository,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
        private readonly IndicatorSnapshotRepository $indicatorRepository,
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

    #[Route('/signals/analysis', name: 'signals_analysis')]
    public function analysis(Request $request): Response
    {
        $symbol = $request->query->get('symbol');
        $timeframe = $request->query->get('timeframe');
        $side = $request->query->get('side');
        $limit = (int) $request->query->get('limit', 25);

        $signals = $this->getSignalsWithFilters($symbol, $timeframe, $side);
        $signals = array_slice($signals, 0, $limit);

        $analysisData = [];
        foreach ($signals as $signal) {
            $analysisData[] = $this->analyzeSignal($signal);
        }

        return $this->render('signals/_signal_analysis_results.html.twig', [
            'signals' => $analysisData,
        ]);
    }

    #[Route('/signals/{id}/details', name: 'signals_details', requirements: ['id' => '\d+'])]
    public function details(int $id): Response
    {
        $signal = $this->signalRepository->find($id);

        if (!$signal) {
            throw $this->createNotFoundException('Signal non trouvé');
        }

        $analysis = $this->analyzeSignal($signal);

        return $this->render('signals/_signal_details_modal.html.twig', [
            'signal' => $signal,
            'analysis' => $analysis,
        ]);
    }

    #[Route('/signals/{id}/meta', name: 'signals_meta', requirements: ['id' => '\d+'])]
    public function meta(int $id): JsonResponse
    {
        $signal = $this->signalRepository->find($id);

        if (!$signal) {
            return new JsonResponse(['error' => 'Signal non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $signal->getId(),
            'symbol' => $signal->getSymbol(),
            'timeframe' => $signal->getTimeframe()->value,
            'side' => $signal->getSide()->value,
            'score' => $signal->getScore(),
            'kline_time' => $signal->getKlineTime()->format('Y-m-d H:i:s'),
            'inserted_at' => $signal->getInsertedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $signal->getUpdatedAt()->format('Y-m-d H:i:s'),
            'meta' => $signal->getMeta(),
        ]);
    }

    #[Route('/signals/mtf-overview', name: 'signals_mtf_overview')]
    public function mtfOverview(Request $request): Response
    {
        $activeSymbols = $this->contractRepository->allActiveSymbolNames();

        $rows = [];

        foreach ($activeSymbols as $symbol) {
            $lastH4 = $this->signalRepository->findLastBySymbolAndTimeframe($symbol, Timeframe::TF_4H);
            if (!$lastH4) {
                continue;
            }

            $start = $lastH4->getKlineTime();
            $end = $start->modify('+4 hours');

            $counts = [
                '1h' => 0,
                '15m' => 0,
                '5m' => 0,
                '1m' => 0,
            ];

            $signals1h = $this->signalRepository->findBySymbolTimeframeAndDateRange($symbol, Timeframe::TF_1H, $start, $end);
            $signals15m = $this->signalRepository->findBySymbolTimeframeAndDateRange($symbol, Timeframe::TF_15M, $start, $end);
            $signals5m = $this->signalRepository->findBySymbolTimeframeAndDateRange($symbol, Timeframe::TF_5M, $start, $end);
            $signals1m = $this->signalRepository->findBySymbolTimeframeAndDateRange($symbol, Timeframe::TF_1M, $start, $end);

            $counts['1h'] = $this->countWindowsWithSignals($signals1h, $start, 60);
            $counts['15m'] = $this->countWindowsWithSignals($signals15m, $start, 15);
            $counts['5m'] = $this->countWindowsWithSignals($signals5m, $start, 5);
            $counts['1m'] = $this->countWindowsWithSignals($signals1m, $start, 1);

            $total = array_sum($counts);

            $rows[] = [
                'symbol' => $symbol,
                'h4' => $lastH4,
                'counts' => $counts,
                'total' => $total,
            ];
        }

        usort($rows, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return $this->render('signals/_mtf_overview_results.html.twig', [
            'rows' => $rows,
        ]);
    }

    /**
     * @param Signal[] $signals
     */
    private function countWindowsWithSignals(array $signals, \DateTimeImmutable $start, int $windowSizeMinutes): int
    {
        $windowSeconds = $windowSizeMinutes * 60;
        $startTs = $start->getTimestamp();
        $covered = [];

        foreach ($signals as $sig) {
            if ($sig->getSide()->isNone()) {
                continue;
            }
            $ts = $sig->getKlineTime()->getTimestamp();
            if ($ts < $startTs || $ts >= $startTs + 4 * 3600) {
                continue;
            }
            $offset = $ts - $startTs;
            $windowIndex = intdiv($offset, $windowSeconds);
            $covered[$windowIndex] = true;
        }

        return count($covered);
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

    private function analyzeSignal(Signal $signal): array
    {
        $symbol = $signal->getSymbol();
        $timeframe = $signal->getTimeframe();
        $klineTime = $signal->getKlineTime();
        $side = $signal->getSide();

        $analysis = [
            'signal' => $signal,
            'indicators' => [],
            'klines' => [],
            'conditions' => [],
            'parent_analysis' => [],
            'errors' => []
        ];

        try {
            // 1. Récupérer les klines pour ce signal
            $klines = $this->klineRepository->findBySymbolAndTimeframe(
                $symbol, 
                $timeframe, 
                200 // Nombre de klines pour le calcul des indicateurs
            );
            
            if (empty($klines)) {
                $analysis['errors'][] = 'Aucune kline trouvée pour ce signal';
                return $analysis;
            }

            $analysis['klines'] = array_slice($klines, -10); // Garder les 10 dernières klines

            // 2. Récupérer les indicateurs
            $indicatorSnapshot = $this->indicatorRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);
            if ($indicatorSnapshot) {
                $analysis['indicators'] = [
                    'rsi' => $indicatorSnapshot->getRsi(),
                    'ema20' => $indicatorSnapshot->getEma20(),
                    'ema50' => $indicatorSnapshot->getEma50(),
                    'macd' => $indicatorSnapshot->getMacd(),
                    'macd_signal' => $indicatorSnapshot->getMacdSignal(),
                    'macd_histogram' => $indicatorSnapshot->getMacdHistogram(),
                    'atr' => $indicatorSnapshot->getAtr(),
                    'vwap' => $indicatorSnapshot->getVwap(),
                    'bb_upper' => $indicatorSnapshot->getBbUpper(),
                    'bb_middle' => $indicatorSnapshot->getBbMiddle(),
                    'bb_lower' => $indicatorSnapshot->getBbLower(),
                    'ma9' => $indicatorSnapshot->getMa9(),
                    'ma21' => $indicatorSnapshot->getMa21(),
                ];
            }

            // 3. Analyser les timeframes parents
            $parentTimeframes = ['15m', '1h', '4h'];
            foreach ($parentTimeframes as $parentTf) {
                try {
                    $parentTfEnum = match($parentTf) {
                        '15m' => \App\Domain\Common\Enum\Timeframe::TF_15M,
                        '1h' => \App\Domain\Common\Enum\Timeframe::TF_1H,
                        '4h' => \App\Domain\Common\Enum\Timeframe::TF_4H,
                        default => null
                    };

                    if (!$parentTfEnum) continue;

                    $parentKlines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $parentTfEnum, 200);
                    if (empty($parentKlines)) {
                        $analysis['parent_analysis'][$parentTf] = ['error' => 'Aucune kline trouvée'];
                        continue;
                    }

                    $parentIndicator = $this->indicatorRepository->findLastBySymbolAndTimeframe($symbol, $parentTfEnum);
                    $parentAnalysis = [
                        'timeframe' => $parentTf,
                        'indicators' => $parentIndicator ? [
                            'rsi' => $parentIndicator->getRsi(),
                            'ema20' => $parentIndicator->getEma20(),
                            'ema50' => $parentIndicator->getEma50(),
                        ] : [],
                        'klines_count' => count($parentKlines)
                    ];

                    $analysis['parent_analysis'][$parentTf] = $parentAnalysis;

                } catch (\Exception $e) {
                    $analysis['parent_analysis'][$parentTf] = ['error' => $e->getMessage()];
                }
            }

        } catch (\Exception $e) {
            $analysis['errors'][] = 'Erreur lors de l\'analyse : ' . $e->getMessage();
        }

        return $analysis;
    }
}