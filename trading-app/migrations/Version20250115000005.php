<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250115000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create technical indicators for available timeframes (5m, 15m, 1h, 4h)';
    }

    public function up(Schema $schema): void
    {
        // Drop existing views that don't have data
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_rsi14_1m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_boll20_1m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_donchian20_1m');

        // Create RSI for 5m timeframe
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_rsi14_5m AS
            WITH base AS (
                SELECT
                    symbol,
                    timeframe,
                    open_time,
                    close_price,
                    LAG(close_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time) AS prev_close
                FROM klines
                WHERE timeframe = \'5m\'
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

        // Create Bollinger Bands for 5m timeframe
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_boll20_5m AS
            SELECT
                symbol,
                timeframe,
                DATE_TRUNC(\'minute\', open_time) AS bucket,
                AVG(close_price) OVER w AS sma,
                STDDEV_SAMP(close_price) OVER w AS sd,
                AVG(close_price) OVER w + 2 * STDDEV_SAMP(close_price) OVER w AS upper,
                AVG(close_price) OVER w - 2 * STDDEV_SAMP(close_price) OVER w AS lower
            FROM klines
            WHERE timeframe = \'5m\'
            WINDOW w AS (
                PARTITION BY symbol, timeframe 
                ORDER BY open_time 
                ROWS BETWEEN 19 PRECEDING AND CURRENT ROW
            );
        ');

        // Create Donchian Channels for 5m timeframe
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_donchian20_5m AS
            SELECT
                symbol,
                timeframe,
                DATE_TRUNC(\'minute\', open_time) AS bucket,
                MAX(high_price) OVER w AS upper,
                MIN(low_price) OVER w AS lower
            FROM klines
            WHERE timeframe = \'5m\'
            WINDOW w AS (
                PARTITION BY symbol, timeframe 
                ORDER BY open_time 
                ROWS BETWEEN 19 PRECEDING AND CURRENT ROW
            );
        ');

        // Create MACD for 5m timeframe
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_macd_5m AS
            WITH ema_calc AS (
                SELECT
                    symbol,
                    timeframe,
                    DATE_TRUNC(\'minute\', open_time) AS bucket,
                    AVG(close_price) OVER (
                        PARTITION BY symbol, timeframe 
                        ORDER BY open_time 
                        ROWS BETWEEN 11 PRECEDING AND CURRENT ROW
                    ) AS ema12,
                    AVG(close_price) OVER (
                        PARTITION BY symbol, timeframe 
                        ORDER BY open_time 
                        ROWS BETWEEN 25 PRECEDING AND CURRENT ROW
                    ) AS ema26
                FROM klines
                WHERE timeframe = \'5m\'
            )
            SELECT
                symbol,
                timeframe,
                bucket,
                (ema12 - ema26) AS macd,
                AVG(ema12 - ema26) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY bucket 
                    ROWS BETWEEN 8 PRECEDING AND CURRENT ROW
                ) AS signal,
                (ema12 - ema26) - AVG(ema12 - ema26) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY bucket 
                    ROWS BETWEEN 8 PRECEDING AND CURRENT ROW
                ) AS histogram
            FROM ema_calc
            WHERE ema12 IS NOT NULL AND ema26 IS NOT NULL;
        ');

        // Create OBV for 5m timeframe
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_obv_5m AS
            WITH base AS (
                SELECT
                    symbol,
                    timeframe,
                    open_time,
                    close_price,
                    volume,
                    LAG(close_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time) AS prev_close
                FROM klines
                WHERE timeframe = \'5m\' AND volume IS NOT NULL
            )
            SELECT
                symbol,
                timeframe,
                DATE_TRUNC(\'minute\', open_time) AS bucket,
                SUM(CASE
                    WHEN close_price > prev_close THEN volume
                    WHEN close_price < prev_close THEN -volume
                    ELSE 0
                END) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY open_time 
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS obv
            FROM base
            WHERE prev_close IS NOT NULL;
        ');

        // Create VWAP for 5m timeframe
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_vwap_5m AS
            SELECT
                symbol,
                timeframe,
                DATE_TRUNC(\'minute\', open_time) AS bucket,
                safe_div(
                    SUM(((high_price + low_price + close_price) / 3.0) * volume),
                    NULLIF(SUM(volume), 0)
                ) AS vwap
            FROM klines
            WHERE timeframe = \'5m\' AND volume IS NOT NULL
            GROUP BY symbol, timeframe, bucket;
        ');

        // Create indexes for better performance
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_rsi14_5m_symbol_bucket ON mv_rsi14_5m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_boll20_5m_symbol_bucket ON mv_boll20_5m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_donchian20_5m_symbol_bucket ON mv_donchian20_5m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_macd_5m_symbol_bucket ON mv_macd_5m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_obv_5m_symbol_bucket ON mv_obv_5m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_vwap_5m_symbol_bucket ON mv_vwap_5m(symbol, bucket)');
    }

    public function down(Schema $schema): void
    {
        // Drop materialized views
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_vwap_5m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_obv_5m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_macd_5m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_donchian20_5m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_boll20_5m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_rsi14_5m');
    }
}
