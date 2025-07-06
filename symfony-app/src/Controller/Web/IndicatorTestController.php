<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class IndicatorTestController extends AbstractController
{
    public function __construct(private HttpClientInterface $httpClient) {}

    #[Route('/indicators/test', name: 'indicators_test')]
    public function test(Request $request): Response
    {
        $results = [];
        $symbol = $request->get('symbol', 'SOLUSDT');
        $interval = $request->get('interval', '1m');
        $limit = (int) $request->get('limit', 50);

        if ($request->isMethod('POST')) {
            $bitmartUrl = "https://api-cloud.bitmart.com/contract/public/kline?symbol=$symbol&interval=$interval&limit=$limit";

            $response = $this->httpClient->request('GET', $bitmartUrl);
            $json = $response->toArray(false);

            $closePrices = array_map(fn($row) => (float) $row[4], $json['data']['klines'] ?? []);

            // Appel API Python
            foreach (['rsi', 'macd'] as $indicator) {
                $payload = [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'close_prices' => $closePrices,
                ];
                if ($indicator === 'rsi') {
                    $payload['period'] = 14;
                }

                $pythonResponse = $this->httpClient->request(
                    'POST',
                    "http://indicator_api:8000/api/$indicator",
                    ['json' => $payload]
                );

                $results[$indicator] = $pythonResponse->toArray(false);
            }
        }

        return $this->render('indicators/test.html.twig', [
            'rsi' => $results['rsi'] ?? null,
            'macd' => $results['macd'] ?? null,
        ]);
    }
}
