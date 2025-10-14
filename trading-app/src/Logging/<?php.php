<?php
// src/Logging/KlineFetchLogger.php
declare(strict_types=1);

namespace App\Logging;

use Psr\Log\LoggerInterface;
use DateTimeImmutable;
use DateTimeZone;

final class KlineFetchLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $localTimezone = 'Europe/Paris',
    ) {}

    /**
     * @param string $symbol        ex: "BTCUSDT"
     * @param string $timeframe     ex: "1m", "5m", "15m", "1h", "4h"
     * @param array  $klines        tableau de klines (JSON décodé)
     * @param string $source        ex: "rest" | "ws" | "cache"
     * @param array  $extraCtx      contexte additionnel (request_id, url, etc.)
     */
    public function logBatch(
        string $symbol,
        string $timeframe,
        array $klines,
        string $source = 'rest',
        array $extraCtx = []
    ): void {
        $count = \count($klines);

        // Récupération timestamp d’ouverture du 1er/dernier kline (tolérant différents schémas)
        [$firstTsMs, $lastTsMs] = $this->extractFirstLastOpenTimeMs($klines);

        // Dates courantes (locale)
        $tzLocal = new DateTimeZone($this->localTimezone);
        $nowLocal = new DateTimeImmutable('now', $tzLocal);

        // Format ISO-8601 avec offset
        $fmt = 'Y-m-d\TH:i:sP';

        // Conversion 1er/dernier en UTC + locale
        $firstUtc   = $firstTsMs !== null ? (new DateTimeImmutable('@' . (int)\floor($firstTsMs / 1000)))->setTimezone(new DateTimeZone('UTC')) : null;
        $lastUtc    = $lastTsMs  !== null ? (new DateTimeImmutable('@' . (int)\floor($lastTsMs  / 1000)))->setTimezone(new DateTimeZone('UTC')) : null;
        $firstLocal = $firstUtc?->setTimezone($tzLocal);
        $lastLocal  = $lastUtc?->setTimezone($tzLocal);

        $context = \array_filter([
            'symbol'            => $symbol,
            'timeframe'         => $timeframe,
            'source'            => $source,
            'count'             => $count,

            // horodatage "maintenant" côté app (locale)
            'now_local'         => $nowLocal->format($fmt),

            // 1er kline
            'first_kline_ts_ms' => $firstTsMs,
            'first_kline_utc'   => $firstUtc?->format($fmt),
            'first_kline_local' => $firstLocal?->format($fmt),

            // dernier kline
            'last_kline_ts_ms'  => $lastTsMs,
            'last_kline_utc'    => $lastUtc?->format($fmt),
            'last_kline_local'  => $lastLocal?->format($fmt),
        ]) + $extraCtx;

        $this->logger->info('KLINE_FETCH_BATCH', $context);
    }

    /**
     * Essaie de trouver les timestamps (en ms) du 1er et dernier kline
     * en s'adaptant aux conventions courantes:
     *  - tableau d’objets: ['openTime' => ms] ou ['t' => ms] ou ['open_time' => ms]
     *  - tableau de tableaux indexés: [ openTimeMs, open, high, low, close, volume, ... ]
     */
    private function extractFirstLastOpenTimeMs(array $klines): array
    {
        if ($klines === []) {
            return [null, null];
        }

        $getTsMs = static function ($row): ?int {
            // objet/assoc
            if (\is_array($row)) {
                foreach (['openTime', 't', 'open_time', 'timestamp', 'ts'] as $k) {
                    if (isset($row[$k]) && \is_numeric($row[$k])) {
                        return (int)$row[$k];
                    }
                }
                // tableau indexé (type binance/bitmart compat)
                // Hypothèse: index 0 = openTimeMs
                if (isset($row[0]) && \is_numeric($row[0])) {
                    // Certaines APIs renvoient des secondes -> si < 10^12, on multiplie par 1000
                    $v = (int)$row[0];
                    return $v < 10**12 ? $v * 1000 : $v;
                }
            }
            return null;
        };

        $firstTsMs = $getTsMs($klines[0]);
        $lastTsMs  = $getTsMs($klines[\count($klines)-1]);

        // Si l’API n’assure pas l’ordre, on re-sécurise en triant par ts
        if ($firstTsMs !== null && $lastTsMs !== null && $firstTsMs > $lastTsMs) {
            $tsList = [];
            foreach ($klines as $r) {
                $ts = $getTsMs($r);
                if ($ts !== null) {
                    $tsList[] = $ts;
                }
            }
            if ($tsList !== []) {
                \sort($tsList, \SORT_NUMERIC);
                $firstTsMs = $tsList[0] ?? $firstTsMs;
                $lastTsMs  = $tsList[\count($tsList)-1] ?? $lastTsMs;
            }
        }

        return [$firstTsMs, $lastTsMs];
    }
}
