<?php

namespace App\Controller;

use App\Repository\KlineRepository;
use App\Service\IntervalConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class IndicatorController extends AbstractController
{
    private HttpClientInterface $httpClient;
    private KlineRepository $klineRepository;
    private IntervalConverter $converter;
    private string $pythonApiBaseUrl;

    public function __construct(HttpClientInterface $httpClient, KlineRepository $klineRepository, IntervalConverter $converter, string $pythonApiBaseUrl)
    {
        $this->httpClient = $httpClient;
        $this->klineRepository = $klineRepository;
        $this->converter = $converter;
        $this->pythonApiBaseUrl = rtrim($pythonApiBaseUrl, '/');
    }

    #[Route('/api/indicator/{indicator}', name: 'app_indicator_generic', methods: ['GET'])]
    public function indicator(string $indicator, Request $request): JsonResponse
    {
        $allowedIndicators = ['rsi', 'macd', 'adx', 'bollinger']; // ajoute d'autres si besoin

        if (!in_array(strtolower($indicator), $allowedIndicators, true)) {
            return new JsonResponse(['error' => 'Indicator not supported'], 400);
        }

        $symbol = strtoupper($request->query->get('symbol', 'BTCUSDT'));
        $interval = $request->query->get('interval', '5m');
        $limit = (int) $request->query->get('limit', 100);

        $klines = $this->klineRepository->findRecentKlines($symbol, $this->converter->intervalToStep($interval), $limit);

        if (empty($klines)) {
            return new JsonResponse(['error' => 'No klines found in database'], 404);
        }

        $formattedKlines = array_map(fn($kline) => [
            'open' => (float) $kline->getOpen(),
            'high' => (float) $kline->getHigh(),
            'low' => (float) $kline->getLow(),
            'close' => (float) $kline->getClose(),
            'volume' => (float) $kline->getVolume(),
            'timestamp' => (int) $kline->getTimestamp()->getTimestamp(),
        ], $klines);

        $payload = [
            'symbol' => $symbol,
            'timeframe' => $interval,
            'klines' => $formattedKlines,
        ];
//        print(json_encode($payload));
//        die;

        $response = $this->httpClient->request('POST', "{$this->pythonApiBaseUrl}/indicator/" . strtolower($indicator), [
            'json' => $payload,
        ]);

        return new JsonResponse($response->toArray(false));
    }
}
