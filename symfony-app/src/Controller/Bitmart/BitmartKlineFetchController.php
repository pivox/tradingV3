<?php

namespace App\Controller\Bitmart;

use App\Dto\FuturesKlineDto;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/klines/bitmart', name: 'klines_bitmart_fetch', methods: ['POST'])]
class BitmartKlineFetchController extends AbstractController
{
    public function __invoke(Request $request, KlinePersister $klinePersister, BitmartFetcher $bitmartFetcher): JsonResponse
    {
        $symbol = $request->query->get('symbol');
        $interval = $request->query->get('interval', '1h');
        $limit = (int) $request->query->get('limit', 200);

        if (!$symbol) {
            return $this->json(['error' => 'Missing symbol'], 400);
        }

        $stepMinutes = match($interval) {
            '1m' => 1, '3m' => 3, '5m' => 5, '15m' => 15, '30m' => 30, '1h' => 60, '4h' => 240, '1d' => 1440, '1w' => 10080, '1M' => 43200, default => 60
        };

        try {
            // Utilisation de la logique métier BitmartFetcher (identique à la callback)
            $dtos = $bitmartFetcher->fetchLatestKlines($symbol, $limit, $stepMinutes);
            \Symfony\Component\VarDumper\VarDumper::dump(['bitmart_dto_count' => count($dtos)]);
            $persisted = $klinePersister->persistMany($symbol, $dtos, $stepMinutes, true);
            return $this->json(['status' => 'Klines fetched and persisted', 'count' => count($persisted)]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        }
    }
}
