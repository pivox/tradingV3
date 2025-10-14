<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250115000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create technical indicators materialized views - Part 1';
    }

    public function up(Schema $schema): void
    {
        // Create utility functions for technical indicators
        
        // Safe division function
        $this->addSql('
            CREATE OR REPLACE FUNCTION safe_div(numerator NUMERIC, denominator NUMERIC)
            RETURNS NUMERIC AS $$
            BEGIN
                RETURN CASE 
                    WHEN denominator IS NULL OR denominator = 0 THEN NULL
                    ELSE numerator / denominator
                END;
            END;
            $$ LANGUAGE plpgsql IMMUTABLE;
        ');

        // 1. RSI (Relative Strength Index) - 14 period
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_rsi14_1m AS
            WITH base AS (
                SELECT
                    symbol,
                    timeframe,
                    open_time,
                    close_price,
                    LAG(close_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time) AS prev_close
                FROM klines
                WHERE timeframe = \'1m\'
            ),
            diffs AS (
                SELECT
                    symbol,
                    timeframe,
                    DATE_TRUNC(\'minute\', open_time) AS bucket,
                    GREATEST(close_price - prev_close, 0) AS gain,
                    GREATEST(prev_close - close_price, 0) AS loss
                FROM base
                WHERE prev_close IS NOT NULL
            ),
            smoothed AS (
                SELECT
                    symbol,
                    timeframe,
                    bucket,
                    -- Simple moving average for gains and losses (approximation of RMA)
                    AVG(gain) OVER (
                        PARTITION BY symbol, timeframe 
                        ORDER BY bucket 
                        ROWS BETWEEN 13 PRECEDING AND CURRENT ROW
                    ) AS avg_gain,
                    AVG(loss) OVER (
                        PARTITION BY symbol, timeframe 
                        ORDER BY bucket 
                        ROWS BETWEEN 13 PRECEDING AND CURRENT ROW
                    ) AS avg_loss
                FROM diffs
            )
            SELECT
                symbol,
                timeframe,
                bucket,
                100 - (100 / (1 + safe_div(avg_gain, NULLIF(avg_loss, 0)))) AS rsi
            FROM smoothed
            WHERE avg_gain IS NOT NULL AND avg_loss IS NOT NULL;
        ');

        // 2. Bollinger Bands (20 period, 2 standard deviations)
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_boll20_1m AS
            SELECT
                symbol,
                timeframe,
                DATE_TRUNC(\'minute\', open_time) AS bucket,
                AVG(close_price) OVER w AS sma,
                STDDEV_SAMP(close_price) OVER w AS sd,
                AVG(close_price) OVER w + 2 * STDDEV_SAMP(close_price) OVER w AS upper,
                AVG(close_price) OVER w - 2 * STDDEV_SAMP(close_price) OVER w AS lower
            FROM klines
            WHERE timeframe = \'1m\'
            WINDOW w AS (
                PARTITION BY symbol, timeframe 
                ORDER BY open_time 
                ROWS BETWEEN 19 PRECEDING AND CURRENT ROW
            );
        ');

        // 3. Donchian Channels (20 period)
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_donchian20_1m AS
            SELECT
                symbol,
                timeframe,
                DATE_TRUNC(\'minute\', open_time) AS bucket,
                MAX(high_price) OVER w AS upper,
                MIN(low_price) OVER w AS lower
            FROM klines
            WHERE timeframe = \'1m\'
            WINDOW w AS (
                PARTITION BY symbol, timeframe 
                ORDER BY open_time 
                ROWS BETWEEN 19 PRECEDING AND CURRENT ROW
            );
        ');

        // Create indexes for better performance
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_rsi14_1m_symbol_bucket ON mv_rsi14_1m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_boll20_1m_symbol_bucket ON mv_boll20_1m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_donchian20_1m_symbol_bucket ON mv_donchian20_1m(symbol, bucket)');
    }

    public function down(Schema $schema): void
    {
        // Drop materialized views
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_donchian20_1m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_boll20_1m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_rsi14_1m');

        // Drop functions
        $this->addSql('DROP FUNCTION IF EXISTS safe_div(NUMERIC, NUMERIC)');
    }
}
