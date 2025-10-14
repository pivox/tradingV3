<?php

namespace App\Controller\Api;

use App\Domain\Common\Enum\Timeframe;
use App\Repository\KlineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class KlinesApiController extends AbstractController
{
    public function __construct(
        private readonly KlineRepository $klineRepository,
    ) {
    }

    #[Route('/api/klines', name: 'api_klines', methods: ['GET'])]
    public function getKlines(Request $request): JsonResponse
    {
        $symbol = $request->query->get('symbol');
        $interval = $request->query->get('interval', '5m');
        $limit = (int) $request->query->get('limit', 100);

        if (!$symbol) {
            return new JsonResponse(['error' => 'Symbol parameter is required'], 400);
        }

        // Convertir l'intervalle string en enum Timeframe
        try {
            $timeframe = Timeframe::from($interval);
        } catch (\ValueError $e) {
            return new JsonResponse(['error' => "Invalid timeframe: $interval. Valid timeframes are: 1m, 5m, 15m, 1h, 4h"], 400);
        }

        $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);

        $data = [];
        foreach ($klines as $kline) {
            $data[] = [
                'openTime' => $kline->getOpenTime()->getTimestamp() * 1000, // Timestamp en millisecondes
                'open' => $kline->getOpenPriceFloat(),
                'high' => $kline->getHighPriceFloat(),
                'low' => $kline->getLowPriceFloat(),
                'close' => $kline->getClosePriceFloat(),
                'volume' => $kline->getVolumeFloat() ?? 0.0,
                'closeTime' => $kline->getOpenTime()->getTimestamp() * 1000, // Approximation
                'quoteAssetVolume' => $kline->getVolumeFloat() ?? 0.0,
                'numberOfTrades' => 0,
                'takerBuyBaseAssetVolume' => $kline->getVolumeFloat() ?? 0.0,
                'takerBuyQuoteAssetVolume' => $kline->getVolumeFloat() ?? 0.0,
            ];
        }

        return new JsonResponse($data);
    }
}
