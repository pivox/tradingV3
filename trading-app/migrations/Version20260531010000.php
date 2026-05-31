<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260531010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope symbol-based storage by exchange and market type with Bitmart perpetual legacy defaults.';
    }

    public function up(Schema $schema): void
    {
        $tables = [
            'contracts',
            'klines',
            'positions',
            'futures_order',
            'futures_plan_order',
            'futures_order_trade',
            'futures_transaction',
            'order_intent',
            'order_protection',
            'indicator_snapshots',
            'trade_zone_events',
            'mtf_state',
        ];

        foreach ($tables as $table) {
            $this->addSql(sprintf("ALTER TABLE %s ADD COLUMN IF NOT EXISTS exchange VARCHAR(32) NOT NULL DEFAULT 'bitmart'", $table));
            $this->addSql(sprintf("ALTER TABLE %s ADD COLUMN IF NOT EXISTS market_type VARCHAR(32) NOT NULL DEFAULT 'perpetual'", $table));
            $this->addSql(sprintf("UPDATE %s SET exchange = COALESCE(NULLIF(exchange, ''), 'bitmart'), market_type = COALESCE(NULLIF(market_type, ''), 'perpetual')", $table));
        }

        $this->addSql("ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS market_type VARCHAR(32) NOT NULL DEFAULT 'perpetual'");
        $this->addSql("UPDATE trade_lifecycle_event SET exchange = COALESCE(NULLIF(exchange, ''), 'bitmart'), market_type = COALESCE(NULLIF(market_type, ''), 'perpetual')");
        $this->addSql("ALTER TABLE trade_lifecycle_event ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE trade_lifecycle_event ALTER COLUMN exchange SET NOT NULL");

        $this->dropOldIndexes();
        $this->createScopedIndexes();
        $this->refreshKlineIngestFunction();
    }

    public function down(Schema $schema): void
    {
        $this->abortDownIfLegacyDuplicatesExist();

        $this->dropScopedIndexes();
        $this->createLegacyIndexes();

        $tables = [
            'contracts',
            'klines',
            'positions',
            'futures_order',
            'futures_plan_order',
            'futures_order_trade',
            'futures_transaction',
            'order_intent',
            'order_protection',
            'indicator_snapshots',
            'trade_zone_events',
            'mtf_state',
        ];

        foreach ($tables as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS market_type', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS exchange', $table));
        }

        $this->addSql('ALTER TABLE trade_lifecycle_event DROP COLUMN IF EXISTS market_type');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER COLUMN exchange DROP DEFAULT');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER COLUMN exchange DROP NOT NULL');

        $this->refreshLegacyKlineIngestFunction();
    }

    private function dropOldIndexes(): void
    {
        $names = [
            'ux_contracts_symbol',
            'idx_klines_symbol_tf',
            'ux_klines_symbol_tf_open',
            'idx_positions_symbol',
            'ux_positions_symbol_side',
            'ux_futures_order_order_id',
            'ux_futures_order_client',
            'idx_futures_order_symbol',
            'ux_futures_plan_order_order_id',
            'ux_futures_plan_order_client',
            'idx_futures_plan_order_symbol',
            'ux_futures_order_trade_trade_id',
            'idx_futures_order_trade_symbol',
            'idx_futures_tx_symbol',
            'ux_order_intent_client_order_id',
            'idx_order_intent_symbol',
            'idx_ind_snap_symbol_tf',
            'ux_ind_snap_symbol_tf_time',
            'idx_zone_symbol',
            'idx_mtf_state_symbol',
            'ux_mtf_state_symbol',
            'idx_trade_lifecycle_symbol_happened_at',
            'uniq_trade_lifecycle_event_dedup',
        ];

        foreach ($names as $name) {
            $this->addSql(sprintf('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE klines DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE positions DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE futures_order DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE futures_plan_order DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE futures_order_trade DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE futures_transaction DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE order_intent DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE indicator_snapshots DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE trade_zone_events DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE mtf_state DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('ALTER TABLE trade_lifecycle_event DROP CONSTRAINT IF EXISTS %s', $name));
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $name));
        }
    }

    private function createScopedIndexes(): void
    {
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_contracts_exchange_market_symbol ON contracts (exchange, market_type, symbol)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_klines_exchange_market_symbol_tf ON klines (exchange, market_type, symbol, timeframe)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_klines_exchange_market_symbol_tf_open ON klines (exchange, market_type, symbol, timeframe, open_time)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_positions_exchange_market_symbol ON positions (exchange, market_type, symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_positions_exchange_market_symbol_side ON positions (exchange, market_type, symbol, side)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_order_exchange_market_symbol ON futures_order (exchange, market_type, symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_order_exchange_market_order_id ON futures_order (exchange, market_type, order_id) WHERE order_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_order_exchange_market_client ON futures_order (exchange, market_type, client_order_id) WHERE client_order_id IS NOT NULL');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_plan_order_exchange_market_symbol ON futures_plan_order (exchange, market_type, symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_plan_order_exchange_market_order_id ON futures_plan_order (exchange, market_type, order_id) WHERE order_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_plan_order_exchange_market_client ON futures_plan_order (exchange, market_type, client_order_id) WHERE client_order_id IS NOT NULL');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_order_trade_exchange_market_symbol ON futures_order_trade (exchange, market_type, symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_order_trade_exchange_market_trade_id ON futures_order_trade (exchange, market_type, trade_id) WHERE trade_id IS NOT NULL');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_tx_exchange_market_symbol ON futures_transaction (exchange, market_type, symbol)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_intent_exchange_market_symbol ON order_intent (exchange, market_type, symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_order_intent_exchange_market_client_order_id ON order_intent (exchange, market_type, client_order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_protection_exchange_market ON order_protection (exchange, market_type)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ind_snap_exchange_market_symbol_tf ON indicator_snapshots (exchange, market_type, symbol, timeframe)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_ind_snap_exchange_market_symbol_tf_time ON indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_zone_exchange_market_symbol ON trade_zone_events (exchange, market_type, symbol)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mtf_state_exchange_market_symbol ON mtf_state (exchange, market_type, symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_mtf_state_exchange_market_symbol ON mtf_state (exchange, market_type, symbol)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lifecycle_exchange_market_symbol_happened_at ON trade_lifecycle_event (exchange, market_type, symbol, happened_at DESC)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_trade_lifecycle_event_dedup ON trade_lifecycle_event (exchange, market_type, account_id, run_id, symbol, event_type, order_id, happened_at)');
    }

    private function dropScopedIndexes(): void
    {
        $names = [
            'ux_contracts_exchange_market_symbol',
            'idx_klines_exchange_market_symbol_tf',
            'ux_klines_exchange_market_symbol_tf_open',
            'idx_positions_exchange_market_symbol',
            'ux_positions_exchange_market_symbol_side',
            'idx_futures_order_exchange_market_symbol',
            'ux_futures_order_exchange_market_order_id',
            'ux_futures_order_exchange_market_client',
            'idx_futures_plan_order_exchange_market_symbol',
            'ux_futures_plan_order_exchange_market_order_id',
            'ux_futures_plan_order_exchange_market_client',
            'idx_futures_order_trade_exchange_market_symbol',
            'ux_futures_order_trade_exchange_market_trade_id',
            'idx_futures_tx_exchange_market_symbol',
            'idx_order_intent_exchange_market_symbol',
            'ux_order_intent_exchange_market_client_order_id',
            'idx_order_protection_exchange_market',
            'idx_ind_snap_exchange_market_symbol_tf',
            'ux_ind_snap_exchange_market_symbol_tf_time',
            'idx_zone_exchange_market_symbol',
            'idx_mtf_state_exchange_market_symbol',
            'ux_mtf_state_exchange_market_symbol',
            'idx_trade_lifecycle_exchange_market_symbol_happened_at',
            'uniq_trade_lifecycle_event_dedup',
        ];

        foreach ($names as $name) {
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $name));
        }
    }

    private function createLegacyIndexes(): void
    {
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_contracts_symbol ON contracts (symbol)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_klines_symbol_tf ON klines (symbol, timeframe)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_klines_symbol_tf_open ON klines (symbol, timeframe, open_time)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_positions_symbol ON positions (symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_positions_symbol_side ON positions (symbol, side)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_order_symbol ON futures_order (symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_order_order_id ON futures_order (order_id) WHERE order_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_order_client ON futures_order (client_order_id) WHERE client_order_id IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_plan_order_symbol ON futures_plan_order (symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_plan_order_order_id ON futures_plan_order (order_id) WHERE order_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_plan_order_client ON futures_plan_order (client_order_id) WHERE client_order_id IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_order_trade_symbol ON futures_order_trade (symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_futures_order_trade_trade_id ON futures_order_trade (trade_id) WHERE trade_id IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_tx_symbol ON futures_transaction (symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_order_intent_client_order_id ON order_intent (client_order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_intent_symbol ON order_intent (symbol)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ind_snap_symbol_tf ON indicator_snapshots (symbol, timeframe)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_ind_snap_symbol_tf_time ON indicator_snapshots (symbol, timeframe, kline_time)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_zone_symbol ON trade_zone_events (symbol)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mtf_state_symbol ON mtf_state (symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_mtf_state_symbol ON mtf_state (symbol)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lifecycle_symbol_happened_at ON trade_lifecycle_event (symbol, happened_at DESC)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_trade_lifecycle_event_dedup ON trade_lifecycle_event (exchange, account_id, run_id, symbol, event_type, order_id, happened_at)');
    }

    private function abortDownIfLegacyDuplicatesExist(): void
    {
        $checks = [
            ['contracts', 'symbol', 'symbol IS NOT NULL'],
            ['klines', 'symbol, timeframe, open_time', 'symbol IS NOT NULL AND timeframe IS NOT NULL AND open_time IS NOT NULL'],
            ['positions', 'symbol, side', 'symbol IS NOT NULL AND side IS NOT NULL'],
            ['futures_order', 'order_id', 'order_id IS NOT NULL'],
            ['futures_order', 'client_order_id', 'client_order_id IS NOT NULL'],
            ['futures_plan_order', 'order_id', 'order_id IS NOT NULL'],
            ['futures_plan_order', 'client_order_id', 'client_order_id IS NOT NULL'],
            ['futures_order_trade', 'trade_id', 'trade_id IS NOT NULL'],
            ['order_intent', 'client_order_id', 'client_order_id IS NOT NULL'],
            ['indicator_snapshots', 'symbol, timeframe, kline_time', 'symbol IS NOT NULL AND timeframe IS NOT NULL AND kline_time IS NOT NULL'],
            ['mtf_state', 'symbol', 'symbol IS NOT NULL'],
            ['trade_lifecycle_event', 'exchange, account_id, run_id, symbol, event_type, order_id, happened_at', 'exchange IS NOT NULL AND run_id IS NOT NULL AND symbol IS NOT NULL AND event_type IS NOT NULL AND happened_at IS NOT NULL'],
        ];

        foreach ($checks as [$table, $legacyKey, $where]) {
            $sql = sprintf(
                "SELECT COUNT(*) FROM (SELECT %s FROM %s WHERE %s GROUP BY %s HAVING COUNT(DISTINCT exchange || ':' || market_type) > 1) duplicates",
                $legacyKey,
                $table,
                $where,
                $legacyKey,
            );

            if ((int) $this->connection->fetchOne($sql) > 0) {
                throw new IrreversibleMigration(sprintf(
                    'Cannot rollback exchange/market scoping: table "%s" contains rows that would collide on the legacy key (%s).',
                    $table,
                    $legacyKey,
                ));
            }
        }
    }

    private function refreshKlineIngestFunction(): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION ingest_klines_json(p_payload jsonb)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
INSERT INTO klines (
    id, exchange, market_type, symbol, timeframe, open_time,
    open_price, high_price, low_price, close_price, volume,
    source, inserted_at, updated_at
)
SELECT
    nextval('klines_id_seq'),
    COALESCE(NULLIF(t.exchange, ''), 'bitmart'),
    COALESCE(NULLIF(t.market_type, ''), 'perpetual'),
    t.symbol,
    t.timeframe,
    (t.open_time)::timestamptz,
    (t.open_price)::numeric,
    (t.high_price)::numeric,
    (t.low_price)::numeric,
    (t.close_price)::numeric,
    (t.volume)::numeric,
    COALESCE(t.source, 'REST'),
    now(),
    now()
FROM jsonb_to_recordset(p_payload) AS t(
    exchange text,
    market_type text,
    symbol text,
    timeframe text,
    open_time text,
    open_price text,
    high_price text,
    low_price text,
    close_price text,
    volume text,
    source text
)
ON CONFLICT (exchange, market_type, symbol, timeframe, open_time) DO UPDATE SET
    open_price = EXCLUDED.open_price,
    high_price = EXCLUDED.high_price,
    low_price = EXCLUDED.low_price,
    close_price = EXCLUDED.close_price,
    volume = EXCLUDED.volume,
    source = EXCLUDED.source,
    updated_at = EXCLUDED.updated_at;
END;
$$;
SQL);
    }

    private function refreshLegacyKlineIngestFunction(): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION ingest_klines_json(p_payload jsonb)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
INSERT INTO klines (
    id, symbol, timeframe, open_time,
    open_price, high_price, low_price, close_price, volume,
    source, inserted_at, updated_at
)
SELECT
    nextval('klines_id_seq'),
    t.symbol,
    t.timeframe,
    (t.open_time)::timestamptz,
    (t.open_price)::numeric,
    (t.high_price)::numeric,
    (t.low_price)::numeric,
    (t.close_price)::numeric,
    (t.volume)::numeric,
    COALESCE(t.source, 'REST'),
    now(),
    now()
FROM jsonb_to_recordset(p_payload) AS t(
    symbol text,
    timeframe text,
    open_time text,
    open_price text,
    high_price text,
    low_price text,
    close_price text,
    volume text,
    source text
)
ON CONFLICT (symbol, timeframe, open_time) DO NOTHING;
END;
$$;
SQL);
    }
}
