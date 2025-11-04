<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Common\Enum\Timeframe;
use Psr\Clock\ClockInterface;

final class MtfTimeService
{
    public function __construct(
        private readonly ClockInterface $clock
    ) {
    }
    /**
     * Aligne un timestamp sur les bornes exactes d'un timeframe
     */
    public function alignTimeframe(\DateTimeImmutable $timestamp, Timeframe $timeframe): \DateTimeImmutable
    {
        $stepSeconds = $timeframe->getStepInSeconds();
        $timestampSeconds = $timestamp->getTimestamp();

        // Aligner sur la borne inférieure
        $alignedSeconds = intval($timestampSeconds / $stepSeconds) * $stepSeconds;

        return $this->clock->now()->setTimestamp($alignedSeconds);
    }

    /**
     * Calcule la dernière bougie fermée pour un timeframe
     */
    public function getLastClosedKlineTime(\DateTimeImmutable $now, Timeframe $timeframe): \DateTimeImmutable
    {
        $alignedNow = $this->alignTimeframe($now, $timeframe);
        $stepSeconds = $timeframe->getStepInSeconds();

        // Retourner la bougie précédente (fermée)
        return $alignedNow->modify("-{$stepSeconds} seconds");
    }

    /**
     * Calcule la prochaine clôture pour un timeframe
     */
    public function getNextCloseTime(\DateTimeImmutable $now, Timeframe $timeframe): \DateTimeImmutable
    {
        $alignedNow = $this->alignTimeframe($now, $timeframe);
        $stepSeconds = $timeframe->getStepInSeconds();

        // Si on est exactement sur une borne, la prochaine clôture est dans un step
        if ($alignedNow->getTimestamp() === $now->getTimestamp()) {
            return $alignedNow->modify("+{$stepSeconds} seconds");
        }

        // Sinon, la prochaine clôture est la borne suivante
        return $alignedNow->modify("+{$stepSeconds} seconds");
    }

    /**
     * Vérifie si on est dans la fenêtre de grâce pour un timeframe
     * 
     * IMPORTANT: Dans le contexte MTF, cette méthode ne devrait être appelée qu'avec
     * les 5 timeframes supportés: TF_4H, TF_1H, TF_15M, TF_5M, TF_1M.
     * 
     * Si un timeframe non géré est passé, cela indique un bug dans le système MTF :
     * - Vérifier que les services Timeframe*Service retournent uniquement les timeframes attendus
     * - Vérifier qu'aucun timeframe non MTF (TF_1D, TF_30M, etc.) n'est utilisé dans le flux MTF
     * - Vérifier que computeAtrValue() et autres méthodes utilisent uniquement les timeframes MTF
     */
    public function isInGraceWindow(\DateTimeImmutable $now, Timeframe $timeframe, int $graceMinutes = 4): bool
    {
        $alignedNow = $this->alignTimeframe($now, $timeframe);

        // Si aucun 3e argument explicite n'a été fourni, appliquer la politique par timeframe
        //  - 4h / 1h  => 1 minutes
        //  - 15m      => 1 minutes
        //  - 5m       => 1 minute
        //  - 1m       => 0 minute (pas de fenêtre de grâce)
        // 
        // GARDE-FOU: Si un timeframe non MTF est passé, cela indique un bug à corriger
        if (\func_num_args() < 3) {
            $graceMinutes = match ($timeframe) {
                Timeframe::TF_4H, Timeframe::TF_1H => 1,
                Timeframe::TF_15M, Timeframe::TF_5M => 1,
                Timeframe::TF_1M => 0,
                default => throw new \InvalidArgumentException(
                    sprintf(
                        'Unhandled timeframe %s (%s) in isInGraceWindow. '
                        . 'This timeframe is not supported in MTF validation context. '
                        . 'Only TF_4H, TF_1H, TF_15M, TF_5M, TF_1M are allowed. '
                        . 'If you see this error, check: (1) Timeframe*Service implementations return correct timeframes, '
                        . '(2) No non-MTF timeframes (TF_1D, TF_30M, etc.) are used in MTF flow, '
                        . '(3) computeAtrValue() and other methods use only MTF timeframes.',
                        $timeframe->name,
                        $timeframe->value
                    )
                ),
            };
        }

        if ($graceMinutes <= 0) {
            return false; // aucune fenêtre de grâce
        }

        $graceSeconds = $graceMinutes * 60;
        $graceEnd = $alignedNow->modify("+{$graceSeconds} seconds");

        return $now >= $alignedNow && $now <= $graceEnd;
    }

    /**
     * Calcule le TTL pour le cache de validation
     */
    public function getValidationCacheTtl(\DateTimeImmutable $now, Timeframe $timeframe): \DateTimeImmutable
    {
        return $this->getNextCloseTime($now, $timeframe);
    }

    /**
     * Vérifie si une bougie est récente (dans les dernières 2 périodes)
     */
    public function isRecentKline(\DateTimeImmutable $klineTime, \DateTimeImmutable $now, Timeframe $timeframe): bool
    {
        $stepSeconds = $timeframe->getStepInSeconds();
        $maxAge = $stepSeconds * 2; // 2 périodes

        return ($now->getTimestamp() - $klineTime->getTimestamp()) <= $maxAge;
    }

    /**
     * Calcule la fenêtre de backfill pour récupérer les klines manquantes
     */
    public function getBackfillWindow(\DateTimeImmutable $from, \DateTimeImmutable $to, Timeframe $timeframe): array
    {
        $stepSeconds = $timeframe->getStepInSeconds();
        $windows = [];

        $current = $this->alignTimeframe($from, $timeframe);
        $end = $this->alignTimeframe($to, $timeframe);

        while ($current <= $end) {
            $windowEnd = $current->modify("+{$stepSeconds} seconds");
            $windows[] = [
                'start' => $current,
                'end' => $windowEnd
            ];
            $current = $windowEnd;
        }

        return $windows;
    }

    /**
     * Découpe une fenêtre en chunks de 500 bougies max pour l'API
     */
    public function chunkBackfillWindow(array $window, Timeframe $timeframe, int $maxCandles = 500): array
    {
        $stepSeconds = $timeframe->getStepInSeconds();
        $maxWindowSeconds = $maxCandles * $stepSeconds;

        $chunks = [];
        $current = $window['start'];
        $end = $window['end'];

        while ($current < $end) {
            $chunkEnd = $current->modify("+{$maxWindowSeconds} seconds");
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $chunks[] = [
                'start' => $current,
                'end' => $chunkEnd
            ];

            $current = $chunkEnd;
        }

        return $chunks;
    }

    /**
     * Calcule l'heure UTC courante alignée
     */
    public function getCurrentAlignedUtc(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * Vérifie si deux timestamps sont dans la même période de timeframe
     */
    public function isSameTimeframePeriod(\DateTimeImmutable $time1, \DateTimeImmutable $time2, Timeframe $timeframe): bool
    {
        $aligned1 = $this->alignTimeframe($time1, $timeframe);
        $aligned2 = $this->alignTimeframe($time2, $timeframe);

        return $aligned1->getTimestamp() === $aligned2->getTimestamp();
    }
}

