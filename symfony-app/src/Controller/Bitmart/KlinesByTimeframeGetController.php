<?php

namespace App\Controller\Bitmart;

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
        LoggerInterface $logger
    ): JsonResponse {
        $symbol    = $request->query->get('symbol');       // ex: BTCUSDT
        $intervals = $request->query->get('intervals') ?? "4h,1h,15m,5m,1m";    // ex: "4h,1h,15m"
        $startStr  = $request->query->get('start');        // ex: 2025-09-21T00:00:00Z
        $endStr    = $request->query->get('end');          // ex: 2025-09-23T00:00:00Z
        $persist   = filter_var($request->query->get('persist', 'true'), FILTER_VALIDATE_BOOLEAN);

        if (!$symbol || !$intervals) {
            return $this->json(['error' => 'Missing symbol or intervals'], 400);
        }

        // -> ["4h","1h","15m"]
        $intervals = array_map('trim', explode(',', strtolower($intervals)));

        try {
            $validIntervals = [];
            foreach ($intervals as $itv) {
                $validIntervals[] = self::normalizeInterval($itv);
            }
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid intervals: ' . $e->getMessage()], 400);
        }

        // plage temporelle
        try {
            $end   = $endStr ? self::parseDateOrTimestamp($endStr) : $clock->now();
            $start = $startStr
                ? self::parseDateOrTimestamp($startStr)
                : $end->modify(sprintf('-%d minutes', 100 * min(array_map([self::class, 'intervalToStep'], $validIntervals))));
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
                $step = self::intervalToStep($interval);

                // 1) fetch DTOs depuis Bitmart (chunké dans BitmartFetcher)
                $dtos = $bitmartFetcher->fetchKlines($symbol, $start, $end, $step);

                // 2) persist optionnel (dédoublonnage via unique index contract/step/timestamp)
                if ($persist && !empty($dtos)) {
                    // flush immédiat pour éviter la croissance du UoW si gros volumes
                    $klinePersister->persistMany($symbol, $dtos, $step, flush: true);
                }

                // 3) réponse : on renvoie ce qu’on a *fetché* (cohérent même si rien n’était en base)
                $list = array_map(static fn($dto) => [
                    'timestamp' => (new DateTimeImmutable())->setTimestamp($dto->timestamp)->format(DateTimeInterface::ATOM),
                    'open'      => (float)$dto->open,
                    'close'     => (float)$dto->close,
                    'high'      => (float)$dto->high,
                    'low'       => (float)$dto->low,
                    'volume'    => (float)$dto->volume,
                    'symbol'    => $symbol,
                    'interval'  => $interval,
                    'step'      => $step,
                ], $dtos);

                usort($list, static fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
                $payload['intervals'][$interval] = $list;
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
