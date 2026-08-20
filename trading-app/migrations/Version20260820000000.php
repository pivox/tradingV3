<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Derive canonical holding time and MFE/MAE provenance from exact ledger fill timestamps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP VIEW position_trade_analysis_v2');
        $this->addCanonicalWrapperSql(true);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->addCanonicalWrapperSql(false);
    }

    private function addCanonicalWrapperSql(bool $withFillTiming): void
    {
        $validChronology = <<<'SQL'
legacy.quantity_status = 'complete'
    AND legacy.entry_first_fill_at IS NOT NULL
    AND legacy.exit_last_fill_at IS NOT NULL
    AND legacy.exit_last_fill_at >= legacy.entry_first_fill_at
SQL;
        $invalidChronology = <<<'SQL'
legacy.quantity_status = 'complete'
    AND legacy.entry_first_fill_at IS NOT NULL
    AND legacy.exit_last_fill_at IS NOT NULL
    AND legacy.exit_last_fill_at < legacy.entry_first_fill_at
SQL;
        $exactWindow = <<<SQL
{$validChronology}
    AND NULLIF(c.extra->> 'mfe_mae_window_source', '') = 'fill_cost_ledger_v1'
    AND NULLIF(c.extra->> 'mfe_mae_entry_price_source', '') = 'fill_cost_ledger_v1'
    AND trading_v3_safe_timestamptz(c.extra->> 'mfe_mae_window_start') = legacy.entry_first_fill_at
    AND trading_v3_safe_timestamptz(c.extra->> 'mfe_mae_window_end') = legacy.exit_last_fill_at
SQL;

        $fillTimingColumns = $withFillTiming ? <<<SQL
  ,CASE WHEN {$validChronology}
    THEN extract(epoch FROM legacy.exit_last_fill_at - legacy.entry_first_fill_at)
    ELSE NULL
  END AS canonical_holding_time_sec
  ,CASE
    WHEN {$validChronology} THEN 'fill_cost_ledger_v1'
    WHEN legacy.quantity_status = 'complete' THEN 'invalid_fill_chronology'
    ELSE 'incomplete_fill_ledger'
  END AS holding_time_source
  ,CASE
    WHEN {$exactWindow} THEN 'fill_cost_ledger_v1'
    WHEN legacy.quantity_status = 'complete' THEN 'unverified_fill_window'
    ELSE 'incomplete_fill_ledger'
  END AS mfe_mae_window_source
  ,CASE
    WHEN {$exactWindow} THEN 'fill_cost_ledger_v1'
    WHEN legacy.quantity_status = 'complete' THEN 'unverified_entry_price'
    ELSE 'incomplete_fill_ledger'
  END AS mfe_mae_entry_price_source
  ,CASE
    WHEN legacy.quantity_status IS DISTINCT FROM 'complete'
      AND legacy.mfe_mae_data_quality IN ('missing_price_data', 'provider_error')
    THEN legacy.mfe_mae_data_quality
    WHEN legacy.quantity_status IS DISTINCT FROM 'complete' THEN 'partial'
    WHEN {$exactWindow} THEN legacy.mfe_mae_data_quality
    WHEN legacy.mfe_mae_data_quality IN ('missing_price_data', 'provider_error') THEN legacy.mfe_mae_data_quality
    ELSE 'partial'
  END AS canonical_mfe_mae_data_quality
  ,CASE WHEN {$invalidChronology} THEN 'partial' ELSE legacy.cost_completeness END AS canonical_cost_completeness
  ,CASE
    WHEN {$invalidChronology} THEN
      COALESCE(legacy.pnl_quality_flags, '[]'::jsonb) || '["ledger_fill_chronology_invalid"]'::jsonb
    ELSE legacy.pnl_quality_flags
  END AS canonical_pnl_quality_flags
SQL : '';

        $canonicalNetCondition = $withFillTiming
            ? "identity.lineage_classification = 'canonical' AND {$validChronology}"
            : "identity.lineage_classification = 'canonical'";

        $this->addSql(<<<SQL
CREATE VIEW position_trade_analysis_v2 AS
SELECT
  legacy.*,
  e.mode_id,
  e.mode_version,
  e.setup_id,
  e.setup_version,
  e.config_hash AS canonical_config_hash,
  e.condition_catalog_hash,
  e.side AS canonical_side,
  e.decision_id,
  e.decision_key,
  e.intent_id,
  e.order_id AS canonical_order_id,
  e.position_id AS canonical_position_id,
  e.trade_id AS canonical_trade_id,
  e.paper_network,
  e.correlation_run_id AS canonical_correlation_run_id,
  e.orchestration_run_id AS canonical_orchestration_run_id,
  e.orchestration_set_id AS canonical_orchestration_set_id,
  e.orchestration_dashboard_id AS canonical_orchestration_dashboard_id,
  identity.lineage_classification,
  CASE WHEN {$canonicalNetCondition} THEN legacy.net_pnl_usdt ELSE NULL END AS canonical_net_pnl_usdt,
  CASE WHEN {$canonicalNetCondition} THEN legacy.realized_net_pnl_r ELSE NULL END AS canonical_realized_net_pnl_r
{$fillTimingColumns}
FROM position_trade_analysis_v2_legacy_source legacy
JOIN trade_lifecycle_event e ON e.id = legacy.entry_event_id
LEFT JOIN trade_lifecycle_event c ON c.id = legacy.close_event_id
CROSS JOIN LATERAL (
  SELECT CASE
    WHEN NOT (
      e.mode_id IS NOT NULL OR e.mode_version IS NOT NULL OR e.setup_id IS NOT NULL OR e.setup_version IS NOT NULL
      OR e.config_hash IS NOT NULL OR e.condition_catalog_hash IS NOT NULL OR e.side IS NOT NULL
      OR e.decision_id IS NOT NULL OR e.decision_key IS NOT NULL OR e.intent_id IS NOT NULL
      OR e.correlation_run_id IS NOT NULL OR e.orchestration_run_id IS NOT NULL
      OR e.orchestration_set_id IS NOT NULL OR e.orchestration_dashboard_id IS NOT NULL
      OR (c.id IS NOT NULL AND (
        c.mode_id IS NOT NULL OR c.mode_version IS NOT NULL OR c.setup_id IS NOT NULL OR c.setup_version IS NOT NULL
        OR c.config_hash IS NOT NULL OR c.condition_catalog_hash IS NOT NULL OR c.decision_id IS NOT NULL
        OR c.decision_key IS NOT NULL OR c.intent_id IS NOT NULL
      ))
    ) THEN 'legacy'
    WHEN e.mode_id IS NULL OR e.mode_version IS NULL OR e.setup_id IS NULL OR e.setup_version IS NULL
      OR e.config_hash IS NULL OR e.condition_catalog_hash IS NULL OR e.side IS NULL
      OR e.decision_id IS NULL OR e.decision_key IS NULL OR e.intent_id IS NULL
      OR e.correlation_run_id IS NULL OR e.orchestration_run_id IS NULL
      OR e.orchestration_set_id IS NULL OR e.orchestration_dashboard_id IS NULL
      OR e.order_id IS NULL
      OR e.paper_network IS NULL OR e.market_data_venue IS NULL
    THEN 'incomplete'
    WHEN c.id IS NOT NULL AND (
      c.mode_id IS DISTINCT FROM e.mode_id OR c.mode_version IS DISTINCT FROM e.mode_version
      OR c.setup_id IS DISTINCT FROM e.setup_id OR c.setup_version IS DISTINCT FROM e.setup_version
      OR c.config_hash IS DISTINCT FROM e.config_hash
      OR c.condition_catalog_hash IS DISTINCT FROM e.condition_catalog_hash
      OR c.side IS DISTINCT FROM e.side OR c.decision_id IS DISTINCT FROM e.decision_id
      OR c.decision_key IS DISTINCT FROM e.decision_key OR c.intent_id IS DISTINCT FROM e.intent_id
      OR c.correlation_run_id IS DISTINCT FROM e.correlation_run_id
      OR c.orchestration_run_id IS DISTINCT FROM e.orchestration_run_id
      OR c.orchestration_set_id IS DISTINCT FROM e.orchestration_set_id
      OR c.orchestration_dashboard_id IS DISTINCT FROM e.orchestration_dashboard_id
      OR c.paper_network IS DISTINCT FROM e.paper_network
      OR c.market_data_venue IS DISTINCT FROM e.market_data_venue
      OR c.order_id IS DISTINCT FROM e.order_id
      OR c.position_id IS NULL OR c.trade_id IS NULL
    ) THEN 'incomplete'
    ELSE 'canonical'
  END AS lineage_classification
) identity
SQL);
    }
}
