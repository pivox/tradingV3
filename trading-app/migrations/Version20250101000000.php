<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MTF trading system tables';
    }

    public function up(Schema $schema): void
    {
        // Create ENUM types
        $this->addSql("CREATE TYPE timeframe AS ENUM ('4h','1h','15m','5m','1m')");
        $this->addSql("CREATE TYPE signal_side AS ENUM ('LONG','SHORT','NONE')");

        // Create klines table
        $this->addSql('CREATE TABLE klines (
            id BIGSERIAL PRIMARY KEY,
            symbol TEXT NOT NULL,
            timeframe timeframe NOT NULL,
            open_time TIMESTAMPTZ NOT NULL,
            open_price NUMERIC(24,12) NOT NULL,
            high_price NUMERIC(24,12) NOT NULL,
            low_price NUMERIC(24,12) NOT NULL,
            close_price NUMERIC(24,12) NOT NULL,
            volume NUMERIC(28,12),
            source TEXT NOT NULL DEFAULT \'REST\',
            inserted_at TIMESTAMPTZ DEFAULT now(),
            updated_at TIMESTAMPTZ DEFAULT now()
        )');

        $this->addSql('CREATE UNIQUE INDEX ux_klines_symbol_tf_open ON klines(symbol,timeframe,open_time)');
        $this->addSql('CREATE INDEX idx_klines_symbol_tf ON klines(symbol,timeframe)');
        $this->addSql('CREATE INDEX idx_klines_open_time ON klines(open_time)');

        // Create indicator_snapshots table
        $this->addSql('CREATE TABLE indicator_snapshots (
            id BIGSERIAL PRIMARY KEY,
            symbol TEXT NOT NULL,
            timeframe timeframe NOT NULL,
            kline_time TIMESTAMPTZ NOT NULL,
            values JSONB NOT NULL,
            inserted_at TIMESTAMPTZ DEFAULT now(),
            updated_at TIMESTAMPTZ DEFAULT now()
        )');

        $this->addSql('CREATE UNIQUE INDEX ux_ind_snap_symbol_tf_time ON indicator_snapshots(symbol,timeframe,kline_time)');
        $this->addSql('CREATE INDEX idx_ind_snap_symbol_tf ON indicator_snapshots(symbol,timeframe)');
        $this->addSql('CREATE INDEX idx_ind_snap_kline_time ON indicator_snapshots(kline_time)');

        // Create signals table
        $this->addSql('CREATE TABLE signals (
            id BIGSERIAL PRIMARY KEY,
            symbol TEXT NOT NULL,
            timeframe timeframe NOT NULL,
            kline_time TIMESTAMPTZ NOT NULL,
            side signal_side NOT NULL,
            score DOUBLE PRECISION,
            meta JSONB DEFAULT \'{}\'::jsonb,
            inserted_at TIMESTAMPTZ DEFAULT now(),
            updated_at TIMESTAMPTZ DEFAULT now()
        )');

        $this->addSql('CREATE UNIQUE INDEX ux_signals_symbol_tf_time ON signals(symbol,timeframe,kline_time)');
        $this->addSql('CREATE INDEX idx_signals_symbol_tf ON signals(symbol,timeframe)');
        $this->addSql('CREATE INDEX idx_signals_kline_time ON signals(kline_time)');
        $this->addSql('CREATE INDEX idx_signals_side ON signals(side)');

        // Create validation_cache table
        $this->addSql('CREATE TABLE validation_cache (
            cache_key TEXT PRIMARY KEY,
            payload JSONB NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            updated_at TIMESTAMPTZ DEFAULT now()
        )');

        $this->addSql('CREATE INDEX idx_validation_cache_expires ON validation_cache(expires_at)');

        // Create mtf_audit table
        $this->addSql('CREATE TABLE mtf_audit (
            id BIGSERIAL PRIMARY KEY,
            symbol TEXT NOT NULL,
            run_id UUID NOT NULL,
            step TEXT NOT NULL,
            timeframe timeframe,
            cause TEXT,
            details JSONB DEFAULT \'{}\'::jsonb,
            created_at TIMESTAMPTZ DEFAULT now()
        )');

        $this->addSql('CREATE INDEX idx_mtf_audit_symbol ON mtf_audit(symbol)');
        $this->addSql('CREATE INDEX idx_mtf_audit_run_id ON mtf_audit(run_id)');
        $this->addSql('CREATE INDEX idx_mtf_audit_created_at ON mtf_audit(created_at)');

        // Create order_plan table
        $this->addSql('CREATE TABLE order_plan (
            id BIGSERIAL PRIMARY KEY,
            symbol TEXT NOT NULL,
            plan_time TIMESTAMPTZ DEFAULT now(),
            side signal_side NOT NULL,
            risk_json JSONB NOT NULL,
            context_json JSONB NOT NULL,
            exec_json JSONB NOT NULL,
            status TEXT DEFAULT \'PLANNED\'
        )');

        $this->addSql('CREATE INDEX idx_order_plan_symbol ON order_plan(symbol)');
        $this->addSql('CREATE INDEX idx_order_plan_status ON order_plan(status)');
        $this->addSql('CREATE INDEX idx_order_plan_plan_time ON order_plan(plan_time)');

        // Create contracts table
        $this->addSql('CREATE TABLE contracts (
            id BIGSERIAL PRIMARY KEY,
            symbol TEXT NOT NULL,
            name TEXT,
            product_type INTEGER,
            open_timestamp BIGINT,
            expire_timestamp BIGINT,
            settle_timestamp BIGINT,
            base_currency TEXT,
            quote_currency TEXT,
            last_price NUMERIC(24,12),
            volume_24h NUMERIC(28,12),
            turnover_24h NUMERIC(28,12),
            status TEXT,
            min_size NUMERIC(24,12),
            max_size NUMERIC(24,12),
            tick_size NUMERIC(24,12),
            multiplier NUMERIC(24,12),
            inserted_at TIMESTAMPTZ DEFAULT now(),
            updated_at TIMESTAMPTZ DEFAULT now()
        )');

        $this->addSql('CREATE UNIQUE INDEX ux_contracts_symbol ON contracts(symbol)');
        $this->addSql('CREATE INDEX idx_contracts_quote_currency ON contracts(quote_currency)');
        $this->addSql('CREATE INDEX idx_contracts_status ON contracts(status)');
        $this->addSql('CREATE INDEX idx_contracts_volume_24h ON contracts(volume_24h)');
    }

    public function down(Schema $schema): void
    {
        // Drop tables
        $this->addSql('DROP TABLE IF EXISTS contracts');
        $this->addSql('DROP TABLE IF EXISTS order_plan');
        $this->addSql('DROP TABLE IF EXISTS mtf_audit');
        $this->addSql('DROP TABLE IF EXISTS validation_cache');
        $this->addSql('DROP TABLE IF EXISTS signals');
        $this->addSql('DROP TABLE IF EXISTS indicator_snapshots');
        $this->addSql('DROP TABLE IF EXISTS klines');

        // Drop ENUM types
        $this->addSql('DROP TYPE IF EXISTS signal_side');
        $this->addSql('DROP TYPE IF EXISTS timeframe');
    }
}
