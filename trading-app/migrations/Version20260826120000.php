<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Evaluate the legacy PnL composite once to bound PostgreSQL planner memory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql($this->rewriteSql(optimized: true));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->rewriteSql(optimized: false));
    }

    private function rewriteSql(bool $optimized): string
    {
        $recordExpression = <<<'SQL'
jsonb_populate_record(
    NULL::position_trade_analysis_v2_pre_ledger,
    to_jsonb(old_source.*) || jsonb_build_object(
        'snapshot_kline_time', snapshot.kline_time,
        'entry_rsi', trading_v3_safe_numeric(snapshot.values->>'rsi'),
        'entry_atr', trading_v3_safe_numeric(snapshot.values->>'atr'),
        'entry_macd', trading_v3_safe_numeric(snapshot.values->>'macd'),
        'entry_ma9', trading_v3_safe_numeric(snapshot.values->>'ma9'),
        'entry_ma21', trading_v3_safe_numeric(snapshot.values->>'ma21'),
        'entry_vwap', COALESCE(ledger.entry_vwap, trading_v3_safe_numeric(snapshot.values->>'vwap')),
        'gross_realized_pnl_usdt', financial.gross_realized_pnl_usdt,
        'entry_fee_usdt', financial.entry_fee_usdt,
        'exit_fee_usdt', financial.exit_fee_usdt,
        'other_trading_fees_usdt', financial.other_trading_fees_usdt,
        'funding_usdt', financial.funding_usdt,
        'spread_cost_usdt', financial.spread_cost_usdt,
        'slippage_cost_usdt', financial.slippage_cost_usdt,
        'borrow_cost_usdt', financial.borrow_cost_usdt,
        'liquidation_fee_usdt', financial.liquidation_fee_usdt,
        'total_known_cost_usdt', certified.total_known_cost_usdt,
        'net_pnl_usdt', certified.net_pnl_usdt,
        'realized_gross_pnl_r', certified.realized_gross_pnl_r,
        'realized_net_pnl_r', certified.realized_net_pnl_r,
        'position_fully_closed', ledger.position_fully_closed,
        'pnl_source', CASE
            WHEN ledger.ledger_row_count IS NOT NULL THEN 'fill_cost_ledger_v1'
            ELSE old_source.pnl_source
        END,
        'pnl_quality_flags', to_jsonb(quality.flags),
        'cost_completeness', certified.cost_completeness
    )
)
SQL;
        $ledgerColumns = <<<'SQL'
ledger.entry_first_fill_at,
ledger.entry_last_fill_at,
ledger.entry_qty,
ledger.exit_first_fill_at,
ledger.exit_last_fill_at,
ledger.exit_qty,
ledger.exit_vwap,
ledger.remaining_qty,
ledger.quantity_status
SQL;
        $composedColumns = str_replace('ledger.', 'composed.', $ledgerColumns);
        $recordLiteral = $this->sqlLiteral($recordExpression);
        $ledgerLiteral = $this->sqlLiteral($ledgerColumns);
        $composedLiteral = $this->sqlLiteral($composedColumns);

        if ($optimized) {
            return <<<SQL
DO \$position_trade_analysis_composite_once\$
DECLARE
    definition text;
    source_tail text;
    source_alias text;
    record_expression text;
    tail_position integer;
    previous_comment text;
BEGIN
    SELECT pg_get_viewdef('position_trade_analysis_v2_legacy_source'::regclass, true)
      INTO definition;
    SELECT obj_description(
        'position_trade_analysis_v2_legacy_source'::regclass,
        'pg_class'
    ) INTO previous_comment;
    source_alias := (regexp_match(
        definition,
        'FROM position_trade_analysis_v2_pre_ledger ([a-zA-Z_][a-zA-Z0-9_]*)'
    ))[1];
    tail_position := strpos(
        definition,
        'FROM position_trade_analysis_v2_pre_ledger ' || source_alias
    );
    IF source_alias IS NULL
        OR tail_position = 0
        OR previous_comment IS NOT NULL
        OR strpos(definition, 'jsonb_populate_record') = 0
        OR strpos(definition, 'composed AS MATERIALIZED') > 0
    THEN
        RAISE EXCEPTION 'position_trade_analysis_v2_composite_source_invalid';
    END IF;
    source_tail := rtrim(
        substring(definition FROM tail_position),
        E' \\n\\t;'
    );
    record_expression := replace(
        {$recordLiteral},
        'old_source.',
        source_alias || '.'
    );

    EXECUTE 'CREATE OR REPLACE VIEW position_trade_analysis_v2_legacy_source AS '
        || 'WITH composed AS MATERIALIZED (SELECT '
        || record_expression || ' AS legacy_record, '
        || {$ledgerLiteral} || ' '
        || source_tail
        || ') SELECT (composed.legacy_record).*, '
        || {$composedLiteral}
        || ' FROM composed';
    EXECUTE format(
        'COMMENT ON VIEW position_trade_analysis_v2_legacy_source IS %L',
        'trading_v3:Version20260826120000:original_definition:' || definition
    );
END
\$position_trade_analysis_composite_once\$
SQL;
        }

        return <<<SQL
DO \$position_trade_analysis_composite_restore\$
DECLARE
    rollback_comment text;
    original_definition text;
BEGIN
    SELECT obj_description(
        'position_trade_analysis_v2_legacy_source'::regclass,
        'pg_class'
    ) INTO rollback_comment;
    IF rollback_comment IS NULL
        OR NOT starts_with(
            rollback_comment,
            'trading_v3:Version20260826120000:original_definition:'
        )
    THEN
        RAISE EXCEPTION 'position_trade_analysis_v2_composite_restore_source_invalid';
    END IF;
    original_definition := substring(
        rollback_comment
        FROM length('trading_v3:Version20260826120000:original_definition:') + 1
    );
    IF strpos(original_definition, 'jsonb_populate_record') = 0
        OR strpos(original_definition, 'composed AS MATERIALIZED') > 0
    THEN
        RAISE EXCEPTION 'position_trade_analysis_v2_composite_restore_definition_invalid';
    END IF;

    EXECUTE 'CREATE OR REPLACE VIEW position_trade_analysis_v2_legacy_source AS '
        || original_definition;
    COMMENT ON VIEW position_trade_analysis_v2_legacy_source IS NULL;
END
\$position_trade_analysis_composite_restore\$
SQL;
    }

    private function sqlLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
