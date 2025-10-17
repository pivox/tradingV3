<?php

declare(strict_types=1);

namespace App\Domain\Mtf\Service;

use App\Domain\Common\Enum\Timeframe;
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
     */
    public function isInGraceWindow(\DateTimeImmutable $now, Timeframe $timeframe, int $graceMinutes = 4): bool
    {
        $alignedNow = $this->alignTimeframe($now, $timeframe);

        // Si aucun 3e argument explicite n'a été fourni, appliquer la politique par timeframe
        //  - 4h / 1h  => 4 minutes
        //  - 15m      => 2 minutes
        //  - 5m       => 1 minute
        //  - 1m       => 0 minute (pas de fenêtre de grâce)
        if (\func_num_args() < 3) {
            $graceMinutes = match ($timeframe) {
                Timeframe::TF_4H, Timeframe::TF_1H => 4,
                Timeframe::TF_15M => 2,
                Timeframe::TF_5M => 0,
                Timeframe::TF_1M => 0,
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

