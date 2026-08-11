<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aggregate exact fill-cost ledger evidence without replacing position_trade_analysis_v2';
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS position_trade_ledger_aggregate_v1');
    }
}
