<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\Service;

use App\Contract\Provider\MainProviderInterface;
use App\Provider\Bitmart\Dto\KlineDto;
use App\Common\Enum\Timeframe;
use App\Provider\Repository\KlineRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

class KlineFetcher
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly KlineRepository $klineRepository,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Récupère et sauvegarde les klines pour un symbole et timeframe
     */
    public function fetchAndSaveKlines(string $symbol, Timeframe $timeframe, int $limit = 270): array
    {
        try {
            $this->logger->info('[Bitmart KlineFetcher] Fetching klines', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'limit' => $limit
            ]);

            // Convertir le timeframe en step Bitmart
            $step = $this->convertTimeframeToStep($timeframe);

            // Récupérer les klines depuis l'API Bitmart
            $klinesData = $this->mainProvider->getKlineProvider()->getKlines(
                symbol: $symbol,
                timeframe: $timeframe,
                limit: $limit
            );

            if (!empty($klinesData)) {
                $this->klineRepository->saveKlines($klinesData, $symbol, $timeframe);
                $this->logger->info('[Bitmart KlineFetcher] Klines saved successfully', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'count' => $klinesData->count()
                ]);
            }

            return $klinesData;

        } catch (\Exception $e) {
            $this->logger->error('[Bitmart KlineFetcher] Failed to fetch and save klines', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Remplit les gaps dans les données de klines
     */
    public function fillGaps(string $symbol, Timeframe $timeframe): int
    {
        if (!$this->mainProvider->getKlineProvider()->hasGaps($symbol, $timeframe)) {
            return 0;
        }

        $gaps = $this->mainProvider->getKlineProvider()->getGaps($symbol, $timeframe);
        $filledCount = 0;

        foreach ($gaps as $gap) {
            $klines = $this->fetchAndSaveKlines($symbol, $timeframe, $gap['count'] ?? 100);
            $filledCount += count($klines);
        }

        return $filledCount;
    }

    /**
     * Récupère les klines nécessaires pour le calcul des indicateurs
     */
    public function getKlinesForIndicators(string $symbol, Timeframe $timeframe, int $requiredPeriods = 250): array
    {
        $klines = $this->mainProvider->getKlineProvider()->getKlines($symbol, $timeframe, $requiredPeriods);

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
        $lastKline = $this->mainProvider->getKlineProvider()->getLastKline($symbol, $timeframe);

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
        $lastKline = $this->mainProvider->getKlineProvider()->getLastKline($symbol, $timeframe);

        if ($lastKline === null || !$this->isDataUpToDate($symbol, $timeframe)) {
            $newKlines = $this->fetchAndSaveKlines($symbol, $timeframe, 1);
            $lastKline = !empty($newKlines) ? $newKlines[0] : $lastKline;
        }

        return $lastKline;
    }

    /**
     * Récupère les klines récentes pour un symbole et timeframe
     */
    public function fetchRecentKlines(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        try {
            $this->logger->info('[Bitmart KlineFetcher] Fetching recent klines', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'limit' => $limit
            ]);

            $step = $this->convertTimeframeToStep($timeframe);
            $klinesData = $this->mainProvider->getKlineProvider()->getKlines($symbol, $timeframe, $limit);

            if (empty($klinesData)) {
                return [];
            }


            if (!empty($klinesData)) {
                $this->mainProvider->getKlineProvider()->saveKlines($klinesData, $symbol, $timeframe);
            }

            return $klinesData;

        } catch (\Exception $e) {
            $this->logger->error('[Bitmart KlineFetcher] Failed to fetch recent klines', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Convertit un timeframe en step Bitmart
     */
    private function convertTimeframeToStep(Timeframe $timeframe): int
    {
        return match ($timeframe) {
            Timeframe::TF_1M => 1,
            Timeframe::TF_5M => 5,
            Timeframe::TF_15M => 15,
            Timeframe::TF_30M => 30,
            Timeframe::TF_1H => 60,
            Timeframe::TF_4H => 240,
        };
    }

}
