<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Creates FuturesOrder, FuturesPlanOrder, FuturesOrderTrade tables
 */
final class Version20250115000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create FuturesOrder, FuturesPlanOrder, FuturesOrderTrade tables for BitMart Futures V2 API synchronization';
    }

    public function up(Schema $schema): void
    {
        // ============================================================
        // Table: futures_order
        // ============================================================
        $this->addSql('
            CREATE TABLE futures_order (
                id BIGSERIAL PRIMARY KEY,
                order_id VARCHAR(80) DEFAULT NULL,
                client_order_id VARCHAR(80) DEFAULT NULL,
                symbol VARCHAR(50) NOT NULL,
                side INTEGER DEFAULT NULL,
                type VARCHAR(20) DEFAULT NULL,
                status VARCHAR(30) DEFAULT NULL,
                price NUMERIC(24, 12) DEFAULT NULL,
                size INTEGER DEFAULT NULL,
                filled_size INTEGER DEFAULT NULL,
                filled_notional NUMERIC(28, 12) DEFAULT NULL,
                open_type VARCHAR(20) DEFAULT NULL,
                position_mode INTEGER DEFAULT NULL,
                leverage INTEGER DEFAULT NULL,
                fee NUMERIC(28, 12) DEFAULT NULL,
                fee_currency VARCHAR(20) DEFAULT NULL,
                account VARCHAR(20) DEFAULT NULL,
                filled_time BIGINT DEFAULT NULL,
                created_time BIGINT DEFAULT NULL,
                updated_time BIGINT DEFAULT NULL,
                raw_data JSONB NOT NULL DEFAULT \'{}\',
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_order_id ON futures_order (order_id) WHERE order_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_client ON futures_order (client_order_id) WHERE client_order_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_futures_order_symbol ON futures_order (symbol)');
        $this->addSql('CREATE INDEX idx_futures_order_status ON futures_order (status)');
        $this->addSql('CREATE INDEX idx_futures_order_client_order_id ON futures_order (client_order_id)');
        $this->addSql("COMMENT ON COLUMN futures_order.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN futures_order.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: futures_plan_order
        // ============================================================
        $this->addSql('
            CREATE TABLE futures_plan_order (
                id BIGSERIAL PRIMARY KEY,
                order_id VARCHAR(80) DEFAULT NULL,
                client_order_id VARCHAR(80) DEFAULT NULL,
                symbol VARCHAR(50) NOT NULL,
                side INTEGER DEFAULT NULL,
                type VARCHAR(20) DEFAULT NULL,
                status VARCHAR(30) DEFAULT NULL,
                trigger_price NUMERIC(24, 12) DEFAULT NULL,
                execution_price NUMERIC(24, 12) DEFAULT NULL,
                price NUMERIC(24, 12) DEFAULT NULL,
                size INTEGER DEFAULT NULL,
                open_type VARCHAR(20) DEFAULT NULL,
                position_mode INTEGER DEFAULT NULL,
                leverage INTEGER DEFAULT NULL,
                plan_type VARCHAR(20) DEFAULT NULL,
                trigger_time BIGINT DEFAULT NULL,
                created_time BIGINT DEFAULT NULL,
                updated_time BIGINT DEFAULT NULL,
                raw_data JSONB NOT NULL DEFAULT \'{}\',
                futures_order_id BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_futures_plan_order_futures_order FOREIGN KEY (futures_order_id) REFERENCES futures_order(id) ON DELETE SET NULL
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_plan_order_order_id ON futures_plan_order (order_id) WHERE order_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_plan_order_client ON futures_plan_order (client_order_id) WHERE client_order_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_futures_plan_order_symbol ON futures_plan_order (symbol)');
        $this->addSql('CREATE INDEX idx_futures_plan_order_status ON futures_plan_order (status)');
        $this->addSql('CREATE INDEX idx_futures_plan_order_futures_order_id ON futures_plan_order (futures_order_id)');
        $this->addSql("COMMENT ON COLUMN futures_plan_order.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN futures_plan_order.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: futures_order_trade
        // ============================================================
        $this->addSql('
            CREATE TABLE futures_order_trade (
                id BIGSERIAL PRIMARY KEY,
                trade_id VARCHAR(80) DEFAULT NULL,
                order_id VARCHAR(80) NOT NULL,
                symbol VARCHAR(50) NOT NULL,
                side INTEGER NOT NULL,
                price NUMERIC(24, 12) NOT NULL,
                size INTEGER NOT NULL,
                fee NUMERIC(28, 12) DEFAULT NULL,
                fee_currency VARCHAR(20) DEFAULT NULL,
                trade_time BIGINT NOT NULL,
                raw_data JSONB NOT NULL DEFAULT \'{}\',
                futures_order_id BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_futures_order_trade_futures_order FOREIGN KEY (futures_order_id) REFERENCES futures_order(id) ON DELETE SET NULL
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_trade_trade_id ON futures_order_trade (trade_id) WHERE trade_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_futures_order_trade_order_id ON futures_order_trade (order_id)');
        $this->addSql('CREATE INDEX idx_futures_order_trade_symbol ON futures_order_trade (symbol)');
        $this->addSql('CREATE INDEX idx_futures_order_trade_futures_order_id ON futures_order_trade (futures_order_id)');
        $this->addSql("COMMENT ON COLUMN futures_order_trade.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN futures_order_trade.updated_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS futures_order_trade');
        $this->addSql('DROP TABLE IF EXISTS futures_plan_order');
        $this->addSql('DROP TABLE IF EXISTS futures_order');
    }
}

