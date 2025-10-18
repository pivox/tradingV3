<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251017140500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PostgreSQL: Remplace mtf_audit_stats par une vue matérialisée enrichie (pass_rate, avg_severity, derniers timestamps, compteurs par type d\'échec)';
    }

    public function up(Schema $schema): void
    {
        // Remplacer la vue matérialisée
        $this->addSql("DROP MATERIALIZED VIEW IF EXISTS mtf_audit_stats");
        $this->addSql(<<<SQL
CREATE MATERIALIZED VIEW mtf_audit_stats AS
SELECT
  a.symbol AS symbol,
  (a.timeframe)::text AS timeframe,
  COUNT(*)::bigint AS total,
  SUM(CASE WHEN (a.details->>'passed')::boolean THEN 1 ELSE 0 END)::bigint AS nb_passed,
  CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN (a.details->>'passed')::boolean THEN 1 ELSE 0 END)::numeric / COUNT(*)::numeric ELSE 0 END AS pass_rate,
  AVG((a.details->'metrics'->>'atr_rel')::numeric) AS avg_atr_rel,
  AVG((a.details->'metrics'->>'spread_bps')::numeric) AS avg_spread_bps,
  AVG(a.severity)::numeric AS avg_severity,
  MAX(a.candle_close_ts) AS last_candle_close_ts,
  MAX(a.created_at) AS last_created_at,
  SUM(CASE WHEN a.step = 'ALIGNMENT_FAILED' THEN 1 ELSE 0 END)::bigint AS nb_alignment_failed,
  SUM(CASE WHEN a.step = '4H_VALIDATION_FAILED' THEN 1 ELSE 0 END)::bigint AS nb_validation_failed_4h,
  SUM(CASE WHEN a.step = '1H_VALIDATION_FAILED' THEN 1 ELSE 0 END)::bigint AS nb_validation_failed_1h,
  SUM(CASE WHEN a.step = '15M_VALIDATION_FAILED' THEN 1 ELSE 0 END)::bigint AS nb_validation_failed_15m,
  SUM(CASE WHEN a.step = '5M_VALIDATION_FAILED' THEN 1 ELSE 0 END)::bigint AS nb_validation_failed_5m,
  SUM(CASE WHEN a.step = '1M_VALIDATION_FAILED' THEN 1 ELSE 0 END)::bigint AS nb_validation_failed_1m
FROM mtf_audit a
GROUP BY a.symbol, a.timeframe
SQL);

        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_symbol ON mtf_audit_stats(symbol)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_tf ON mtf_audit_stats(timeframe)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP MATERIALIZED VIEW IF EXISTS mtf_audit_stats");
    }
}



