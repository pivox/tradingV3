<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contract_cooldown and order_lifecycle tables for exposure guard and order tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS contract_cooldown (
            id BIGSERIAL NOT NULL,
            symbol VARCHAR(50) NOT NULL,
            active_until TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            reason VARCHAR(120) NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_contract_cooldown_symbol ON contract_cooldown (symbol)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_contract_cooldown_active_until ON contract_cooldown (active_until)');

        $this->addSql('CREATE TABLE IF NOT EXISTS order_lifecycle (
            id BIGSERIAL NOT NULL,
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
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_order_lifecycle_order_id ON order_lifecycle (order_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_lifecycle_symbol ON order_lifecycle (symbol)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS order_lifecycle');
        $this->addSql('DROP TABLE IF EXISTS contract_cooldown');
    }
}

