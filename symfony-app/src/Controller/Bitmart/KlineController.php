<?php

namespace App\Controller\Bitmart;

use App\Entity\Kline;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\KlineService;
use DateTimeZone;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[OA\Tag(name: 'Bitmart')]
#[OA\Post(
    path: '/api/bitmart/kline',
    summary: 'Get Klines from BitMart (direct or persisted)',
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['symbol'],
            properties: [
                new OA\Property(property: 'symbol', type: 'string'),
                new OA\Property(property: 'start', type: 'string', format: 'date-time'),
                new OA\Property(property: 'end', type: 'string', format: 'date-time'),
                new OA\Property(property: 'step', type: 'integer'),
                new OA\Property(property: 'persist', type: 'boolean'),
            ],
            type: 'object'
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Klines data returned'),
        new OA\Response(response: 400, description: 'Invalid parameters'),
        new OA\Response(response: 500, description: 'Internal error')
    ]
)]
class KlineController extends AbstractController
{
    public function __invoke(
        Request $request,
        KlineService $klineService,
        BitmartFetcher $bitmartFetcher,
        ClockInterface $clock,
        LoggerInterface $logger
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $symbol = $data['symbol'] ?? null;
        $startStr = $data['start'] ?? null;
        $endStr = $data['end'] ?? null;
        $interval = $data['interval'] ?? '15m';
        $persist = isset($data['persist']) ? filter_var($data['persist'], FILTER_VALIDATE_BOOLEAN) : true;

        if (!$symbol) {
            return $this->json(['error' => 'Missing symbol'], 400);
        }

        try {
            $step = self::intervalToStep($interval);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid interval: ' . $e->getMessage()], 400);
        }
        $logger->info(str_pad('-', 100, '-'));
        $logger->info(str_pad('-', 100, '-'));
        try {
            $end = $endStr ? self::parseDateOrTimestamp($endStr) : $clock->now();
            $start = $startStr
                ? self::parseDateOrTimestamp($startStr)
                : $end->modify(sprintf('-%d minutes', 100 * $step));
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid date format: ' . $e->getMessage()], 400);
        }

        try {
            $logger->info(json_encode(['persist' => $persist]));
            if ($persist) {
                $klines = $klineService->fetchMissingAndReturnAll($symbol, $start, $end, $step);
            } else {
                $dtos = $bitmartFetcher->fetchKlines($symbol, $start, $end, $step);
                $klines = array_map(fn($dto) => $dto->toArray(), $dtos);
            }

            $logger->info(sprintf('[Bitmart] Fetched klines: %s %s → %s', $symbol, $startStr, $endStr));

            return $this->json(array_map(
                fn(Kline $k) => [
                    'timestamp' => $k->getTimestamp()->format(DATE_ATOM),
                    'open' => $k->getOpen(),
                    'close' => $k->getClose(),
                    'high' => $k->getHigh(),
                    'low' => $k->getLow(),
                    'volume' => $k->getVolume(),
                    'symbol' => $k->getContract()->getSymbol(),
                    'step' => $k->getStep(),
                ],
                $klines
            ));
        } catch (\Throwable $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        }

    }

    private static function parseDateOrTimestamp(string $input): \DateTimeImmutable
    {
        if (ctype_digit($input)) {
            return (new \DateTimeImmutable())->setTimestamp((int) $input);
        }

        return new \DateTimeImmutable($input, new DateTimeZone('UTC'));
    }

    private static function intervalToStep(string $interval): int
    {
        $map = [
            '1m' => 1,
            '3m' => 3,
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '2h' => 120,
            '4h' => 240,
            '1d' => 1440,
        ];

        return $map[$interval] ?? throw new \InvalidArgumentException("Invalid interval: $interval");
    }
}
