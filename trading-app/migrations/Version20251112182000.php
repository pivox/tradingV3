<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the mtf_audit_stats materialized view used by the /mtf/stats pages.
 */
final class Version20251112182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create materialized view mtf_audit_stats (aggregates per symbol/timeframe)';
    }

    public function up(Schema $schema): void
    {
        // Create materialized view with aggregates computed from mtf_audit
        $this->addSql(<<<'SQL'
CREATE MATERIALIZED VIEW IF NOT EXISTS mtf_audit_stats AS
WITH base AS (
  SELECT
    symbol,
    COALESCE(NULLIF(timeframe, ''), NULLIF(details->> 'timeframe', '')) AS timeframe,
    created_at,
    COALESCE(candle_open_ts, NULLIF(details->> 'kline_time', '')::timestamptz) AS candle_open_ts,
    step,
    NULLIF(details->> 'passed', '')::boolean AS passed,
    CASE
      WHEN details ? 'metrics' AND NULLIF(details->'metrics'->> 'atr_rel', '') IS NOT NULL
        THEN NULLIF(details->'metrics'->> 'atr_rel', '')::numeric
      WHEN (details ? 'atr' AND details ? 'current_price'
            AND NULLIF(details->> 'current_price', '') ~ '^[0-9.]+$'
            AND (details->> 'current_price')::numeric > 0)
        THEN ((details->> 'atr')::numeric / NULLIF((details->> 'current_price')::numeric, 0))
      ELSE NULL
    END AS atr_rel,
    CASE WHEN details ? 'spread_bps' THEN NULLIF(details->> 'spread_bps', '')::numeric ELSE NULL END AS spread_bps,
    severity
  FROM mtf_audit
)
SELECT
  symbol,
  timeframe,
  COUNT(*)                                                       AS total,
  COUNT(*) FILTER (WHERE passed IS TRUE)                         AS nb_passed,
  CASE WHEN COUNT(*) > 0
       THEN ROUND((COUNT(*) FILTER (WHERE passed IS TRUE))::numeric * 100.0 / COUNT(*), 2)
       ELSE 0.0
  END                                                            AS pass_rate,
  AVG(atr_rel)                                                   AS avg_atr_rel,
  AVG(spread_bps)                                                AS avg_spread_bps,
  AVG(severity)::float                                           AS avg_severity,
  MAX(candle_open_ts)                                            AS last_candle_open_ts,
  MAX(created_at)                                                AS last_created_at,
  COUNT(*) FILTER (WHERE step = 'ALIGNMENT_FAILED')              AS nb_alignment_failed,
  COUNT(*) FILTER (WHERE step = '4H_VALIDATION_FAILED')          AS nb_validation_failed_4h,
  COUNT(*) FILTER (WHERE step = '1H_VALIDATION_FAILED')          AS nb_validation_failed_1h,
  COUNT(*) FILTER (WHERE step = '15M_VALIDATION_FAILED')         AS nb_validation_failed_15m,
  COUNT(*) FILTER (WHERE step = '5M_VALIDATION_FAILED')          AS nb_validation_failed_5m,
  COUNT(*) FILTER (WHERE step = '1M_VALIDATION_FAILED')          AS nb_validation_failed_1m
FROM base
WHERE timeframe IS NOT NULL AND timeframe <> ''
GROUP BY symbol, timeframe
SQL);

        // Unique index required to allow REFRESH MATERIALIZED VIEW CONCURRENTLY
        $this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS ux_mtf_audit_stats_symbol_tf ON mtf_audit_stats (symbol, timeframe)");

        // Helpful indexes for common queries (ordering/search); safe to create if-not-exists
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_last_created ON mtf_audit_stats (last_created_at DESC)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_pass_rate ON mtf_audit_stats (pass_rate DESC)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_mtf_audit_stats_pass_rate');
        $this->addSql('DROP INDEX IF EXISTS idx_mtf_audit_stats_last_created');
        $this->addSql('DROP INDEX IF EXISTS ux_mtf_audit_stats_symbol_tf');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mtf_audit_stats');
    }
}

