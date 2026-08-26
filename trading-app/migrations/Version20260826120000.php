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
    to_jsonb(old_1.*) || jsonb_build_object(
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
            ELSE old_1.pnl_source
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
    tail_position integer;
BEGIN
    SELECT pg_get_viewdef('position_trade_analysis_v2_legacy_source'::regclass, true)
      INTO definition;
    tail_position := strpos(
        definition,
        '   FROM position_trade_analysis_v2_pre_ledger old_1'
    );
    IF tail_position = 0
        OR strpos(definition, 'jsonb_populate_record') = 0
        OR strpos(definition, 'composed AS MATERIALIZED') > 0
    THEN
        RAISE EXCEPTION 'position_trade_analysis_v2_composite_source_invalid';
    END IF;
    source_tail := rtrim(
        substring(definition FROM tail_position),
        E' \\n\\t;'
    );

    EXECUTE 'CREATE OR REPLACE VIEW position_trade_analysis_v2_legacy_source AS '
        || 'WITH composed AS MATERIALIZED (SELECT '
        || {$recordLiteral} || ' AS legacy_record, '
        || {$ledgerLiteral} || ' '
        || source_tail
        || ') SELECT (composed.legacy_record).*, '
        || {$composedLiteral}
        || ' FROM composed';
END
\$position_trade_analysis_composite_once\$
SQL;
        }

        return <<<SQL
DO \$position_trade_analysis_composite_restore\$
DECLARE
    definition text;
    source_tail text;
    tail_position integer;
    tail_end_position integer;
    expanded_columns text;
BEGIN
    SELECT pg_get_viewdef('position_trade_analysis_v2_legacy_source'::regclass, true)
      INTO definition;
    tail_position := strpos(
        definition,
        '           FROM position_trade_analysis_v2_pre_ledger old_1'
    );
    tail_end_position := strpos(
        definition,
        E'\n        )\n SELECT (composed.legacy_record)'
    );
    IF tail_position = 0
        OR tail_end_position <= tail_position
        OR strpos(definition, 'composed AS MATERIALIZED') = 0
    THEN
        RAISE EXCEPTION 'position_trade_analysis_v2_composite_restore_source_invalid';
    END IF;
    source_tail := rtrim(substring(
        definition
        FROM tail_position
        FOR tail_end_position - tail_position
    ));

    SELECT string_agg(
        format('(%s).%I AS %I', {$recordLiteral}, attribute.attname, attribute.attname),
        ', ' ORDER BY attribute.attnum
    )
      INTO expanded_columns
      FROM pg_attribute attribute
     WHERE attribute.attrelid = 'position_trade_analysis_v2_pre_ledger'::regclass
       AND attribute.attnum > 0
       AND NOT attribute.attisdropped;
    IF expanded_columns IS NULL THEN
        RAISE EXCEPTION 'position_trade_analysis_v2_composite_restore_columns_invalid';
    END IF;

    EXECUTE 'CREATE OR REPLACE VIEW position_trade_analysis_v2_legacy_source AS SELECT '
        || expanded_columns || ', '
        || {$ledgerLiteral} || ' '
        || source_tail;
END
\$position_trade_analysis_composite_restore\$
SQL;
    }

    private function sqlLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
