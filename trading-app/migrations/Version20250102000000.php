<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial migration: Creates all application tables
 * This migration consolidates all previous migrations into a single initial schema
 * Generated on 2025-01-02
 */
final class Version20250102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial database schema - Creates all application tables';
    }

    public function up(Schema $schema): void
    {
        // ============================================================
        // Table: blacklisted_contract
        // ============================================================
        $this->addSql('
            CREATE TABLE blacklisted_contract (
                id SERIAL PRIMARY KEY,
                symbol VARCHAR(50) DEFAULT NULL,
                reason VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            )
        ');
        $this->addSql("COMMENT ON COLUMN blacklisted_contract.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN blacklisted_contract.expires_at IS '(DC2Type:datetime_immutable)'");

        // ============================================================
        // Table: contract_cooldown
        // ============================================================
        $this->addSql('
            CREATE TABLE contract_cooldown (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                active_until TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                reason VARCHAR(120) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_contract_cooldown_symbol ON contract_cooldown (symbol)');
        $this->addSql('CREATE INDEX idx_contract_cooldown_active_until ON contract_cooldown (active_until)');
        $this->addSql("COMMENT ON COLUMN contract_cooldown.active_until IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN contract_cooldown.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN contract_cooldown.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: contracts
        // ============================================================
        $this->addSql('
            CREATE TABLE contracts (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                name VARCHAR(100),
                product_type INTEGER,
                open_timestamp BIGINT,
                expire_timestamp BIGINT,
                settle_timestamp BIGINT,
                base_currency VARCHAR(20),
                quote_currency VARCHAR(20),
                last_price NUMERIC(24, 12),
                volume_24h NUMERIC(28, 12),
                turnover_24h NUMERIC(28, 12),
                status VARCHAR(20),
                min_size NUMERIC(24, 12),
                max_size NUMERIC(24, 12),
                tick_size NUMERIC(24, 12),
                multiplier NUMERIC(24, 12),
                inserted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                index_price NUMERIC(24, 12) DEFAULT NULL,
                index_name VARCHAR(50) DEFAULT NULL,
                contract_size NUMERIC(24, 12) DEFAULT NULL,
                min_leverage NUMERIC(8, 2) DEFAULT NULL,
                max_leverage NUMERIC(8, 2) DEFAULT NULL,
                price_precision VARCHAR(20) DEFAULT NULL,
                vol_precision VARCHAR(20) DEFAULT NULL,
                max_volume NUMERIC(28, 12) DEFAULT NULL,
                market_max_volume NUMERIC(28, 12) DEFAULT NULL,
                min_volume NUMERIC(28, 12) DEFAULT NULL,
                funding_rate NUMERIC(18, 8) DEFAULT NULL,
                expected_funding_rate NUMERIC(18, 8) DEFAULT NULL,
                open_interest NUMERIC(28, 12) DEFAULT NULL,
                open_interest_value NUMERIC(28, 12) DEFAULT NULL,
                high_24h NUMERIC(24, 12) DEFAULT NULL,
                low_24h NUMERIC(24, 12) DEFAULT NULL,
                change_24h NUMERIC(18, 8) DEFAULT NULL,
                funding_interval_hours INTEGER,
                delist_time BIGINT
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX ux_contracts_symbol ON contracts (symbol)');
        $this->addSql("COMMENT ON COLUMN contracts.inserted_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN contracts.updated_at IS '(DC2Type:datetime_immutable)'");

        // ============================================================
        // Table: order_plan (must be created before exchange_order due to FK)
        // ============================================================
        $this->addSql('
            CREATE TABLE order_plan (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                plan_time TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                side VARCHAR(10) NOT NULL,
                risk_json JSONB NOT NULL,
                context_json JSONB NOT NULL,
                exec_json JSONB NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'PLANNED\'
            )
        ');
        $this->addSql('CREATE INDEX idx_order_plan_symbol ON order_plan (symbol)');
        $this->addSql('CREATE INDEX idx_order_plan_status ON order_plan (status)');
        $this->addSql('CREATE INDEX idx_order_plan_plan_time ON order_plan (plan_time)');
        $this->addSql("COMMENT ON COLUMN order_plan.plan_time IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: positions (must be created before exchange_order due to FK)
        // ============================================================
        $this->addSql('
            CREATE TABLE positions (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                side VARCHAR(10) NOT NULL,
                size NUMERIC(28, 12) DEFAULT NULL,
                avg_entry_price NUMERIC(24, 12) DEFAULT NULL,
                leverage INTEGER,
                unrealized_pnl NUMERIC(28, 12) DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'OPEN\',
                payload JSONB NOT NULL,
                inserted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->addSql('CREATE INDEX idx_positions_symbol ON positions (symbol)');
        $this->addSql('CREATE UNIQUE INDEX ux_positions_symbol_side ON positions (symbol, side)');
        $this->addSql("COMMENT ON COLUMN positions.inserted_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN positions.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: exchange_order (depends on order_plan and positions)
        // ============================================================
        $this->addSql('
            CREATE TABLE exchange_order (
                id BIGSERIAL PRIMARY KEY,
                order_plan_id BIGINT DEFAULT NULL,
                position_id BIGINT DEFAULT NULL,
                order_id VARCHAR(80) DEFAULT NULL,
                client_order_id VARCHAR(80) NOT NULL,
                parent_client_order_id VARCHAR(80) DEFAULT NULL,
                symbol VARCHAR(50) NOT NULL,
                kind VARCHAR(20) NOT NULL,
                status VARCHAR(24) NOT NULL,
                type VARCHAR(24) NOT NULL,
                side VARCHAR(24) NOT NULL,
                price NUMERIC(24, 12) DEFAULT NULL,
                size NUMERIC(28, 12) DEFAULT NULL,
                leverage INTEGER,
                submitted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                exchange_payload JSONB NOT NULL,
                metadata JSONB NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_eb1edfd01ee3abd0 FOREIGN KEY (order_plan_id) REFERENCES order_plan(id) ON DELETE SET NULL,
                CONSTRAINT fk_eb1edfd0dd842e46 FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_eb1edfd01ee3abd0 ON exchange_order (order_plan_id)');
        $this->addSql('CREATE INDEX idx_eb1edfd0dd842e46 ON exchange_order (position_id)');
        $this->addSql('CREATE INDEX idx_exchange_order_symbol ON exchange_order (symbol)');
        $this->addSql('CREATE INDEX idx_exchange_order_kind ON exchange_order (kind)');
        $this->addSql('CREATE INDEX idx_exchange_order_status ON exchange_order (status)');
        $this->addSql('CREATE UNIQUE INDEX ux_exchange_order_client ON exchange_order (client_order_id)');
        $this->addSql("COMMENT ON COLUMN exchange_order.submitted_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN exchange_order.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN exchange_order.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: hot_kline
        // ============================================================
        $this->addSql('
            CREATE TABLE hot_kline (
                symbol VARCHAR(50) NOT NULL,
                timeframe VARCHAR(10) NOT NULL,
                open_time TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                ohlc JSON NOT NULL,
                is_closed BOOLEAN NOT NULL DEFAULT false,
                last_update TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (symbol, timeframe, open_time)
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX uniq_hot_kline_pk ON hot_kline (symbol, timeframe, open_time)');
        $this->addSql('CREATE INDEX idx_hot_kline_symbol_tf ON hot_kline (symbol, timeframe)');
        $this->addSql('CREATE INDEX idx_hot_kline_last_update ON hot_kline (last_update)');
        $this->addSql("COMMENT ON COLUMN hot_kline.open_time IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN hot_kline.last_update IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: indicator_snapshots
        // ============================================================
        $this->addSql('
            CREATE TABLE indicator_snapshots (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                timeframe VARCHAR(10) NOT NULL,
                kline_time TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                "values" JSONB NOT NULL,
                inserted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                source VARCHAR(10) NOT NULL DEFAULT \'PHP\'
            )
        ');
        $this->addSql('CREATE INDEX idx_ind_snap_symbol_tf ON indicator_snapshots (symbol, timeframe)');
        $this->addSql('CREATE INDEX idx_ind_snap_kline_time ON indicator_snapshots (kline_time)');
        $this->addSql('CREATE UNIQUE INDEX ux_ind_snap_symbol_tf_time ON indicator_snapshots (symbol, timeframe, kline_time)');
        $this->addSql("COMMENT ON COLUMN indicator_snapshots.kline_time IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN indicator_snapshots.inserted_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN indicator_snapshots.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: klines
        // ============================================================
        $this->addSql('
            CREATE TABLE klines (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                timeframe VARCHAR(10) NOT NULL,
                open_time TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                open_price NUMERIC(24, 12) NOT NULL,
                high_price NUMERIC(24, 12) NOT NULL,
                low_price NUMERIC(24, 12) NOT NULL,
                close_price NUMERIC(24, 12) NOT NULL,
                volume NUMERIC(28, 12),
                source VARCHAR(20) NOT NULL DEFAULT \'REST\',
                inserted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now()
            )
        ');
        $this->addSql('CREATE INDEX idx_klines_symbol_tf ON klines (symbol, timeframe)');
        $this->addSql('CREATE INDEX idx_klines_open_time ON klines (open_time)');
        $this->addSql('CREATE UNIQUE INDEX ux_klines_symbol_tf_open ON klines (symbol, timeframe, open_time)');
        $this->addSql("COMMENT ON COLUMN klines.open_time IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN klines.inserted_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN klines.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: lock_keys
        // ============================================================
        $this->addSql('
            CREATE TABLE lock_keys (
                key_id VARCHAR(64) PRIMARY KEY,
                key_token VARCHAR(44) NOT NULL,
                key_expiration INTEGER NOT NULL
            )
        ');

        // ============================================================
        // Table: mtf_audit
        // ============================================================
        $this->addSql('
            CREATE TABLE mtf_audit (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                run_id UUID NOT NULL,
                step VARCHAR(100) NOT NULL,
                timeframe VARCHAR(10),
                cause TEXT,
                details JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                candle_close_ts TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                severity SMALLINT NOT NULL DEFAULT 0
            )
        ');
        $this->addSql('CREATE INDEX idx_mtf_audit_symbol ON mtf_audit (symbol)');
        $this->addSql('CREATE INDEX idx_mtf_audit_run_id ON mtf_audit (run_id)');
        $this->addSql('CREATE INDEX idx_mtf_audit_created_at ON mtf_audit (created_at)');
        $this->addSql("COMMENT ON COLUMN mtf_audit.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN mtf_audit.candle_close_ts IS 'Heure de clôture de la bougie concernée(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN mtf_audit.severity IS 'Niveau de sévérité 0..n'");

        // ============================================================
        // Table: mtf_lock
        // ============================================================
        $this->addSql('
            CREATE TABLE mtf_lock (
                lock_key VARCHAR(50) PRIMARY KEY,
                process_id VARCHAR(100) NOT NULL,
                acquired_at TIMESTAMPTZ NOT NULL,
                expires_at TIMESTAMPTZ,
                metadata TEXT
            )
        ');

        // ============================================================
        // Table: mtf_state
        // ============================================================
        $this->addSql('
            CREATE TABLE mtf_state (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                k4h_time TIMESTAMPTZ,
                k1h_time TIMESTAMPTZ,
                k15m_time TIMESTAMPTZ,
                k5m_time TIMESTAMPTZ,
                k1m_time TIMESTAMPTZ,
                sides JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX ux_mtf_state_symbol ON mtf_state (symbol)');

        // ============================================================
        // Table: mtf_switch
        // ============================================================
        $this->addSql('
            CREATE TABLE mtf_switch (
                id BIGSERIAL PRIMARY KEY,
                switch_key VARCHAR(100) NOT NULL,
                is_on BOOLEAN NOT NULL DEFAULT true,
                description TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                expires_at TIMESTAMPTZ DEFAULT NULL
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX ux_mtf_switch_key ON mtf_switch (switch_key)');

        // ============================================================
        // Table: order_lifecycle
        // ============================================================
        $this->addSql('
            CREATE TABLE order_lifecycle (
                id BIGSERIAL PRIMARY KEY,
                order_id VARCHAR(80) NOT NULL,
                client_order_id VARCHAR(80) DEFAULT NULL,
                symbol VARCHAR(50) NOT NULL,
                side VARCHAR(16) DEFAULT NULL,
                type VARCHAR(24) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                last_action VARCHAR(32) DEFAULT NULL,
                last_event_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                payload JSONB NOT NULL,
                kind VARCHAR(24) NOT NULL DEFAULT \'ENTRY\'
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_lifecycle_order_id ON order_lifecycle (order_id)');
        $this->addSql('CREATE INDEX idx_order_lifecycle_symbol ON order_lifecycle (symbol)');
        $this->addSql("COMMENT ON COLUMN order_lifecycle.last_event_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN order_lifecycle.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN order_lifecycle.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: signals
        // ============================================================
        $this->addSql('
            CREATE TABLE signals (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                timeframe VARCHAR(10) NOT NULL,
                kline_time TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                side VARCHAR(10) NOT NULL,
                score DOUBLE PRECISION,
                meta JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                inserted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now()
            )
        ');
        $this->addSql('CREATE INDEX idx_signals_symbol_tf ON signals (symbol, timeframe)');
        $this->addSql('CREATE INDEX idx_signals_kline_time ON signals (kline_time)');
        $this->addSql('CREATE INDEX idx_signals_side ON signals (side)');
        $this->addSql('CREATE UNIQUE INDEX ux_signals_symbol_tf_time ON signals (symbol, timeframe, kline_time)');
        $this->addSql("COMMENT ON COLUMN signals.kline_time IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN signals.inserted_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN signals.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: validation_cache
        // ============================================================
        $this->addSql('
            CREATE TABLE validation_cache (
                cache_key VARCHAR(255) PRIMARY KEY,
                payload JSONB NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now()
            )
        ');
        $this->addSql('CREATE INDEX idx_validation_cache_expires ON validation_cache (expires_at)');
        $this->addSql("COMMENT ON COLUMN validation_cache.expires_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN validation_cache.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: messenger_messages_failed (Symfony Messenger)
        // ============================================================
        $this->addSql('
            CREATE TABLE messenger_messages_failed (
                id BIGSERIAL PRIMARY KEY,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_804a86d9fb7336f0 ON messenger_messages_failed (queue_name)');
        $this->addSql('CREATE INDEX idx_804a86d9e3bd61ce ON messenger_messages_failed (available_at)');
        $this->addSql('CREATE INDEX idx_804a86d916ba31db ON messenger_messages_failed (delivered_at)');
        $this->addSql("COMMENT ON COLUMN messenger_messages_failed.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN messenger_messages_failed.available_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN messenger_messages_failed.delivered_at IS '(DC2Type:datetime_immutable)'");
        
        // Create trigger function for messenger notifications
        $this->addSql('
            CREATE OR REPLACE FUNCTION notify_messenger_messages_failed() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM pg_notify(\'messenger_messages_failed\', NEW.queue_name::text);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ');
        
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages_failed');
        $this->addSql('
            CREATE TRIGGER notify_trigger 
            AFTER INSERT OR UPDATE ON messenger_messages_failed 
            FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages_failed()
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop all tables in reverse order to respect foreign key constraints
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages_failed');
        $this->addSql('DROP FUNCTION IF EXISTS notify_messenger_messages_failed() CASCADE');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages_failed CASCADE');
        $this->addSql('DROP TABLE IF EXISTS validation_cache CASCADE');
        $this->addSql('DROP TABLE IF EXISTS signals CASCADE');
        $this->addSql('DROP TABLE IF EXISTS order_lifecycle CASCADE');
        $this->addSql('DROP TABLE IF EXISTS mtf_switch CASCADE');
        $this->addSql('DROP TABLE IF EXISTS mtf_state CASCADE');
        $this->addSql('DROP TABLE IF EXISTS mtf_lock CASCADE');
        $this->addSql('DROP TABLE IF EXISTS mtf_audit CASCADE');
        $this->addSql('DROP TABLE IF EXISTS lock_keys CASCADE');
        $this->addSql('DROP TABLE IF EXISTS klines CASCADE');
        $this->addSql('DROP TABLE IF EXISTS indicator_snapshots CASCADE');
        $this->addSql('DROP TABLE IF EXISTS hot_kline CASCADE');
        $this->addSql('DROP TABLE IF EXISTS exchange_order CASCADE');
        $this->addSql('DROP TABLE IF EXISTS positions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS order_plan CASCADE');
        $this->addSql('DROP TABLE IF EXISTS contracts CASCADE');
        $this->addSql('DROP TABLE IF EXISTS contract_cooldown CASCADE');
        $this->addSql('DROP TABLE IF EXISTS blacklisted_contract CASCADE');
    }
}

