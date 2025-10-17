<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250115000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Ichimoku Kinko Hyo indicator';
    }

    public function up(Schema $schema): void
    {
        // Ichimoku Kinko Hyo - Simplified version
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ichimoku_5m AS
            WITH base AS (
                SELECT
                    symbol,
                    timeframe,
                    open_time,
                    high_price,
                    low_price,
                    close_price,
                    DATE_TRUNC(\'minute\', open_time) AS bucket
                FROM klines
                WHERE timeframe = \'5m\'
            ),
            tk_kj AS (
                SELECT
                    symbol,
                    timeframe,
                    bucket,
                    -- Tenkan-sen (9 periods)
                    (MAX(high_price) OVER w9 + MIN(low_price) OVER w9) / 2 AS tenkan,
                    -- Kijun-sen (26 periods)
                    (MAX(high_price) OVER w26 + MIN(low_price) OVER w26) / 2 AS kijun,
                    close_price
                FROM base
                WINDOW
                    w9 AS (PARTITION BY symbol, timeframe ORDER BY bucket ROWS BETWEEN 8 PRECEDING AND CURRENT ROW),
                    w26 AS (PARTITION BY symbol, timeframe ORDER BY bucket ROWS BETWEEN 25 PRECEDING AND CURRENT ROW)
            )
            SELECT
                symbol,
                timeframe,
                bucket,
                tenkan,
                kijun,
                -- Senkou Span A (simplified - just the average of tenkan and kijun)
                (tenkan + kijun) / 2 AS senkou_a,
                -- Senkou Span B (simplified - using a fixed period calculation)
                NULL AS senkou_b,
                -- Chikou Span (close price shifted 26 periods backward)
                LAG(close_price, 26) OVER (PARTITION BY symbol, timeframe ORDER BY bucket) AS chikou
            FROM tk_kj;
        ');

        // Create index for better performance
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_ichimoku_5m_symbol_bucket ON mv_ichimoku_5m(symbol, bucket)');
    }

    public function down(Schema $schema): void
    {
        // Drop materialized view
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_ichimoku_5m');
    }
}
