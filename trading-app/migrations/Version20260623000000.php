<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent internal trade lineage mapping for exact trade lifecycle correlation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS trade_lineage (
    id BIGSERIAL NOT NULL,
    order_intent_id BIGINT DEFAULT NULL,
    internal_trade_id VARCHAR(96) NOT NULL,
    client_order_id VARCHAR(96) NOT NULL,
    exchange_order_id VARCHAR(96) DEFAULT NULL,
    position_id VARCHAR(96) DEFAULT NULL,
    run_id VARCHAR(96) DEFAULT NULL,
    correlation_run_id VARCHAR(96) DEFAULT NULL,
    orchestration_run_id VARCHAR(96) DEFAULT NULL,
    orchestration_set_id VARCHAR(96) DEFAULT NULL,
    orchestration_dashboard_id VARCHAR(96) DEFAULT NULL,
    exchange VARCHAR(32) DEFAULT 'bitmart' NOT NULL,
    market_type VARCHAR(32) DEFAULT 'perpetual' NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    side VARCHAR(16) DEFAULT NULL,
    profile VARCHAR(80) DEFAULT NULL,
    origin VARCHAR(24) DEFAULT 'runtime' NOT NULL,
    created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    PRIMARY KEY(id)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_trade_lineage_internal_trade_id ON trade_lineage (internal_trade_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_trade_lineage_order_intent ON trade_lineage (order_intent_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_trade_lineage_venue_client_order ON trade_lineage (exchange, market_type, client_order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lineage_venue_exchange_order ON trade_lineage (exchange, market_type, exchange_order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lineage_venue_position ON trade_lineage (exchange, market_type, position_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lineage_run_set ON trade_lineage (run_id, orchestration_set_id)');
        $this->addSql('ALTER TABLE trade_lineage ADD CONSTRAINT FK_TRADE_LINEAGE_ORDER_INTENT FOREIGN KEY (order_intent_id) REFERENCES order_intent (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS internal_trade_id VARCHAR(96) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lifecycle_internal_trade_id ON trade_lifecycle_event (internal_trade_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_trade_lifecycle_internal_trade_id');
        $this->addSql('ALTER TABLE trade_lifecycle_event DROP COLUMN IF EXISTS internal_trade_id');
        $this->addSql('DROP TABLE IF EXISTS trade_lineage');
    }
}
