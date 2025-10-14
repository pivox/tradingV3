<?php

declare(strict_types=1);

namespace App\Domain\Mtf\Service;

use App\Domain\Common\Enum\Timeframe;
use App\Infrastructure\Persistence\KlineJsonIngestionService;
use App\Infrastructure\Http\BitmartRestClient;
use Psr\Log\LoggerInterface;
use Psr\Clock\ClockInterface;

/**
 * NOUVELLE LOGIQUE SIMPLIFIÉE POUR MtfService::processTimeframe()
 * 
 * Remplace la logique complexe de backfill par chunks par une insertion en masse simple
 */
class MtfServiceSimplified
{
    public function __construct(
        private readonly KlineJsonIngestionService $klineJsonIngestion,
        private readonly BitmartRestClient $bitmartClient,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock
    ) {}

    /**
     * NOUVELLE MÉTHODE : Remplit les klines manquantes en masse
     */
    private function fillMissingKlinesInBulk(
        string $symbol, 
        Timeframe $timeframe, 
        int $requiredLimit, 
        \DateTimeImmutable $now,
        UuidInterface $runId
    ): void {
        $this->logger->info('[MTF] Filling missing klines in bulk', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'required_limit' => $requiredLimit
        ]);

        // Calculer la période à récupérer
        $intervalMinutes = $timeframe->getStepInMinutes();
        $startDate = (clone $now)->sub(new \DateInterval('PT' . ($requiredLimit * $intervalMinutes) . 'M'));
        
        // Fetch toutes les klines manquantes d'un coup
        $fetchedKlines = $this->bitmartClient->fetchKlinesInWindow(
            $symbol,
            $timeframe,
            $startDate,
            $now,
            $requiredLimit * 2 // Récupérer un peu plus pour être sûr
        );

        if (empty($fetchedKlines)) {
            $this->logger->warning('[MTF] No klines fetched from BitMart', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value
            ]);
            return;
        }

        // Convertir en format JSON pour l'insertion en masse
        $jsonPayload = $this->convertKlinesToJsonPayload($fetchedKlines);
        
        // Insertion en masse via la fonction SQL JSON
        $result = $this->klineJsonIngestion->ingestKlinesBatch($jsonPayload);
        
        $this->logger->info('[MTF] Bulk klines insertion completed', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'fetched_count' => count($fetchedKlines),
            'inserted_count' => $result->count,
            'duration_ms' => $result->durationMs
        ]);
    }

    /**
     * Convertit les KlineDto en format JSON pour l'insertion
     */
    private function convertKlinesToJsonPayload(array $klines): array
    {
        return array_map(function($klineDto) {
            return [
                'symbol' => $klineDto->symbol,
                'timeframe' => $klineDto->timeframe->value,
                'open_time' => $klineDto->openTime->format('c'), // ISO 8601
                'open_price' => (string) $klineDto->open,
                'high_price' => (string) $klineDto->high,
                'low_price' => (string) $klineDto->low,
                'close_price' => (string) $klineDto->close,
                'volume' => $klineDto->volume ? (string) $klineDto->volume : null,
                'source' => $klineDto->source ?? 'REST'
            ];
        }, $klines);
    }

    /**
     * MÉTHODE PRINCIPALE MODIFIÉE : processTimeframe() simplifiée
     */
    private function processTimeframeSimplified(
        string $symbol, 
        Timeframe $timeframe, 
        UuidInterface $runId, 
        \DateTimeImmutable $now, 
        array &$collector, 
        bool $forceTimeframeCheck = false, 
        bool $forceRun = false
    ): array {
        $limit = 270; // fallback
        try {
            $cfg = $this->mtfConfig->getConfig();
            $limit = (int)($cfg['timeframes'][$timeframe->value]['guards']['min_bars'] ?? 270);
        } catch (\Throwable $ex) {
            $this->logger->error("[MTF] Error loading config for {$timeframe->value}, using default limit", ['error' => $ex->getMessage()]);
        }
        
        // 🔥 NOUVELLE LOGIQUE : Charger les klines existantes
        $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);
        
        // 🔥 NOUVELLE LOGIQUE : Si pas assez de klines → INSÉRER EN MASSE
        if (count($klines) < $limit) {
            $this->logger->info('[MTF] Insufficient klines, filling in bulk', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'current_count' => count($klines),
                'required_count' => $limit
            ]);
            
            // Remplir les klines manquantes en masse
            $this->fillMissingKlinesInBulk($symbol, $timeframe, $limit, $now, $runId);
            
            // Recharger les klines après insertion
            $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);
            
            // Si toujours pas assez après insertion → désactiver temporairement
            if (count($klines) < $limit) {
                $missingBars = $limit - count($klines);
                $duration = ($missingBars * $timeframe->getStepInMinutes() + $timeframe->getStepInMinutes()) . ' minutes';
                $this->mtfSwitchRepository->turnOffSymbolForDuration($symbol, $duration);
                
                $this->auditStep($runId, $symbol, "{$timeframe->value}_INSUFFICIENT_DATA_AFTER_FILL", "Still insufficient bars after bulk fill", [
                    'timeframe' => $timeframe->value,
                    'bars_count' => count($klines),
                    'min_bars' => $limit,
                    'missing_bars' => $missingBars,
                    'duration_disabled' => $duration
                ]);
                return ['status' => 'SKIPPED', 'reason' => 'INSUFFICIENT_DATA_AFTER_FILL', 'failed_timeframe' => $timeframe->value];
            }
        }

        // ✅ SUITE NORMALE : Vérifications de fraîcheur, kill switches, etc.
        if (!$forceTimeframeCheck && !$forceRun) {
            $lastKline = $this->klineRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);
            if ($lastKline) {
                $interval = new \DateInterval('PT' . $timeframe->getStepInMinutes() . 'M');
                $threshold = $now->sub($interval);
                if ($lastKline->getOpenTime() > $threshold) {
                    $this->auditStep($runId, $symbol, "{$timeframe->value}_SKIPPED_TOO_RECENT", "Last kline is too recent", [
                        'timeframe' => $timeframe->value,
                        'last_kline_time' => $lastKline->getOpenTime()->format('Y-m-d H:i:s'),
                        'threshold' => $threshold->format('Y-m-d H:i:s')
                    ]);
                    return ['status' => 'SKIPPED', 'reason' => 'TOO_RECENT'];
                }
            }
        }

        // Kill switch TF (sauf si force-run est activé)
        if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbolTimeframe($symbol, $timeframe->value)) {
            $this->auditStep($runId, $symbol, "{$timeframe->value}_KILL_SWITCH_OFF", "{$timeframe->value} kill switch is OFF", ['timeframe' => $timeframe->value, 'force_run' => $forceRun]);
            return ['status' => 'SKIPPED', 'reason' => "{$timeframe->value} kill switch OFF"];
        }

        // Fenêtre de grâce (sauf si force-run est activé)
        if (!$forceRun && $this->timeService->isInGraceWindow($now, $timeframe)) {
            $this->auditStep($runId, $symbol, "{$timeframe->value}_GRACE_WINDOW", "In grace window for {$timeframe->value}", ['timeframe' => $timeframe->value, 'force_run' => $forceRun]);
            return ['status' => 'GRACE_WINDOW', 'reason' => "In grace window for {$timeframe->value}"];
        }

        // ✅ SUITE NORMALE : Traitement des signaux
        // Reverser en ordre chronologique ascendant
        usort($klines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

        // ... reste du traitement normal (validation des signaux, etc.)
        return $this->processSignals($symbol, $timeframe, $klines, $collector, $runId, $now);
    }
}
