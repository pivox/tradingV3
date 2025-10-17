<?php

declare(strict_types=1);

namespace App\Service\Indicator;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service pour calculer les indicateurs via les vues matérialisées SQL
 */
class SqlIndicatorService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Récupère les indicateurs EMA depuis la vue matérialisée
     */
    public function getEma(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                ema9,
                ema21,
                ema50,
                ema200
            FROM mv_ema_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs RSI depuis la vue matérialisée
     */
    public function getRsi(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                rsi
            FROM mv_rsi14_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs MACD depuis la vue matérialisée
     */
    public function getMacd(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                macd,
                signal,
                histogram
            FROM mv_macd_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs VWAP depuis la vue matérialisée
     */
    public function getVwap(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                vwap
            FROM mv_vwap_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs Bollinger Bands depuis la vue matérialisée
     */
    public function getBollingerBands(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                upper_band,
                middle_band,
                lower_band
            FROM mv_boll20_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs StochRSI depuis la vue matérialisée
     */
    public function getStochRsi(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                stoch_rsi,
                stoch_rsi_d
            FROM mv_stochrsi_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs ADX depuis la vue matérialisée
     */
    public function getAdx(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                plus_di,
                minus_di,
                adx
            FROM mv_adx14_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs Ichimoku depuis la vue matérialisée
     */
    public function getIchimoku(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                tenkan,
                kijun,
                senkou_a,
                senkou_b,
                chikou
            FROM mv_ichimoku_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs OBV depuis la vue matérialisée
     */
    public function getObv(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                obv
            FROM mv_obv_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère les indicateurs Donchian Channels depuis la vue matérialisée
     */
    public function getDonchianChannels(string $symbol, string $timeframe, int $limit = 1): array
    {
        $sql = "
            SELECT 
                bucket,
                upper_channel,
                lower_channel,
                middle_channel
            FROM mv_donchian20_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
            ORDER BY bucket DESC 
            LIMIT :limit
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Récupère tous les indicateurs pour un symbole et timeframe donnés
     */
    public function getAllIndicators(string $symbol, string $timeframe, int $limit = 1): array
    {
        $indicators = [];

        try {
            $indicators['ema'] = $this->getEma($symbol, $timeframe, $limit);
            $indicators['rsi'] = $this->getRsi($symbol, $timeframe, $limit);
            $indicators['macd'] = $this->getMacd($symbol, $timeframe, $limit);
            $indicators['vwap'] = $this->getVwap($symbol, $timeframe, $limit);
            $indicators['bollinger'] = $this->getBollingerBands($symbol, $timeframe, $limit);
            $indicators['stochrsi'] = $this->getStochRsi($symbol, $timeframe, $limit);
            $indicators['adx'] = $this->getAdx($symbol, $timeframe, $limit);
            $indicators['ichimoku'] = $this->getIchimoku($symbol, $timeframe, $limit);
            $indicators['obv'] = $this->getObv($symbol, $timeframe, $limit);
            $indicators['donchian'] = $this->getDonchianChannels($symbol, $timeframe, $limit);
        } catch (\Exception $e) {
            $this->logger?->error('Erreur lors de la récupération des indicateurs SQL', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $indicators;
    }

    /**
     * Vérifie si les vues matérialisées contiennent des données
     */
    public function hasData(string $symbol, string $timeframe): bool
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM mv_ema_5m 
            WHERE symbol = :symbol 
                AND timeframe = :timeframe
        ";

        $result = $this->connection->executeQuery($sql, [
            'symbol' => $symbol,
            'timeframe' => $timeframe
        ]);

        $count = $result->fetchOne();
        return $count > 0;
    }

    /**
     * Récupère le dernier snapshot d'indicateurs depuis les vues matérialisées
     */
    public function getLastIndicatorSnapshot(string $symbol, \App\Domain\Common\Enum\Timeframe $timeframe): ?\App\Domain\Common\Dto\IndicatorSnapshotDto
    {
        try {
            // Récupérer tous les indicateurs depuis les vues matérialisées
            $indicators = $this->getAllIndicators($symbol, $timeframe->value, 1);
            
            if (empty($indicators['ema']) && empty($indicators['rsi']) && empty($indicators['macd'])) {
                $this->logger?->info('Aucun indicateur trouvé pour le symbole et timeframe', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value
                ]);
                return null;
            }

            // Déterminer le bucket le plus récent
            $latestBucket = null;
            foreach ($indicators as $indicatorType => $data) {
                if (!empty($data)) {
                    $bucket = $data[0]['bucket'] ?? null;
                    if ($bucket && ($latestBucket === null || $bucket > $latestBucket)) {
                        $latestBucket = $bucket;
                    }
                }
            }

            if ($latestBucket === null) {
                return null;
            }

            // Extraire les valeurs des indicateurs pour le bucket le plus récent
            $ema = $this->extractLatestValue($indicators['ema'], $latestBucket);
            $rsi = $this->extractLatestValue($indicators['rsi'], $latestBucket);
            $macd = $this->extractLatestValue($indicators['macd'], $latestBucket);
            $vwap = $this->extractLatestValue($indicators['vwap'], $latestBucket);
            $bollinger = $this->extractLatestValue($indicators['bollinger'], $latestBucket);

            // Créer le DTO avec les valeurs extraites
            return new \App\Domain\Common\Dto\IndicatorSnapshotDto(
                symbol: $symbol,
                timeframe: $timeframe,
                klineTime: new \DateTimeImmutable($latestBucket, new \DateTimeZone('UTC')),
                ema20: $ema ? \Brick\Math\BigDecimal::of($ema['ema21'] ?? 0) : null,
                ema50: $ema ? \Brick\Math\BigDecimal::of($ema['ema50'] ?? 0) : null,
                macd: $macd ? \Brick\Math\BigDecimal::of($macd['macd'] ?? 0) : null,
                macdSignal: $macd ? \Brick\Math\BigDecimal::of($macd['signal'] ?? 0) : null,
                macdHistogram: $macd ? \Brick\Math\BigDecimal::of($macd['histogram'] ?? 0) : null,
                rsi: $rsi ? (float)$rsi['rsi'] : null,
                vwap: $vwap ? \Brick\Math\BigDecimal::of($vwap['vwap'] ?? 0) : null,
                bbUpper: $bollinger ? \Brick\Math\BigDecimal::of($bollinger['upper_band'] ?? 0) : null,
                bbMiddle: $bollinger ? \Brick\Math\BigDecimal::of($bollinger['middle_band'] ?? 0) : null,
                bbLower: $bollinger ? \Brick\Math\BigDecimal::of($bollinger['lower_band'] ?? 0) : null,
                ma9: $ema ? \Brick\Math\BigDecimal::of($ema['ema9'] ?? 0) : null,
                ma21: $ema ? \Brick\Math\BigDecimal::of($ema['ema21'] ?? 0) : null,
                meta: [
                    'source' => 'sql_materialized_views',
                    'bucket' => $latestBucket,
                    'indicators_available' => array_keys(array_filter($indicators, fn($data) => !empty($data)))
                ]
            );

        } catch (\Exception $e) {
            $this->logger?->error('Erreur lors de la récupération du dernier snapshot d\'indicateurs', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupère les snapshots d'indicateurs pour une période
     */
    public function getIndicatorSnapshots(string $symbol, \App\Domain\Common\Enum\Timeframe $timeframe, int $limit = 100): array
    {
        try {
            // Récupérer tous les indicateurs depuis les vues matérialisées
            $indicators = $this->getAllIndicators($symbol, $timeframe->value, $limit);
            
            if (empty($indicators['ema']) && empty($indicators['rsi']) && empty($indicators['macd'])) {
                $this->logger?->info('Aucun indicateur trouvé pour le symbole et timeframe', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value
                ]);
                return [];
            }

            // Collecter tous les buckets uniques
            $buckets = [];
            foreach ($indicators as $indicatorType => $data) {
                foreach ($data as $item) {
                    $bucket = $item['bucket'] ?? null;
                    if ($bucket && !in_array($bucket, $buckets)) {
                        $buckets[] = $bucket;
                    }
                }
            }

            // Trier les buckets par ordre décroissant (plus récent en premier)
            rsort($buckets);

            // Limiter le nombre de buckets
            $buckets = array_slice($buckets, 0, $limit);

            $snapshots = [];
            foreach ($buckets as $bucket) {
                $snapshot = $this->createSnapshotForBucket($symbol, $timeframe, $bucket, $indicators);
                if ($snapshot) {
                    $snapshots[] = $snapshot;
                }
            }

            return $snapshots;

        } catch (\Exception $e) {
            $this->logger?->error('Erreur lors de la récupération des snapshots d\'indicateurs', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'limit' => $limit,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Crée un snapshot pour un bucket donné
     */
    private function createSnapshotForBucket(
        string $symbol, 
        \App\Domain\Common\Enum\Timeframe $timeframe, 
        string $bucket, 
        array $indicators
    ): ?\App\Domain\Common\Dto\IndicatorSnapshotDto {
        // Extraire les valeurs des indicateurs pour ce bucket
        $ema = $this->extractLatestValue($indicators['ema'], $bucket);
        $rsi = $this->extractLatestValue($indicators['rsi'], $bucket);
        $macd = $this->extractLatestValue($indicators['macd'], $bucket);
        $vwap = $this->extractLatestValue($indicators['vwap'], $bucket);
        $bollinger = $this->extractLatestValue($indicators['bollinger'], $bucket);

        // Vérifier qu'au moins un indicateur a des données pour ce bucket
        if (!$ema && !$rsi && !$macd && !$vwap && !$bollinger) {
            return null;
        }

        return new \App\Domain\Common\Dto\IndicatorSnapshotDto(
            symbol: $symbol,
            timeframe: $timeframe,
            klineTime: new \DateTimeImmutable($bucket, new \DateTimeZone('UTC')),
            ema20: $ema ? \Brick\Math\BigDecimal::of($ema['ema21'] ?? 0) : null,
            ema50: $ema ? \Brick\Math\BigDecimal::of($ema['ema50'] ?? 0) : null,
            macd: $macd ? \Brick\Math\BigDecimal::of($macd['macd'] ?? 0) : null,
            macdSignal: $macd ? \Brick\Math\BigDecimal::of($macd['signal'] ?? 0) : null,
            macdHistogram: $macd ? \Brick\Math\BigDecimal::of($macd['histogram'] ?? 0) : null,
            rsi: $rsi ? (float)$rsi['rsi'] : null,
            vwap: $vwap ? \Brick\Math\BigDecimal::of($vwap['vwap'] ?? 0) : null,
            bbUpper: $bollinger ? \Brick\Math\BigDecimal::of($bollinger['upper_band'] ?? 0) : null,
            bbMiddle: $bollinger ? \Brick\Math\BigDecimal::of($bollinger['middle_band'] ?? 0) : null,
            bbLower: $bollinger ? \Brick\Math\BigDecimal::of($bollinger['lower_band'] ?? 0) : null,
            ma9: $ema ? \Brick\Math\BigDecimal::of($ema['ema9'] ?? 0) : null,
            ma21: $ema ? \Brick\Math\BigDecimal::of($ema['ema21'] ?? 0) : null,
            meta: [
                'source' => 'sql_materialized_views',
                'bucket' => $bucket,
                'indicators_available' => array_keys(array_filter([
                    'ema' => $ema,
                    'rsi' => $rsi,
                    'macd' => $macd,
                    'vwap' => $vwap,
                    'bollinger' => $bollinger
                ]))
            ]
        );
    }

    /**
     * Extrait la valeur la plus récente pour un bucket donné
     */
    private function extractLatestValue(array $data, string $targetBucket): ?array
    {
        if (empty($data)) {
            return null;
        }

        // Chercher le bucket exact
        foreach ($data as $item) {
            if ($item['bucket'] === $targetBucket) {
                return $item;
            }
        }

        // Si pas trouvé, retourner le premier élément (le plus récent)
        return $data[0];
    }

    /**
     * Rafraîchit les vues matérialisées
     */
    public function refreshMaterializedViews(): void
    {
        $views = [
            'mv_ema_5m',
            'mv_rsi14_5m',
            'mv_macd_5m',
            'mv_vwap_5m',
            'mv_boll20_5m',
            'mv_stochrsi_5m',
            'mv_adx14_5m',
            'mv_ichimoku_5m',
            'mv_obv_5m',
            'mv_donchian20_5m'
        ];

        foreach ($views as $view) {
            try {
                $this->connection->executeStatement("REFRESH MATERIALIZED VIEW $view");
                $this->logger?->info("Vue matérialisée rafraîchie: $view");
            } catch (\Exception $e) {
                $this->logger?->error("Erreur lors du rafraîchissement de $view", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
