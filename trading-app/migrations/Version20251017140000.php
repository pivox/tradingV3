<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251017140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PostgreSQL: Ajout candle_close_ts + severity sur mtf_audit et création de la vue matérialisée mtf_audit_stats';
    }

    public function up(Schema $schema): void
    {
        // Colonnes (PostgreSQL)
        $this->addSql("ALTER TABLE mtf_audit ADD COLUMN IF NOT EXISTS candle_close_ts TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL");
        $this->addSql("ALTER TABLE mtf_audit ADD COLUMN IF NOT EXISTS severity SMALLINT DEFAULT 0 NOT NULL");

        // Vue matérialisée basée sur colonnes table + métriques dans JSON details
        $this->addSql("DROP MATERIALIZED VIEW IF EXISTS mtf_audit_stats");
        $this->addSql(<<<SQL
CREATE MATERIALIZED VIEW mtf_audit_stats AS
SELECT
  symbol,
  (timeframe)::text AS timeframe,
  count(*) AS total,
  sum(CASE WHEN (details->>'passed')::boolean THEN 1 ELSE 0 END) AS nb_passed,
  avg(NULLIF((details->'metrics'->>'atr_rel')::numeric, NULL)) AS avg_atr,
  avg(NULLIF((details->'metrics'->>'spread_bps')::numeric, NULL)) AS avg_spread
FROM mtf_audit
GROUP BY symbol, timeframe
SQL);

        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_symbol ON mtf_audit_stats(symbol)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_tf ON mtf_audit_stats(timeframe)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP MATERIALIZED VIEW IF EXISTS mtf_audit_stats");
        $this->addSql("ALTER TABLE mtf_audit DROP COLUMN IF EXISTS candle_close_ts");
        $this->addSql("ALTER TABLE mtf_audit DROP COLUMN IF EXISTS severity");
    }
}



