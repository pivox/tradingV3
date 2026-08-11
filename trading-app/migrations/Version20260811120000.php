<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Certify position_trade_analysis_v2 net PnL from exact persisted ledger evidence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE VIEW position_trade_ledger_aggregate_v1 AS
WITH eligible_ledger AS (
    SELECT
        ledger.*,
        lower(ledger.fill_role) AS normalized_fill_role,
        CASE
            WHEN ledger.quantity <> 'NaN'::numeric
             AND ledger.quantity > 0
             AND ledger.price <> 'NaN'::numeric
             AND ledger.price > 0
             AND (ledger.notional IS NULL OR (ledger.notional <> 'NaN'::numeric AND ledger.notional > 0))
            THEN ledger.quantity
        END AS valid_quantity,
        CASE
            WHEN ledger.quantity <> 'NaN'::numeric
             AND ledger.quantity > 0
             AND ledger.price <> 'NaN'::numeric
             AND ledger.price > 0
             AND (ledger.notional IS NULL OR (ledger.notional <> 'NaN'::numeric AND ledger.notional > 0))
            THEN COALESCE(ledger.notional, ledger.price * ledger.quantity)
        END AS valid_notional,
        CASE
            WHEN ledger.fee_usdt <> 'NaN'::numeric AND ledger.fee_usdt >= 0 THEN ledger.fee_usdt
        END AS normalized_fee_usdt,
        jsonb_typeof(ledger.quality_flags) = 'array'
            AND ledger.quality_flags = '[]'::jsonb
            AND (ledger.spread_cost_usdt IS NULL OR (ledger.spread_cost_usdt <> 'NaN'::numeric AND ledger.spread_cost_usdt >= 0))
            AND (ledger.slippage_cost_usdt IS NULL OR (ledger.slippage_cost_usdt <> 'NaN'::numeric AND ledger.slippage_cost_usdt >= 0))
            AND (ledger.funding_usdt IS NULL OR ledger.funding_usdt <> 'NaN'::numeric)
            AND (ledger.borrow_cost_usdt IS NULL OR (ledger.borrow_cost_usdt <> 'NaN'::numeric AND ledger.borrow_cost_usdt >= 0))
            AND (ledger.liquidation_fee_usdt IS NULL OR (ledger.liquidation_fee_usdt <> 'NaN'::numeric AND ledger.liquidation_fee_usdt >= 0)) AS row_quality_valid
    FROM fill_cost_ledger ledger
    WHERE NOT (
        jsonb_typeof(ledger.quality_flags) = 'array'
        AND ledger.quality_flags ?| ARRAY['fill_cancelled', 'fill_corrected', 'fill_reversed', 'voided']
    )
), ledger_aggregate AS (
    SELECT
        internal_trade_id,
        exchange,
        market_type,
        symbol,
        market_data_venue,
        paper_execution_cell_id,
        configuration_snapshot_id,
        paper_network,
        paper_eligibility,

        COUNT(*) AS ledger_row_count,
        COUNT(*) FILTER (WHERE NOT row_quality_valid) AS ledger_quality_flagged_count,
        bool_and(row_quality_valid) AS ledger_quality_valid,

        MIN(occurred_at) FILTER (WHERE normalized_fill_role = 'entry') AS entry_first_fill_at,
        MAX(occurred_at) FILTER (WHERE normalized_fill_role = 'entry') AS entry_last_fill_at,
        COUNT(*) FILTER (WHERE normalized_fill_role = 'entry') AS entry_fill_count,
        COUNT(valid_quantity) FILTER (WHERE normalized_fill_role = 'entry') AS entry_valid_fill_count,
        SUM(valid_quantity) FILTER (WHERE normalized_fill_role = 'entry') AS entry_qty,
        SUM(valid_notional) FILTER (WHERE normalized_fill_role = 'entry') AS entry_notional,
        SUM(valid_notional) FILTER (WHERE normalized_fill_role = 'entry')
            / NULLIF(SUM(valid_quantity) FILTER (WHERE normalized_fill_role = 'entry'), 0) AS entry_vwap,

        MIN(occurred_at) FILTER (WHERE normalized_fill_role = 'exit') AS exit_first_fill_at,
        MAX(occurred_at) FILTER (WHERE normalized_fill_role = 'exit') AS exit_last_fill_at,
        COUNT(*) FILTER (WHERE normalized_fill_role = 'exit') AS exit_fill_count,
        COUNT(valid_quantity) FILTER (WHERE normalized_fill_role = 'exit') AS exit_valid_fill_count,
        SUM(valid_quantity) FILTER (WHERE normalized_fill_role = 'exit') AS exit_qty,
        SUM(valid_notional) FILTER (WHERE normalized_fill_role = 'exit') AS exit_notional,
        SUM(valid_notional) FILTER (WHERE normalized_fill_role = 'exit')
            / NULLIF(SUM(valid_quantity) FILTER (WHERE normalized_fill_role = 'exit'), 0) AS exit_vwap,

        COUNT(normalized_fee_usdt) FILTER (WHERE normalized_fill_role = 'entry') AS entry_fee_usdt_count,
        SUM(normalized_fee_usdt) FILTER (WHERE normalized_fill_role = 'entry') AS entry_fee_usdt,
        COUNT(normalized_fee_usdt) FILTER (WHERE normalized_fill_role = 'exit') AS exit_fee_usdt_count,
        SUM(normalized_fee_usdt) FILTER (WHERE normalized_fill_role = 'exit') AS exit_fee_usdt,
        COUNT(normalized_fee_usdt) FILTER (WHERE normalized_fill_role IN ('entry', 'exit')) AS fee_usdt_count,
        SUM(normalized_fee_usdt) FILTER (WHERE normalized_fill_role IN ('entry', 'exit')) AS fee_usdt,

        COUNT(spread_cost_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND spread_cost_usdt <> 'NaN'::numeric AND spread_cost_usdt >= 0) AS entry_spread_cost_explicit_count,
        SUM(spread_cost_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND spread_cost_usdt <> 'NaN'::numeric AND spread_cost_usdt >= 0) AS entry_spread_cost_usdt,
        COUNT(spread_cost_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND spread_cost_usdt <> 'NaN'::numeric AND spread_cost_usdt >= 0) AS exit_spread_cost_explicit_count,
        SUM(spread_cost_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND spread_cost_usdt <> 'NaN'::numeric AND spread_cost_usdt >= 0) AS exit_spread_cost_usdt,
        COUNT(spread_cost_usdt) FILTER (WHERE spread_cost_usdt <> 'NaN'::numeric AND spread_cost_usdt >= 0) AS spread_cost_explicit_count,
        SUM(spread_cost_usdt) FILTER (WHERE spread_cost_usdt <> 'NaN'::numeric AND spread_cost_usdt >= 0) AS spread_cost_usdt,

        COUNT(slippage_cost_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND slippage_cost_usdt <> 'NaN'::numeric AND slippage_cost_usdt >= 0) AS entry_slippage_cost_explicit_count,
        SUM(slippage_cost_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND slippage_cost_usdt <> 'NaN'::numeric AND slippage_cost_usdt >= 0) AS entry_slippage_cost_usdt,
        COUNT(slippage_cost_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND slippage_cost_usdt <> 'NaN'::numeric AND slippage_cost_usdt >= 0) AS exit_slippage_cost_explicit_count,
        SUM(slippage_cost_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND slippage_cost_usdt <> 'NaN'::numeric AND slippage_cost_usdt >= 0) AS exit_slippage_cost_usdt,
        COUNT(slippage_cost_usdt) FILTER (WHERE slippage_cost_usdt <> 'NaN'::numeric AND slippage_cost_usdt >= 0) AS slippage_cost_explicit_count,
        SUM(slippage_cost_usdt) FILTER (WHERE slippage_cost_usdt <> 'NaN'::numeric AND slippage_cost_usdt >= 0) AS slippage_cost_usdt,

        COUNT(funding_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND funding_usdt <> 'NaN'::numeric) AS entry_funding_explicit_count,
        SUM(funding_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND funding_usdt <> 'NaN'::numeric) AS entry_funding_usdt,
        COUNT(funding_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND funding_usdt <> 'NaN'::numeric) AS exit_funding_explicit_count,
        SUM(funding_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND funding_usdt <> 'NaN'::numeric) AS exit_funding_usdt,
        COUNT(funding_usdt) FILTER (WHERE funding_usdt <> 'NaN'::numeric) AS funding_explicit_count,
        SUM(funding_usdt) FILTER (WHERE funding_usdt <> 'NaN'::numeric) AS funding_usdt,

        COUNT(borrow_cost_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND borrow_cost_usdt <> 'NaN'::numeric AND borrow_cost_usdt >= 0) AS entry_borrow_cost_explicit_count,
        SUM(borrow_cost_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND borrow_cost_usdt <> 'NaN'::numeric AND borrow_cost_usdt >= 0) AS entry_borrow_cost_usdt,
        COUNT(borrow_cost_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND borrow_cost_usdt <> 'NaN'::numeric AND borrow_cost_usdt >= 0) AS exit_borrow_cost_explicit_count,
        SUM(borrow_cost_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND borrow_cost_usdt <> 'NaN'::numeric AND borrow_cost_usdt >= 0) AS exit_borrow_cost_usdt,
        COUNT(borrow_cost_usdt) FILTER (WHERE borrow_cost_usdt <> 'NaN'::numeric AND borrow_cost_usdt >= 0) AS borrow_cost_explicit_count,
        SUM(borrow_cost_usdt) FILTER (WHERE borrow_cost_usdt <> 'NaN'::numeric AND borrow_cost_usdt >= 0) AS borrow_cost_usdt,

        COUNT(liquidation_fee_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND liquidation_fee_usdt <> 'NaN'::numeric AND liquidation_fee_usdt >= 0) AS entry_liquidation_fee_explicit_count,
        SUM(liquidation_fee_usdt) FILTER (WHERE normalized_fill_role = 'entry' AND liquidation_fee_usdt <> 'NaN'::numeric AND liquidation_fee_usdt >= 0) AS entry_liquidation_fee_usdt,
        COUNT(liquidation_fee_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND liquidation_fee_usdt <> 'NaN'::numeric AND liquidation_fee_usdt >= 0) AS exit_liquidation_fee_explicit_count,
        SUM(liquidation_fee_usdt) FILTER (WHERE normalized_fill_role = 'exit' AND liquidation_fee_usdt <> 'NaN'::numeric AND liquidation_fee_usdt >= 0) AS exit_liquidation_fee_usdt,
        COUNT(liquidation_fee_usdt) FILTER (WHERE liquidation_fee_usdt <> 'NaN'::numeric AND liquidation_fee_usdt >= 0) AS liquidation_fee_explicit_count,
        SUM(liquidation_fee_usdt) FILTER (WHERE liquidation_fee_usdt <> 'NaN'::numeric AND liquidation_fee_usdt >= 0) AS liquidation_fee_usdt,

        COUNT(DISTINCT side) FILTER (WHERE normalized_fill_role = 'entry' AND side IS NOT NULL) AS entry_side_cardinality,
        array_agg(DISTINCT side ORDER BY side) FILTER (WHERE normalized_fill_role = 'entry' AND side IS NOT NULL) AS entry_sides,
        COUNT(DISTINCT side) FILTER (WHERE normalized_fill_role = 'exit' AND side IS NOT NULL) AS exit_side_cardinality,
        array_agg(DISTINCT side ORDER BY side) FILTER (WHERE normalized_fill_role = 'exit' AND side IS NOT NULL) AS exit_sides,
        COUNT(DISTINCT side) FILTER (WHERE side IS NOT NULL) AS side_cardinality,
        array_agg(DISTINCT side ORDER BY side) FILTER (WHERE side IS NOT NULL) AS sides
    FROM eligible_ledger
    GROUP BY
        internal_trade_id,
        exchange,
        market_type,
        symbol,
        market_data_venue,
        paper_execution_cell_id,
        configuration_snapshot_id,
        paper_network,
        paper_eligibility
), classified AS (
    SELECT
        aggregate.*,
        CASE
            WHEN entry_fill_count = 0 THEN 'missing_entry_fill'
            WHEN exit_fill_count = 0 THEN 'open_position'
            WHEN entry_valid_fill_count < entry_fill_count
              OR exit_valid_fill_count < exit_fill_count THEN 'invalid_fill_quantity'
            WHEN exit_qty - entry_qty > 0.00000001 THEN 'quantity_mismatch'
            WHEN abs(entry_qty - exit_qty) <= 0.00000001 THEN 'complete'
            ELSE 'partial_exit'
        END AS quantity_status,
        CASE
            WHEN entry_qty IS NULL THEN NULL
            WHEN exit_qty IS NULL THEN entry_qty
            WHEN abs(entry_qty - exit_qty) <= 0.00000001 THEN 0::numeric
            ELSE entry_qty - exit_qty
        END AS remaining_qty
    FROM ledger_aggregate aggregate
)
SELECT
    classified.*,
    CASE
        WHEN entry_fill_count = 0 THEN NULL
        ELSE entry_fee_usdt_count = entry_fill_count
    END AS entry_fee_usdt_complete,
    CASE
        WHEN exit_fill_count = 0 THEN NULL
        ELSE exit_fee_usdt_count = exit_fill_count
    END AS exit_fee_usdt_complete,
    CASE
        WHEN entry_fill_count + exit_fill_count = 0 THEN NULL
        ELSE fee_usdt_count = entry_fill_count + exit_fill_count
    END AS fee_usdt_complete,
    quantity_status = 'complete' AS position_fully_closed
FROM classified
SQL);

        $this->addSql('DROP VIEW position_trade_analysis_v2');
        $this->addSql('ALTER VIEW position_trade_analysis_v2_legacy_source RENAME TO position_trade_analysis_v2_pre_ledger');
        $this->addSql(<<<'SQL'
CREATE VIEW position_trade_analysis_v2_legacy_source AS
SELECT
    (jsonb_populate_record(
        NULL::position_trade_analysis_v2_pre_ledger,
        to_jsonb(old) || jsonb_build_object(
            'snapshot_kline_time', snapshot.kline_time,
            'entry_rsi', trading_v3_safe_numeric(snapshot.values->> 'rsi'),
            'entry_atr', trading_v3_safe_numeric(snapshot.values->> 'atr'),
            'entry_macd', trading_v3_safe_numeric(snapshot.values->> 'macd'),
            'entry_ma9', trading_v3_safe_numeric(snapshot.values->> 'ma9'),
            'entry_ma21', trading_v3_safe_numeric(snapshot.values->> 'ma21'),
            'entry_vwap', COALESCE(ledger.entry_vwap, trading_v3_safe_numeric(snapshot.values->> 'vwap')),
            'gross_realized_pnl_usdt', financial.gross_realized_pnl_usdt,
            'entry_fee_usdt', ledger.entry_fee_usdt,
            'exit_fee_usdt', ledger.exit_fee_usdt,
            'other_trading_fees_usdt', financial.other_trading_fees_usdt,
            'funding_usdt', financial.funding_usdt,
            'spread_cost_usdt', ledger.spread_cost_usdt,
            'slippage_cost_usdt', ledger.slippage_cost_usdt,
            'borrow_cost_usdt', financial.borrow_cost_usdt,
            'liquidation_fee_usdt', financial.liquidation_fee_usdt,
            'total_known_cost_usdt', certified.total_known_cost_usdt,
            'net_pnl_usdt', certified.net_pnl_usdt,
            'realized_gross_pnl_r', certified.realized_gross_pnl_r,
            'realized_net_pnl_r', certified.realized_net_pnl_r,
            'position_fully_closed', ledger.position_fully_closed,
            'pnl_source', CASE WHEN ledger.ledger_row_count IS NOT NULL THEN 'fill_cost_ledger_v1' ELSE old.pnl_source END,
            'pnl_quality_flags', to_jsonb(quality.flags),
            'cost_completeness', certified.cost_completeness
        )
    )).*,
    ledger.entry_first_fill_at,
    ledger.entry_last_fill_at,
    ledger.entry_qty,
    ledger.exit_first_fill_at,
    ledger.exit_last_fill_at,
    ledger.exit_qty,
    ledger.exit_vwap,
    ledger.remaining_qty,
    ledger.quantity_status
FROM position_trade_analysis_v2_pre_ledger old
JOIN trade_lifecycle_event entry_event ON entry_event.id = old.entry_event_id
LEFT JOIN trade_lifecycle_event close_event ON close_event.id = old.close_event_id
LEFT JOIN position_trade_ledger_aggregate_v1 ledger
  ON close_event.id IS NOT NULL
 AND ledger.internal_trade_id IS NOT DISTINCT FROM old.internal_trade_id
 AND ledger.exchange IS NOT DISTINCT FROM old.exchange
 AND ledger.market_type IS NOT DISTINCT FROM old.market_type
 AND ledger.symbol IS NOT DISTINCT FROM old.symbol
 AND ledger.market_data_venue IS NOT DISTINCT FROM old.market_data_venue
 AND ledger.paper_execution_cell_id IS NOT DISTINCT FROM entry_event.paper_execution_cell_id
 AND ledger.configuration_snapshot_id IS NOT DISTINCT FROM entry_event.configuration_snapshot_id
 AND ledger.paper_network IS NOT DISTINCT FROM entry_event.paper_network
 AND ledger.paper_eligibility IS NOT DISTINCT FROM entry_event.paper_eligibility
 AND ledger.internal_trade_id IS NOT DISTINCT FROM COALESCE(NULLIF(close_event.internal_trade_id, ''), NULLIF(close_event.extra->> 'internal_trade_id', ''))
 AND ledger.exchange IS NOT DISTINCT FROM close_event.exchange
 AND ledger.market_type IS NOT DISTINCT FROM close_event.market_type
 AND ledger.symbol IS NOT DISTINCT FROM close_event.symbol
 AND ledger.market_data_venue IS NOT DISTINCT FROM close_event.market_data_venue
 AND ledger.paper_execution_cell_id IS NOT DISTINCT FROM close_event.paper_execution_cell_id
 AND ledger.configuration_snapshot_id IS NOT DISTINCT FROM close_event.configuration_snapshot_id
 AND ledger.paper_network IS NOT DISTINCT FROM close_event.paper_network
 AND ledger.paper_eligibility IS NOT DISTINCT FROM close_event.paper_eligibility
LEFT JOIN LATERAL (
    SELECT s.kline_time, s.values
    FROM indicator_snapshots s
    WHERE s.symbol = old.symbol
      AND s.timeframe = old.timeframe
      AND s.exchange IS NOT DISTINCT FROM old.exchange
      AND s.market_type IS NOT DISTINCT FROM old.market_type
      AND s.kline_time <= old.entry_time
    ORDER BY s.kline_time DESC, s.id DESC
    LIMIT 1
) snapshot ON true
LEFT JOIN LATERAL (
    SELECT
        COALESCE(bool_or(
            candidate.exchange IS DISTINCT FROM old.exchange
            OR candidate.market_type IS DISTINCT FROM old.market_type
            OR candidate.symbol IS DISTINCT FROM old.symbol
            OR candidate.market_data_venue IS DISTINCT FROM old.market_data_venue
        ), false) AS market_identity_mismatch,
        COALESCE(bool_or(
            candidate.exchange IS NOT DISTINCT FROM old.exchange
            AND candidate.market_type IS NOT DISTINCT FROM old.market_type
            AND candidate.symbol IS NOT DISTINCT FROM old.symbol
            AND candidate.market_data_venue IS NOT DISTINCT FROM old.market_data_venue
            AND (
                candidate.paper_execution_cell_id IS DISTINCT FROM entry_event.paper_execution_cell_id
                OR candidate.configuration_snapshot_id IS DISTINCT FROM entry_event.configuration_snapshot_id
                OR candidate.paper_network IS DISTINCT FROM entry_event.paper_network
                OR candidate.paper_eligibility IS DISTINCT FROM entry_event.paper_eligibility
            )
        ), false) AS paper_provenance_mismatch
    FROM position_trade_ledger_aggregate_v1 candidate
    WHERE candidate.internal_trade_id IS NOT DISTINCT FROM old.internal_trade_id
) probe ON true
CROSS JOIN LATERAL (
    SELECT
        close_event.id IS NOT NULL
        AND COALESCE(NULLIF(close_event.internal_trade_id, ''), NULLIF(close_event.extra->> 'internal_trade_id', '')) IS NOT DISTINCT FROM old.internal_trade_id
        AND close_event.exchange IS NOT DISTINCT FROM old.exchange
        AND close_event.market_type IS NOT DISTINCT FROM old.market_type
        AND close_event.symbol IS NOT DISTINCT FROM old.symbol
        AND close_event.market_data_venue IS NOT DISTINCT FROM old.market_data_venue AS market_identity_coherent,
        close_event.id IS NOT NULL
        AND close_event.paper_execution_cell_id IS NOT DISTINCT FROM entry_event.paper_execution_cell_id
        AND close_event.configuration_snapshot_id IS NOT DISTINCT FROM entry_event.configuration_snapshot_id
        AND close_event.paper_network IS NOT DISTINCT FROM entry_event.paper_network
        AND close_event.paper_eligibility IS NOT DISTINCT FROM entry_event.paper_eligibility AS paper_provenance_coherent,
        close_event.id IS NOT NULL
        AND close_event.mode_id IS NOT DISTINCT FROM entry_event.mode_id
        AND close_event.mode_version IS NOT DISTINCT FROM entry_event.mode_version
        AND close_event.setup_id IS NOT DISTINCT FROM entry_event.setup_id
        AND close_event.setup_version IS NOT DISTINCT FROM entry_event.setup_version
        AND close_event.config_hash IS NOT DISTINCT FROM entry_event.config_hash
        AND close_event.condition_catalog_hash IS NOT DISTINCT FROM entry_event.condition_catalog_hash
        AND close_event.side IS NOT DISTINCT FROM entry_event.side
        AND close_event.decision_id IS NOT DISTINCT FROM entry_event.decision_id
        AND close_event.decision_key IS NOT DISTINCT FROM entry_event.decision_key
        AND close_event.intent_id IS NOT DISTINCT FROM entry_event.intent_id
        AND close_event.correlation_run_id IS NOT DISTINCT FROM entry_event.correlation_run_id
        AND close_event.orchestration_run_id IS NOT DISTINCT FROM entry_event.orchestration_run_id
        AND close_event.orchestration_set_id IS NOT DISTINCT FROM entry_event.orchestration_set_id
        AND close_event.orchestration_dashboard_id IS NOT DISTINCT FROM entry_event.orchestration_dashboard_id
        AND close_event.order_id IS NOT DISTINCT FROM entry_event.order_id
        AND close_event.position_id IS NOT NULL
        AND close_event.trade_id IS NOT NULL AS lifecycle_identity_coherent
) identity
CROSS JOIN LATERAL (
    SELECT
        CASE
            WHEN upper(entry_event.side) = 'LONG' THEN ledger.exit_notional - ledger.entry_notional
            WHEN upper(entry_event.side) = 'SHORT' THEN ledger.entry_notional - ledger.exit_notional
        END AS gross_realized_pnl_usdt,
        CASE
            WHEN trading_v3_safe_numeric(close_event.extra->> 'other_trading_fees_usdt') >= 0
                THEN trading_v3_safe_numeric(close_event.extra->> 'other_trading_fees_usdt')
        END AS other_trading_fees_usdt,
        CASE
            WHEN ledger.funding_explicit_count > 0 THEN ledger.funding_usdt
            ELSE trading_v3_safe_numeric(close_event.extra->> 'funding_usdt')
        END AS funding_usdt,
        CASE
            WHEN ledger.borrow_cost_explicit_count > 0 THEN ledger.borrow_cost_usdt
            WHEN trading_v3_safe_numeric(close_event.extra->> 'borrow_cost_usdt') >= 0
                THEN trading_v3_safe_numeric(close_event.extra->> 'borrow_cost_usdt')
        END AS borrow_cost_usdt,
        CASE
            WHEN ledger.liquidation_fee_explicit_count > 0 THEN ledger.liquidation_fee_usdt
            WHEN trading_v3_safe_numeric(close_event.extra->> 'liquidation_fee_usdt') >= 0
                THEN trading_v3_safe_numeric(close_event.extra->> 'liquidation_fee_usdt')
        END AS liquidation_fee_usdt
) financial
CROSS JOIN LATERAL (
    SELECT ARRAY_REMOVE(ARRAY[
        CASE WHEN old.close_event_id IS NULL THEN 'unmatched' END,
        CASE WHEN old.close_event_id IS NOT NULL AND ledger.ledger_row_count IS NULL AND (
            identity.market_identity_coherent IS NOT TRUE OR probe.market_identity_mismatch
        ) THEN 'ledger_market_identity_mismatch' END,
        CASE WHEN old.close_event_id IS NOT NULL AND ledger.ledger_row_count IS NULL
            AND identity.market_identity_coherent
            AND NOT probe.market_identity_mismatch
            AND (identity.paper_provenance_coherent IS NOT TRUE OR probe.paper_provenance_mismatch)
            THEN 'ledger_paper_provenance_mismatch' END,
        CASE WHEN old.close_event_id IS NOT NULL AND ledger.ledger_row_count IS NULL
            AND identity.market_identity_coherent
            AND identity.paper_provenance_coherent
            AND NOT probe.market_identity_mismatch
            AND NOT probe.paper_provenance_mismatch
            THEN 'ledger_quantity_aggregate_missing' END,
        CASE WHEN ledger.quantity_status = 'missing_entry_fill' THEN 'missing_entry_fill' END,
        CASE WHEN ledger.quantity_status = 'open_position' THEN 'missing_exit_fill' END,
        CASE WHEN ledger.quantity_status = 'invalid_fill_quantity' THEN 'invalid_fill_quantity' END,
        CASE WHEN ledger.quantity_status = 'quantity_mismatch' THEN 'quantity_mismatch' END,
        CASE WHEN ledger.quantity_status = 'partial_exit' THEN 'partial_exit' END,
        CASE WHEN ledger.ledger_row_count IS NOT NULL AND ledger.ledger_quality_valid IS NOT TRUE THEN 'ledger_quality_invalid' END,
        CASE WHEN ledger.ledger_row_count IS NOT NULL AND identity.lifecycle_identity_coherent IS NOT TRUE THEN 'ledger_lifecycle_identity_mismatch' END,
        CASE WHEN ledger.ledger_row_count IS NOT NULL AND NOT (
            (upper(entry_event.side) = 'LONG'
                AND ledger.entry_side_cardinality = 1 AND upper(ledger.entry_sides[1]) = 'BUY'
                AND ledger.exit_side_cardinality = 1 AND upper(ledger.exit_sides[1]) = 'SELL')
            OR (upper(entry_event.side) = 'SHORT'
                AND ledger.entry_side_cardinality = 1 AND upper(ledger.entry_sides[1]) = 'SELL'
                AND ledger.exit_side_cardinality = 1 AND upper(ledger.exit_sides[1]) = 'BUY')
        ) THEN 'ledger_side_mismatch' END,
        CASE WHEN old.close_event_id IS NOT NULL AND financial.gross_realized_pnl_usdt IS NULL THEN 'missing_gross_pnl' END,
        CASE WHEN old.close_event_id IS NOT NULL AND (
            ledger.ledger_row_count IS NULL OR ledger.entry_fee_usdt_complete IS NOT TRUE
        ) THEN 'missing_entry_fee' END,
        CASE WHEN old.close_event_id IS NOT NULL AND (
            ledger.ledger_row_count IS NULL OR ledger.exit_fee_usdt_complete IS NOT TRUE
        ) THEN 'missing_exit_fee' END,
        CASE WHEN old.close_event_id IS NOT NULL AND financial.other_trading_fees_usdt IS NULL THEN 'missing_other_trading_fees' END,
        CASE WHEN old.close_event_id IS NOT NULL AND financial.funding_usdt IS NULL THEN 'missing_funding' END,
        CASE WHEN old.close_event_id IS NOT NULL AND (ledger.ledger_row_count IS NULL OR NOT (
            ledger.entry_spread_cost_explicit_count = ledger.entry_fill_count
            AND ledger.exit_spread_cost_explicit_count = ledger.exit_fill_count
        )) THEN 'missing_spread_cost' END,
        CASE WHEN old.close_event_id IS NOT NULL AND (ledger.ledger_row_count IS NULL OR NOT (
            ledger.entry_slippage_cost_explicit_count = ledger.entry_fill_count
            AND ledger.exit_slippage_cost_explicit_count = ledger.exit_fill_count
        )) THEN 'missing_slippage_cost' END,
        CASE WHEN old.close_event_id IS NOT NULL AND financial.borrow_cost_usdt IS NULL THEN 'missing_borrow_cost' END,
        CASE WHEN old.close_event_id IS NOT NULL AND financial.liquidation_fee_usdt IS NULL THEN 'missing_liquidation_fee' END
    ], NULL)::text[] AS flags
) quality
CROSS JOIN LATERAL (
    SELECT
        CASE WHEN quality.flags = ARRAY[]::text[] THEN
            ledger.entry_fee_usdt
              + ledger.exit_fee_usdt
              + financial.other_trading_fees_usdt
              - financial.funding_usdt
              + ledger.spread_cost_usdt
              + ledger.slippage_cost_usdt
              + financial.borrow_cost_usdt
              + financial.liquidation_fee_usdt
        END AS total_known_cost_usdt,
        CASE WHEN quality.flags = ARRAY[]::text[] THEN
            financial.gross_realized_pnl_usdt
              - ledger.entry_fee_usdt
              - ledger.exit_fee_usdt
              - financial.other_trading_fees_usdt
              + financial.funding_usdt
              - ledger.spread_cost_usdt
              - ledger.slippage_cost_usdt
              - financial.borrow_cost_usdt
              - financial.liquidation_fee_usdt
        END AS net_pnl_usdt,
        CASE WHEN financial.gross_realized_pnl_usdt IS NOT NULL AND old.risk_usdt_at_entry > 0
            THEN financial.gross_realized_pnl_usdt / old.risk_usdt_at_entry
        END AS realized_gross_pnl_r,
        CASE WHEN quality.flags = ARRAY[]::text[] AND old.risk_usdt_at_entry > 0 THEN
            (
                financial.gross_realized_pnl_usdt
                  - ledger.entry_fee_usdt
                  - ledger.exit_fee_usdt
                  - financial.other_trading_fees_usdt
                  + financial.funding_usdt
                  - ledger.spread_cost_usdt
                  - ledger.slippage_cost_usdt
                  - financial.borrow_cost_usdt
                  - financial.liquidation_fee_usdt
            ) / old.risk_usdt_at_entry
        END AS realized_net_pnl_r,
        CASE
            WHEN old.close_event_id IS NULL THEN 'not_applicable'
            WHEN quality.flags = ARRAY[]::text[] THEN 'complete'
            WHEN ledger.ledger_row_count IS NULL
             AND financial.gross_realized_pnl_usdt IS NULL
             AND financial.other_trading_fees_usdt IS NULL
             AND financial.funding_usdt IS NULL
             AND financial.borrow_cost_usdt IS NULL
             AND financial.liquidation_fee_usdt IS NULL THEN 'unknown'
            ELSE 'partial'
        END AS cost_completeness
) certified
SQL);

        $this->addCanonicalWrapperSql();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2_legacy_source');
        $this->addSql('ALTER VIEW position_trade_analysis_v2_pre_ledger RENAME TO position_trade_analysis_v2_legacy_source');
        $this->addCanonicalWrapperSql();
        $this->addSql('DROP VIEW IF EXISTS position_trade_ledger_aggregate_v1');
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
