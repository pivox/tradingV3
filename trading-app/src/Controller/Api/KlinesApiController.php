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
    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly KlineProviderInterface $klineProvider,
    ) {
    }

    #[Route('/api/klines', name: 'api_klines', methods: ['GET'])]
    public function getKlines(Request $request): JsonResponse
    {
        $symbol = $request->query->get('symbol');
        $interval = $request->query->get('interval', '5m');
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', 100)));

        if (!$symbol) {
            return new JsonResponse(['error' => 'Symbol parameter is required'], 400);
        }

        // Convertir l'intervalle string en enum Timeframe
        try {
            $timeframe = Timeframe::from($interval);
        } catch (\ValueError $e) {
            return new JsonResponse(['error' => "Invalid timeframe: $interval. Valid timeframes are: 1m, 5m, 15m, 1h, 4h"], 400);
        }

        $startRaw = $request->query->get('start');
        $endRaw = $request->query->get('end');
        if ($startRaw !== null && $startRaw !== '') {
            $start = $this->parseInstant((string) $startRaw);
            $end = $endRaw !== null && $endRaw !== '' ? $this->parseInstant((string) $endRaw) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($start === null || $end === null || $start >= $end) {
                return new JsonResponse(['error' => 'Invalid start/end: expected ISO-8601 or UTC milliseconds with start < end'], 400);
            }
            $klines = $this->klineProvider->getKlinesInWindow($symbol, $timeframe, $start, $end, $limit);
        } elseif ($endRaw !== null && $endRaw !== '') {
            return new JsonResponse(['error' => 'end requires start'], 400);
        } else {
            $klines = $this->klineProvider->getKlines($symbol, $timeframe, $limit);
        }

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

    private function parseInstant(string $value): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if (ctype_digit($value)) {
            return (new \DateTimeImmutable('@' . intdiv((int) $value, 1000)))->setTimezone($utc);
        }
        try {
            return (new \DateTimeImmutable($value, $utc))->setTimezone($utc);
        } catch (\Exception) {
            return null;
        }
    }
}
