<?php

namespace App\Controller;

use App\Service\Exchange\Bitmart\Dto\KlineDto;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class KlinesController
{
    public function __construct(private BitmartFetcher $fetcher) {}

    #[Route('/api/klines', name: 'api_klines', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $symbol = $request->query->get('symbol');
        $start = $request->query->get('start');
        $end = $request->query->get('end');
        $step = (int) $request->query->get('step', 1);
        $allowedSteps = [1,3,5,15,30,60,120,240,360,720,1440,4320,10080];
        if (!in_array($step, $allowedSteps, true)) {
            return new JsonResponse(['error' => 'Invalid step value'], 400);
        }

        if (!$symbol || !$start || !$end) {
            return new JsonResponse(['error' => 'Missing required parameters'], 400);
        }

        try {
            $startTime = new \DateTimeImmutable($start);
            $endTime = new \DateTimeImmutable($end);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid date format'], 400);
        }

        $klines = $this->fetcher->fetchKlines($symbol, $startTime, $endTime, $step);

        return new JsonResponse(array_map(fn($k) => [
            'timestamp' => $k->timestamp->format('Y-m-d H:i:s'),
            'open' => $k->open,
            'high' => $k->high,
            'low' => $k->low,
            'close' => $k->close,
            'volume' => $k->volume,
        ], $klines));
    }
}
