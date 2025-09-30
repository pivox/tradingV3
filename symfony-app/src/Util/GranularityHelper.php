<?php
declare(strict_types=1);

namespace App\Util;

/**
 * Helper pour gérer les granularités (klines).
 *
 * - Certaines APIs (ex: Spot) attendent des secondes (60, 300…).
 * - BitMart Futures V2 attend des minutes (1, 3, 5, 15, 30, 60…).
 */
final class GranularityHelper
{
    /**
     * Mapping humain → secondes
     */
    private const MAP_SECONDS = [
        '1m'  => 60,
        '3m'  => 180,
        '5m'  => 300,
        '15m' => 900,
        '30m' => 1800,
        '1h'  => 3600,
        '4h'  => 14400,
        '1d'  => 86400,
    ];

    /**
     * Mapping humain → minutes (Futures V2 BitMart).
     */
    private const MAP_MINUTES = [
        '1m'  => 1,
        '3m'  => 3,
        '5m'  => 5,
        '15m' => 15,
        '30m' => 30,
        '1h'  => 60,
        '2h'  => 120,
        '4h'  => 240,
        '6h'  => 360,
        '12h' => 720,
        '1d'  => 1440,
        '3d'  => 4320,
        '1w'  => 10080,
    ];

    /**
     * Convertit une granularité (int ou "1m/15m/1h…") en secondes.
     *
     * @param int|string $granularity
     * @return int
     */
    public static function normalizeToSeconds(int|string $granularity): int
    {
        if (is_int($granularity)) {
            return $granularity; // déjà en secondes
        }

        $key = strtolower(trim($granularity));
        if (!isset(self::MAP_SECONDS[$key])) {
            throw new \InvalidArgumentException("Granularity invalide (seconds): $granularity");
        }

        return self::MAP_SECONDS[$key];
    }

    /**
     * Convertit une granularité (int ou "1m/15m/1h…") en minutes.
     * Spécifique Futures V2 (paramètre `step`).
     *
     * @param int|string $granularity
     * @return int
     */
    public static function normalizeToMinutes(int|string $granularity): int
    {
        if (is_int($granularity)) {
            return $granularity; // déjà en minutes
        }

        $key = strtolower(trim($granularity));
        if (!isset(self::MAP_MINUTES[$key])) {
            throw new \InvalidArgumentException("Granularity invalide (minutes): $granularity");
        }

        return self::MAP_MINUTES[$key];
    }

    /**
     * Retourne la granularité humaine à partir de secondes.
     *
     * @param int $seconds
     * @return string|null
     */
    public static function toHumanFromSeconds(int $seconds): ?string
    {
        $mapFlip = array_flip(self::MAP_SECONDS);
        return $mapFlip[$seconds] ?? null;
    }

    /**
     * Retourne la granularité humaine à partir de minutes.
     *
     * @param int $minutes
     * @return string|null
     */
    public static function toHumanFromMinutes(int $minutes): ?string
    {
        $mapFlip = array_flip(self::MAP_MINUTES);
        return $mapFlip[$minutes] ?? null;
    }
}
