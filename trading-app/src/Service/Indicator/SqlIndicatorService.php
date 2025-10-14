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
