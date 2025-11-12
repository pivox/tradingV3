<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251112000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MTF run persistence tables: mtf_run, mtf_run_symbol, mtf_run_metric';
    }

    public function up(Schema $schema): void
    {
        // mtf_run
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS mtf_run (
    run_id UUID NOT NULL,
    status VARCHAR(64) NOT NULL,
    execution_time_seconds DOUBLE PRECISION NOT NULL,
    symbols_requested INT NOT NULL,
    symbols_processed INT NOT NULL,
    symbols_successful INT NOT NULL,
    symbols_failed INT NOT NULL,
    symbols_skipped INT NOT NULL,
    success_rate NUMERIC(6,2) NOT NULL,
    dry_run BOOLEAN NOT NULL,
    force_run BOOLEAN NOT NULL,
    current_tf VARCHAR(8) DEFAULT NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ DEFAULT NULL,
    workers INT DEFAULT NULL,
    user_id VARCHAR(128) DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    options_json JSONB DEFAULT NULL,
    PRIMARY KEY(run_id)
)
SQL);
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_started_at ON mtf_run (started_at)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_status ON mtf_run (status)");

        // mtf_run_symbol
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS mtf_run_symbol (
    id BIGSERIAL NOT NULL,
    run_id UUID NOT NULL REFERENCES mtf_run(run_id) ON DELETE CASCADE,
    symbol VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL,
    execution_tf VARCHAR(8) DEFAULT NULL,
    blocking_tf VARCHAR(8) DEFAULT NULL,
    signal_side VARCHAR(8) DEFAULT NULL,
    current_price NUMERIC(18,8) DEFAULT NULL,
    atr NUMERIC(18,8) DEFAULT NULL,
    validation_mode_used TEXT DEFAULT NULL,
    trade_entry_mode_used TEXT DEFAULT NULL,
    trading_decision JSONB DEFAULT NULL,
    error JSONB DEFAULT NULL,
    context JSONB DEFAULT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(id),
    CONSTRAINT uniq_mtf_run_symbol UNIQUE(run_id, symbol)
)
SQL);
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_symbol_run_id ON mtf_run_symbol (run_id)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_symbol_symbol ON mtf_run_symbol (symbol)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_symbol_status ON mtf_run_symbol (status)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_symbol_blocking_tf ON mtf_run_symbol (blocking_tf)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_symbol_exec_tf ON mtf_run_symbol (execution_tf)");

        // mtf_run_metric
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS mtf_run_metric (
    id BIGSERIAL NOT NULL,
    run_id UUID NOT NULL REFERENCES mtf_run(run_id) ON DELETE CASCADE,
    category TEXT NOT NULL,
    operation TEXT NOT NULL,
    symbol VARCHAR(32) DEFAULT NULL,
    timeframe VARCHAR(8) DEFAULT NULL,
    count INT NOT NULL,
    duration DOUBLE PRECISION NOT NULL,
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(id)
)
SQL);
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_metric_run_id ON mtf_run_metric (run_id)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_metric_cat_op ON mtf_run_metric (category, operation)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_metric_symbol ON mtf_run_metric (symbol)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_mtf_run_metric_timeframe ON mtf_run_metric (timeframe)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mtf_run_metric');
        $this->addSql('DROP TABLE IF EXISTS mtf_run_symbol');
        $this->addSql('DROP TABLE IF EXISTS mtf_run');
    }
}
