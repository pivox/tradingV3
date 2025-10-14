<?php

namespace App\Controller\Bitmart;

use App\Entity\Kline;
use App\Repository\KlineRepository;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class KlinesByTimeframeGetController extends AbstractController
{
    #[Route('/api/bitmart/klines/by-timeframe', name: 'bitmart_klines_by_timeframe', methods: ['GET'])]
    public function __invoke(
        Request $request,
        BitmartFetcher $bitmartFetcher,
        KlinePersister $klinePersister,
        ClockInterface $clock,
        LoggerInterface $logger,
        KlineRepository $klineRepository
    ): JsonResponse {
        $symbol       = $request->query->get('symbol');       // ex: BTCUSDT
        $intervalsStr = $request->query->get('intervals') ?? "4h,1h,15m,5m,1m";
        $startStr     = $request->query->get('start');        // ex: 2025-09-21T00:00:00Z
        $endStr       = $request->query->get('end');          // ex: 2025-09-23T00:00:00Z
        $persist      = filter_var($request->query->get('persist', 'true'), FILTER_VALIDATE_BOOLEAN);
        $fromBitmart  = filter_var($request->query->get('from_bitmart', '0'), FILTER_VALIDATE_BOOLEAN);

        // Derniers N klines (par défaut 260, borne max 500)
        $limit = (int)($request->query->get('limit', '260'));
        if ($limit < 1)   { $limit = 1; }
        if ($limit > 500) { $limit = 500; }

        if (!$symbol || !$intervalsStr) {
            return $this->json(['error' => 'Missing symbol or intervals'], 400);
        }

        // -> ["4h","1h","15m",...]
        $intervals = array_map('trim', explode(',', strtolower($intervalsStr)));

        // Validation/normalisation des intervalles
        try {
            $validIntervals = [];
            foreach ($intervals as $itv) {
                $validIntervals[] = self::normalizeInterval($itv);
            }
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid intervals: ' . $e->getMessage()], 400);
        }

        // Borne temporelle globale
        try {
            $end   = $endStr ? self::parseDateOrTimestamp($endStr) : $clock->now();
            $start = $startStr ? self::parseDateOrTimestamp($startStr) : null;
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid date format: ' . $e->getMessage()], 400);
        }

        try {
            $payload = [
                'symbol'    => $symbol,
                'timezone'  => 'UTC',
                'intervals' => [],
            ];

            foreach ($validIntervals as $interval) {
                $step = self::intervalToStep($interval); // minutes par bougie

                if ($fromBitmart) {
                    // --- BRANCHE BITMART : "derniers N" si start absent, sinon plage explicite ---
                    $startForInterval = $start ?? $end->modify(sprintf('-%d minutes', $step * $limit));

                    $dtos = $bitmartFetcher->fetchKlines($symbol, $startForInterval, $end, $step);

                    if ($persist && !empty($dtos)) {
                        // flush immédiat pour éviter la croissance du UoW si gros volumes
                        $klinePersister->persistMany($symbol, $dtos, $step, flush: true);
                    }

                    $rows = array_map(static fn($dto) => [
                        'timestamp' => (new \DateTimeImmutable())->setTimestamp($dto->timestamp)->format(\DateTimeInterface::ATOM),
                        'open'      => (float)$dto->open,
                        'close'     => (float)$dto->close,
                        'high'      => (float)$dto->high,
                        'low'       => (float)$dto->low,
                        'volume'    => (float)$dto->volume,
                        'symbol'    => $symbol,
                        'interval'  => $interval,
                        'step'      => $step,
                    ], $dtos);

                } else {
                    // --- BRANCHE DB (par défaut) ---
                    if (!$start) {
                        // Derniers N klines pour ce step
                        $entities = $klineRepository->createQueryBuilder('k')
                            ->join('k.contract', 'c')
                            ->andWhere('c.symbol = :symbol')
                            ->andWhere('k.step = :step')
                            ->setParameter('symbol', $symbol)
                            ->setParameter('step', $step)
                            ->orderBy('k.timestamp', 'DESC')
                            ->setMaxResults($limit)
                            ->getQuery()
                            ->getResult();
                    } else {
                        // Plage temporelle explicite
                        $entities = $klineRepository->createQueryBuilder('k')
                            ->join('k.contract', 'c')
                            ->andWhere('c.symbol = :symbol')
                            ->andWhere('k.step = :step')
                            ->andWhere('k.timestamp >= :start')
                            ->andWhere('k.timestamp <= :end')
                            ->setParameter('symbol', $symbol)
                            ->setParameter('step', $step)
                            ->setParameter('start', $start)
                            ->setParameter('end', $end)
                            ->orderBy('k.timestamp', 'ASC')
                            ->setMaxResults($limit)
                            ->getQuery()
                            ->getResult();
                    }

                    $rows = array_map(static fn(Kline $e) => [
                        'timestamp' => $e->getTimestamp()
                            ->setTimezone(new \DateTimeZone('UTC'))
                            ->format(\DateTimeInterface::ATOM),
                        'open'      => (float)$e->getOpen(),
                        'close'     => (float)$e->getClose(),
                        'high'      => (float)$e->getHigh(),
                        'low'       => (float)$e->getLow(),
                        'volume'    => (float)$e->getVolume(),
                        'symbol'    => $symbol,
                        'interval'  => $interval,
                        'step'      => $step,
                    ], $entities);
                }

                // Tri final en ASC pour cohérence (notamment après lecture DESC des "derniers N")
                usort($rows, static fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
                $payload['intervals'][$interval] = $rows;
            }

            return $this->json($payload, 200);
        } catch (\Throwable $e) {
            $logger->error('by-timeframe GET error', ['exception' => $e]);
            return $this->json([
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ], 500);
        }
    }




    private static function parseDateOrTimestamp(string $input): \DateTimeImmutable
    {
        if (ctype_digit($input)) {
            // accepte secondes ou millis
            $ts = (int)$input;
            if (strlen($input) > 10) { $ts = intdiv($ts, 1000); }
            return (new \DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
        }
        return new \DateTimeImmutable($input, new DateTimeZone('UTC'));
    }

    private static function normalizeInterval(string $interval): string
    {
        $interval = strtolower(trim($interval));
        self::intervalToStep($interval); // lèvera si invalide
        return $interval;
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
        if (!isset($map[$interval])) {
            throw new \InvalidArgumentException("Invalid interval: $interval");
        }
        return $map[$interval];
    }
}
