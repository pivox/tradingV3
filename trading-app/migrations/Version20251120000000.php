<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy worker order tables and detach order_intent from order_plan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_intent DROP CONSTRAINT IF EXISTS fk_7de3ebc41ee3abd0');
        $this->addSql('DROP INDEX IF EXISTS idx_7de3ebc41ee3abd0');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS order_plan_id');

        $this->addSql('DROP TABLE IF EXISTS exchange_order');
        $this->addSql('DROP TABLE IF EXISTS order_lifecycle');
        $this->addSql('DROP TABLE IF EXISTS order_plan');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE order_plan (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                plan_time TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                side VARCHAR(10) NOT NULL,
                risk_json JSONB NOT NULL,
                context_json JSONB NOT NULL,
                exec_json JSONB NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'PLANNED'
            )
        SQL);
        $this->addSql('CREATE INDEX idx_order_plan_symbol ON order_plan (symbol)');
        $this->addSql('CREATE INDEX idx_order_plan_status ON order_plan (status)');
        $this->addSql('CREATE INDEX idx_order_plan_plan_time ON order_plan (plan_time)');
        $this->addSql("COMMENT ON COLUMN order_plan.plan_time IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql(<<<'SQL'
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
                kind VARCHAR(24) NOT NULL DEFAULT 'ENTRY'
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_order_lifecycle_order_id ON order_lifecycle (order_id)');
        $this->addSql('CREATE INDEX idx_order_lifecycle_symbol ON order_lifecycle (symbol)');
        $this->addSql("COMMENT ON COLUMN order_lifecycle.last_event_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN order_lifecycle.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN order_lifecycle.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql(<<<'SQL'
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
        SQL);
        $this->addSql('CREATE INDEX idx_eb1edfd01ee3abd0 ON exchange_order (order_plan_id)');
        $this->addSql('CREATE INDEX idx_eb1edfd0dd842e46 ON exchange_order (position_id)');
        $this->addSql('CREATE INDEX idx_exchange_order_symbol ON exchange_order (symbol)');
        $this->addSql('CREATE INDEX idx_exchange_order_kind ON exchange_order (kind)');
        $this->addSql('CREATE INDEX idx_exchange_order_status ON exchange_order (status)');
        $this->addSql('CREATE UNIQUE INDEX ux_exchange_order_client ON exchange_order (client_order_id)');
        $this->addSql("COMMENT ON COLUMN exchange_order.submitted_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN exchange_order.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN exchange_order.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('ALTER TABLE order_intent ADD COLUMN order_plan_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE order_intent ADD CONSTRAINT fk_7de3ebc41ee3abd0 FOREIGN KEY (order_plan_id) REFERENCES order_plan (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_7de3ebc41ee3abd0 ON order_intent (order_plan_id)');
    }
}
