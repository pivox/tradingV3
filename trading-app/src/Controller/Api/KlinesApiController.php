<?php

namespace App\Controller\Api;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\KlineProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class KlinesApiController extends AbstractController
{
    public function __construct(
        private readonly KlineProviderInterface $klineProvider,
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

        $klines = $this->klineProvider->getKlines($symbol, $timeframe, $limit);

        if (empty($klines)) {
            return new JsonResponse([]);
        }

        // Les klines renvoyées par le provider sont triées du plus ancien au plus récent.
        // On applique un tri inverse pour conserver le comportement historique (dernier en premier).
        usort($klines, static function ($a, $b): int {
            return $b->openTime <=> $a->openTime;
        });

        $data = array_map(static function ($kline) {
            $volume = $kline->volume?->toFloat() ?? 0.0;

            return [
                'openTime' => $kline->openTime->getTimestamp() * 1000,
                'open' => $kline->open->toFloat(),
                'high' => $kline->high->toFloat(),
                'low' => $kline->low->toFloat(),
                'close' => $kline->close->toFloat(),
                'volume' => $volume,
                'closeTime' => $kline->openTime->getTimestamp() * 1000, // Approximation conservée
                'quoteAssetVolume' => $volume,
                'numberOfTrades' => 0,
                'takerBuyBaseAssetVolume' => $volume,
                'takerBuyQuoteAssetVolume' => $volume,
            ];
        }, $klines);

        return new JsonResponse($data);
    }
}
