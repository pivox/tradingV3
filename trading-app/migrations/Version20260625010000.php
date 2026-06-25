<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DATA-002: create exchange-neutral fill and cost ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE fill_cost_ledger (
    id BIGSERIAL NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    payload_hash VARCHAR(64) NOT NULL,
    internal_trade_id VARCHAR(96) DEFAULT NULL,
    internal_position_id VARCHAR(96) DEFAULT NULL,
    position_id VARCHAR(96) DEFAULT NULL,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    side VARCHAR(16) DEFAULT NULL,
    fill_id VARCHAR(128) NOT NULL,
    exchange_fill_id VARCHAR(128) DEFAULT NULL,
    exchange_order_id VARCHAR(96) DEFAULT NULL,
    client_order_id VARCHAR(96) DEFAULT NULL,
    order_intent_id BIGINT DEFAULT NULL,
    fill_role VARCHAR(24) NOT NULL,
    liquidity_role VARCHAR(24) NOT NULL,
    price NUMERIC(30, 12) DEFAULT NULL,
    quantity NUMERIC(30, 12) DEFAULT NULL,
    notional NUMERIC(30, 12) DEFAULT NULL,
    fee_amount NUMERIC(30, 12) DEFAULT NULL,
    fee_currency VARCHAR(20) DEFAULT NULL,
    fee_usdt NUMERIC(30, 12) DEFAULT NULL,
    funding_usdt NUMERIC(30, 12) DEFAULT NULL,
    spread_cost_usdt NUMERIC(30, 12) DEFAULT NULL,
    slippage_cost_usdt NUMERIC(30, 12) DEFAULT NULL,
    borrow_cost_usdt NUMERIC(30, 12) DEFAULT NULL,
    liquidation_fee_usdt NUMERIC(30, 12) DEFAULT NULL,
    occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    source VARCHAR(64) NOT NULL,
    source_version VARCHAR(64) NOT NULL,
    quality_flags JSONB NOT NULL,
    raw_reference JSONB NOT NULL,
    created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    PRIMARY KEY(id)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX ux_fill_cost_ledger_idempotency ON fill_cost_ledger (idempotency_key)');
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_internal_trade ON fill_cost_ledger (internal_trade_id, occurred_at, id)');
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_internal_position ON fill_cost_ledger (internal_position_id)');
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_position ON fill_cost_ledger (exchange, market_type, position_id)');
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_venue_fill ON fill_cost_ledger (exchange, market_type, exchange_fill_id)');
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_venue_order ON fill_cost_ledger (exchange, market_type, exchange_order_id)');
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_client_order ON fill_cost_ledger (exchange, market_type, client_order_id)');
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_order_intent ON fill_cost_ledger (order_intent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS fill_cost_ledger');
    }
}
