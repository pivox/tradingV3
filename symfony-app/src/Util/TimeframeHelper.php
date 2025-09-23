<?php

namespace App\Util;

class TimeframeHelper
{
    public static function parseTimeframeToMinutes(string $tf): int
    {
        if (!preg_match('/^(?<n>\d+)(?<u>[mhdw])$/i', trim($tf), $m)) {
            throw new \InvalidArgumentException("Invalid timeframe format: $tf");
        }
        $n = (int)$m['n'];
        $u = strtolower($m['u']);

        return match ($u) {
            'm' => $n,
            'h' => $n * 60,
            'd' => $n * 60 * 24,
            'w' => $n * 60 * 24 * 7,
            default => throw new \InvalidArgumentException("Unsupported unit: $u"),
        };
    }

    /**
     * Retourne le début du dernier « candle » aligné pour un timeframe en minutes.
     * Exemple 4h: 00:00, 04:00, 08:00, …
     */
    public static function getAlignedOpenByMinutes(int $timeframeMinutes, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $minutesSinceEpoch = intdiv($now->getTimestamp(), 60);
        $steps = intdiv($minutesSinceEpoch, $timeframeMinutes);
        $alignedTs = $steps * $timeframeMinutes * 60; // seconds
        return (new \DateTimeImmutable("@$alignedTs"))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function getAlignedOpen(string $timeframe, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $mins = self::parseTimeframeToMinutes($timeframe);
        return self::getAlignedOpenByMinutes($mins, $now);
    }
}
