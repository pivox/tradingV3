<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251113000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trade_lifecycle_event table to persist lifecycle events (skip/submit/expired/opened)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trade_lifecycle_event (
            id BIGSERIAL PRIMARY KEY,
            run_id VARCHAR(64) DEFAULT NULL,
            symbol VARCHAR(50) NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            order_id VARCHAR(64) DEFAULT NULL,
            client_order_id VARCHAR(64) DEFAULT NULL,
            position_id VARCHAR(64) DEFAULT NULL,
            side VARCHAR(16) DEFAULT NULL,
            qty NUMERIC(30, 15) DEFAULT NULL,
            price NUMERIC(30, 15) DEFAULT NULL,
            timeframe VARCHAR(8) DEFAULT NULL,
            config_profile VARCHAR(64) DEFAULT NULL,
            config_version VARCHAR(16) DEFAULT NULL,
            plan_id VARCHAR(64) DEFAULT NULL,
            exchange VARCHAR(32) DEFAULT NULL,
            account_id VARCHAR(64) DEFAULT NULL,
            reason_code VARCHAR(64) DEFAULT NULL,
            extra JSONB DEFAULT NULL,
            happened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW()
        )');
        $this->addSql('CREATE INDEX idx_trade_lifecycle_symbol_happened_at ON trade_lifecycle_event (symbol, happened_at DESC)');
        $this->addSql('CREATE INDEX idx_trade_lifecycle_event_type_happened_at ON trade_lifecycle_event (event_type, happened_at DESC)');
        $this->addSql('CREATE INDEX idx_trade_lifecycle_exchange_account ON trade_lifecycle_event (exchange, account_id, happened_at DESC)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trade_lifecycle_event_dedup ON trade_lifecycle_event (exchange, account_id, run_id, symbol, event_type, order_id, happened_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS trade_lifecycle_event');
    }
}
