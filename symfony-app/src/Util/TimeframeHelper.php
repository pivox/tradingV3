<?php

declare(strict_types=1);

namespace App\Util;

final class TimeframeHelper
{
    public static function parseTimeframeToMinutes(string|int $tf): int
    {
        if (\is_int($tf)) {
            return $tf; // déjà en minutes
        }
        if (!preg_match('/^(?<n>\d+)(?<u>[mhdw])$/i', trim($tf), $m)) {
            throw new \InvalidArgumentException("Invalid timeframe format: $tf");
        }
        $n = (int) $m['n'];
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
     * Retourne l'ouverture (UTC) de la tranche courante alignée pour un timeframe en minutes.
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

    public static function getAlignedOpen(string|int $timeframe, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $mins = self::parseTimeframeToMinutes($timeframe);
        return self::getAlignedOpenByMinutes($mins, $now);
    }

    /**
     * Début de la DERNIÈRE bougie CLÔTURÉE (UTC) pour un timeframe en minutes.
     * Utile pour REST Futures V2 : on ne veut pas la bougie en cours.
     */
    public static function getAlignedCloseByMinutes(int $timeframeMinutes, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $openCurrent = self::getAlignedOpenByMinutes($timeframeMinutes, $now);      // début tranche courante
        $closedTs = $openCurrent->getTimestamp() - ($timeframeMinutes * 60);        // recule d’une tranche
        return (new \DateTimeImmutable("@$closedTs"))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function getAlignedClose(string|int $timeframe, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $mins = self::parseTimeframeToMinutes($timeframe);
        return self::getAlignedCloseByMinutes($mins, $now);
    }

    /**
     * Calcule une fenêtre (fromTs, toTs) ALIGNÉE et CLÔTURÉE en secondes (UTC),
     * pour N bougies REST (Futures V2).
     *
     * @return array{fromTs:int,toTs:int}
     */
    public static function getClosedWindowByMinutes(int $timeframeMinutes, int $limit, ?int $nowTs = null): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be >= 1');
        }
        $now = new \DateTimeImmutable(
            $nowTs !== null ? "@$nowTs" : 'now',
            new \DateTimeZone('UTC')
        );

        $closeOpen = self::getAlignedCloseByMinutes($timeframeMinutes, $now); // début de la dernière bougie CLOSE
        $toTs   = $closeOpen->getTimestamp();                                  // borne supérieure (incluse)
        $fromTs = $toTs - ($limit * $timeframeMinutes * 60);                   // N bougies en arrière

        return ['fromTs' => $fromTs, 'toTs' => $toTs];
    }

    /**
     * Calcule une fenêtre de N bougies **clôturées** se terminant AVANT ou À la date d’ancrage.
     * @param int $timeframeMinutes  ex: 15 (pour 15m)
     * @param int $limit             nombre de bougies
     * @param \DateTimeImmutable $anchor Date/heure d'ancrage (Europe/Paris accepté)
     * @param bool $strictBefore     true => fenêtre se termine STRICTEMENT avant anchor
     */
    public static function closedWindowAt(
        int $timeframeMinutes,
        int $limit,
        \DateTimeImmutable $anchor,
        bool $strictBefore = true
    ): TimeWindow {
        $stepSec = $timeframeMinutes * 60;

        // Convertit l’ancrage en UTC
        $anchorUtc = $anchor->setTimezone(new \DateTimeZone('UTC'));
        $anchorSec = $anchorUtc->getTimestamp();

        // Début tranche courante contenant anchor
        $currentOpen = intdiv($anchorSec, $stepSec) * $stepSec;

        // Borne de fin = début de la DERNIÈRE bougie CLOSE avant (ou à) anchor
        $toTs = $strictBefore
            ? $currentOpen - $stepSec           // strictement avant la tranche d'anchor
            : (($anchorSec % $stepSec) === 0    // si anchor pile sur une borne, on peut la prendre
                ? $anchorSec - $stepSec
                : $currentOpen - $stepSec);

        if ($toTs <= 0) {
            throw new \InvalidArgumentException('Anchor trop tôt dans l’epoch.');
        }

        $fromTs = $toTs - $limit * $stepSec;

        return new TimeWindow($fromTs, $toTs);
    }
}
