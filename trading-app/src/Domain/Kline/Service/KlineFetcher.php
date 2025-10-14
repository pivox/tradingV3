<?php

declare(strict_types=1);

namespace App\Domain\Kline\Service;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Ports\Out\KlineProviderPort;
use Psr\Clock\ClockInterface;

class KlineFetcher
{
    public function __construct(
        private readonly KlineProviderPort $klineProvider,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * Récupère et sauvegarde les klines pour un symbole et timeframe
     */
    public function fetchAndSaveKlines(string $symbol, Timeframe $timeframe, int $limit = 1000): array
    {
        $klines = $this->klineProvider->fetchKlines($symbol, $timeframe, $limit);
        
        if (!empty($klines)) {
            $this->klineProvider->saveKlines($klines);
        }
        
        return $klines;
    }

    /**
     * Remplit les gaps dans les données de klines
     */
    public function fillGaps(string $symbol, Timeframe $timeframe): int
    {
        if (!$this->klineProvider->hasGaps($symbol, $timeframe)) {
            return 0;
        }

        $gaps = $this->klineProvider->getGaps($symbol, $timeframe);
        $filledCount = 0;

        foreach ($gaps as $gap) {
            $klines = $this->klineProvider->fetchKlines(
                $symbol, 
                $timeframe, 
                $gap['count']
            );
            
            if (!empty($klines)) {
                $this->klineProvider->saveKlines($klines);
                $filledCount += count($klines);
            }
        }

        return $filledCount;
    }

    /**
     * Récupère les klines nécessaires pour le calcul des indicateurs
     */
    public function getKlinesForIndicators(string $symbol, Timeframe $timeframe, int $requiredPeriods = 200): array
    {
        $klines = $this->klineProvider->getKlines($symbol, $timeframe, $requiredPeriods);
        
        if (count($klines) < $requiredPeriods) {
            // Récupérer plus de données si nécessaire
            $additionalKlines = $this->fetchAndSaveKlines($symbol, $timeframe, $requiredPeriods);
            $klines = array_merge($additionalKlines, $klines);
        }
        
        return array_slice($klines, -$requiredPeriods);
    }

    /**
     * Vérifie si les données sont à jour
     */
    public function isDataUpToDate(string $symbol, Timeframe $timeframe): bool
    {
        $lastKline = $this->klineProvider->getLastKline($symbol, $timeframe);
        
        if ($lastKline === null) {
            return false;
        }

        $now = $this->clock->now();
        $expectedNextTime = $lastKline->openTime->modify('+' . $timeframe->getStepInMinutes() . ' minutes');
        
        // Tolérance de 5 minutes pour les délais de traitement
        $tolerance = new \DateInterval('PT5M');
        
        return $now->sub($tolerance) <= $expectedNextTime;
    }

    /**
     * Récupère la dernière kline ou en fetch une nouvelle si nécessaire
     */
    public function getOrFetchLastKline(string $symbol, Timeframe $timeframe): ?KlineDto
    {
        $lastKline = $this->klineProvider->getLastKline($symbol, $timeframe);
        
        if ($lastKline === null || !$this->isDataUpToDate($symbol, $timeframe)) {
            $newKlines = $this->fetchAndSaveKlines($symbol, $timeframe, 1);
            $lastKline = !empty($newKlines) ? $newKlines[0] : $lastKline;
        }
        
        return $lastKline;
    }
}



