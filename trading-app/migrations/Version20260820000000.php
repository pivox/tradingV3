<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Derive holding time and strong MFE/MAE provenance from exact ledger fill timestamps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP VIEW position_trade_analysis_v2');
        $this->addSql('ALTER VIEW position_trade_analysis_v2_legacy_source RENAME TO position_trade_analysis_v2_pre_fill_timing');
        $this->addSql(<<<'SQL'
CREATE VIEW position_trade_analysis_v2_legacy_source AS
SELECT
    (jsonb_populate_record(
        NULL::position_trade_analysis_v2_pre_fill_timing,
        to_jsonb(old) || jsonb_build_object(
            'holding_time_sec', timing.holding_time_sec,
            'mfe_pct', excursion.mfe_pct,
            'mae_pct', excursion.mae_pct,
            'mfe_price', excursion.mfe_price,
            'mae_price', excursion.mae_price,
            'mfe_at', excursion.mfe_at,
            'mae_at', excursion.mae_at,
            'mfe_r', excursion.mfe_r,
            'mae_r', excursion.mae_r,
            'mfe_mae_data_quality', excursion.data_quality,
            'net_pnl_usdt', CASE WHEN timing.chronology_valid IS FALSE THEN NULL ELSE old.net_pnl_usdt END,
            'realized_net_pnl_r', CASE WHEN timing.chronology_valid IS FALSE THEN NULL ELSE old.realized_net_pnl_r END,
            'cost_completeness', CASE WHEN timing.chronology_valid IS FALSE THEN 'partial' ELSE old.cost_completeness END,
            'pnl_quality_flags', CASE
                WHEN timing.chronology_valid IS FALSE THEN
                    COALESCE(old.pnl_quality_flags, '[]'::jsonb) || '["ledger_fill_chronology_invalid"]'::jsonb
                ELSE old.pnl_quality_flags
            END
        )
    )).*,
    timing.holding_time_source,
    excursion.window_source AS mfe_mae_window_source,
    excursion.entry_price_source AS mfe_mae_entry_price_source
FROM position_trade_analysis_v2_pre_fill_timing old
LEFT JOIN trade_lifecycle_event close_event ON close_event.id = old.close_event_id
CROSS JOIN LATERAL (
    SELECT
        CASE
            WHEN old.quantity_status = 'complete'
             AND old.entry_first_fill_at IS NOT NULL
             AND old.exit_last_fill_at IS NOT NULL
              THEN old.exit_last_fill_at >= old.entry_first_fill_at
            ELSE NULL
        END AS chronology_valid,
        CASE
            WHEN old.quantity_status = 'complete'
             AND old.entry_first_fill_at IS NOT NULL
             AND old.exit_last_fill_at >= old.entry_first_fill_at
              THEN extract(epoch FROM old.exit_last_fill_at - old.entry_first_fill_at)
            WHEN old.quantity_status = 'complete' THEN NULL
            ELSE old.holding_time_sec
        END AS holding_time_sec,
        CASE
            WHEN old.quantity_status = 'complete'
             AND old.entry_first_fill_at IS NOT NULL
             AND old.exit_last_fill_at >= old.entry_first_fill_at
              THEN 'fill_cost_ledger_v1'
            WHEN old.quantity_status = 'complete' THEN 'invalid_fill_chronology'
            ELSE COALESCE(NULLIF(close_event.extra->> 'holding_time_source', ''), 'legacy_position_history')
        END AS holding_time_source
) timing
CROSS JOIN LATERAL (
    SELECT
        old.quantity_status = 'complete'
        AND timing.chronology_valid
        AND NULLIF(close_event.extra->> 'mfe_mae_window_source', '') = 'fill_cost_ledger_v1'
        AND NULLIF(close_event.extra->> 'mfe_mae_entry_price_source', '') = 'fill_cost_ledger_v1'
        AND trading_v3_safe_timestamptz(close_event.extra->> 'mfe_mae_window_start') = old.entry_first_fill_at
        AND trading_v3_safe_timestamptz(close_event.extra->> 'mfe_mae_window_end') = old.exit_last_fill_at AS exact_window
) evidence
CROSS JOIN LATERAL (
    SELECT
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mfe_pct END AS mfe_pct,
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mae_pct END AS mae_pct,
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mfe_price END AS mfe_price,
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mae_price END AS mae_price,
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mfe_at END AS mfe_at,
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mae_at END AS mae_at,
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mfe_r END AS mfe_r,
        CASE WHEN old.quantity_status IS DISTINCT FROM 'complete' OR evidence.exact_window THEN old.mae_r END AS mae_r,
        CASE
            WHEN old.quantity_status IS DISTINCT FROM 'complete' THEN old.mfe_mae_data_quality
            WHEN evidence.exact_window THEN old.mfe_mae_data_quality
            WHEN old.mfe_mae_data_quality IN ('missing_price_data', 'provider_error') THEN old.mfe_mae_data_quality
            ELSE 'partial'
        END AS data_quality,
        CASE
            WHEN evidence.exact_window THEN 'fill_cost_ledger_v1'
            WHEN old.quantity_status = 'complete' THEN 'unverified_fill_window'
            ELSE COALESCE(NULLIF(close_event.extra->> 'mfe_mae_window_source', ''), 'legacy_position_history')
        END AS window_source,
        CASE
            WHEN evidence.exact_window THEN 'fill_cost_ledger_v1'
            WHEN old.quantity_status = 'complete' THEN 'unverified_entry_price'
            ELSE COALESCE(NULLIF(close_event.extra->> 'mfe_mae_entry_price_source', ''), 'legacy_position_history')
        END AS entry_price_source
) excursion
SQL);

        $this->addCanonicalWrapperSql();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2_legacy_source');
        $this->addSql('ALTER VIEW position_trade_analysis_v2_pre_fill_timing RENAME TO position_trade_analysis_v2_legacy_source');
        $this->addCanonicalWrapperSql();
    }

    private function addCanonicalWrapperSql(): void
    {
        $this->addSql(<<<'SQL'
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
  CASE WHEN identity.lineage_classification = 'canonical' THEN legacy.net_pnl_usdt ELSE NULL END AS canonical_net_pnl_usdt,
  CASE WHEN identity.lineage_classification = 'canonical' THEN legacy.realized_net_pnl_r ELSE NULL END AS canonical_realized_net_pnl_r
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
