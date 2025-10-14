<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250115000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create advanced technical indicators: StochRSI, ADX, and Ichimoku';
    }

    public function up(Schema $schema): void
    {
        // 1. StochRSI (depends on RSI) - 14 period
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_stochrsi_5m AS
            WITH rsi_with_minmax AS (
                SELECT
                    symbol,
                    timeframe,
                    bucket,
                    rsi,
                    MIN(rsi) OVER w14 AS min_rsi_14,
                    MAX(rsi) OVER w14 AS max_rsi_14
                FROM mv_rsi14_5m
                WHERE rsi IS NOT NULL
                WINDOW w14 AS (PARTITION BY symbol, timeframe ORDER BY bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW)
            ),
            stoch_rsi_calc AS (
                SELECT
                    symbol,
                    timeframe,
                    bucket,
                    rsi,
                    (rsi - min_rsi_14) / NULLIF(max_rsi_14 - min_rsi_14, 0) AS stoch_rsi
                FROM rsi_with_minmax
                WHERE min_rsi_14 IS NOT NULL AND max_rsi_14 IS NOT NULL
            )
            SELECT
                symbol,
                timeframe,
                bucket,
                stoch_rsi,
                -- StochRSI %D (3-period average of StochRSI)
                AVG(stoch_rsi) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY bucket 
                    ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
                ) AS stoch_rsi_d
            FROM stoch_rsi_calc;
        ');

        // 2. ADX (Average Directional Index) - 14 period
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_adx14_5m AS
            WITH base AS (
                SELECT
                    symbol,
                    timeframe,
                    open_time,
                    high_price,
                    low_price,
                    close_price,
                    LAG(high_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time) AS prev_high,
                    LAG(low_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time) AS prev_low,
                    LAG(close_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time) AS prev_close
                FROM klines
                WHERE timeframe = \'5m\'
            ),
            dm_tr AS (
                SELECT
                    symbol,
                    timeframe,
                    DATE_TRUNC(\'minute\', open_time) AS bucket,
                    GREATEST(high_price - prev_high, 0) AS up_move,
                    GREATEST(prev_low - low_price, 0) AS down_move,
                    GREATEST(
                        GREATEST(high_price - low_price, ABS(high_price - prev_close)),
                        ABS(low_price - prev_close)
                    ) AS tr
                FROM base
                WHERE prev_high IS NOT NULL AND prev_low IS NOT NULL AND prev_close IS NOT NULL
            ),
            smoothed AS (
                SELECT
                    symbol,
                    timeframe,
                    bucket,
                    -- Smoothed +DM and -DM (approximated with simple moving average)
                    AVG(CASE WHEN up_move > down_move THEN up_move ELSE 0 END) OVER (
                        PARTITION BY symbol, timeframe 
                        ORDER BY bucket 
                        ROWS BETWEEN 13 PRECEDING AND CURRENT ROW
                    ) AS plus_dm,
                    AVG(CASE WHEN down_move > up_move THEN down_move ELSE 0 END) OVER (
                        PARTITION BY symbol, timeframe 
                        ORDER BY bucket 
                        ROWS BETWEEN 13 PRECEDING AND CURRENT ROW
                    ) AS minus_dm,
                    AVG(tr) OVER (
                        PARTITION BY symbol, timeframe 
                        ORDER BY bucket 
                        ROWS BETWEEN 13 PRECEDING AND CURRENT ROW
                    ) AS atr14
                FROM dm_tr
            )
            SELECT
                symbol,
                timeframe,
                bucket,
                100 * safe_div(plus_dm, atr14) AS plus_di,
                100 * safe_div(minus_dm, atr14) AS minus_di,
                -- ADX calculation (simplified - average of directional movement)
                AVG(ABS(safe_div(plus_dm - minus_dm, plus_dm + minus_dm)) * 100) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY bucket 
                    ROWS BETWEEN 13 PRECEDING AND CURRENT ROW
                ) AS adx
            FROM smoothed
            WHERE plus_dm IS NOT NULL AND minus_dm IS NOT NULL AND atr14 IS NOT NULL;
        ');

        // Note: Ichimoku will be implemented in a separate migration due to complexity

        // Create indexes for better performance
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_stochrsi_5m_symbol_bucket ON mv_stochrsi_5m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_adx14_5m_symbol_bucket ON mv_adx14_5m(symbol, bucket)');
    }

    public function down(Schema $schema): void
    {
        // Drop materialized views
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_adx14_5m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_stochrsi_5m');
    }
}
