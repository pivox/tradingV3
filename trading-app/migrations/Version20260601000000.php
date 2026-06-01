<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cross-profile symbol execution lock table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS symbol_execution_lock (
    id BIGSERIAL NOT NULL,
    owner_order_intent_id BIGINT DEFAULT NULL,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    status VARCHAR(24) NOT NULL,
    owner_profile VARCHAR(80) DEFAULT NULL,
    owner_decision_key VARCHAR(255) DEFAULT NULL,
    locked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    released_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
    release_reason VARCHAR(120) DEFAULT NULL,
    payload JSONB NOT NULL,
    created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    PRIMARY KEY(id)
)
SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_symbol_execution_lock_owner_intent ON symbol_execution_lock (owner_order_intent_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_symbol_execution_lock_active ON symbol_execution_lock (exchange, market_type, symbol, released_at)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_symbol_execution_lock_active_symbol ON symbol_execution_lock (exchange, market_type, symbol) WHERE released_at IS NULL');
        $this->addSql('ALTER TABLE symbol_execution_lock ADD CONSTRAINT FK_SYMBOL_EXECUTION_LOCK_OWNER_INTENT FOREIGN KEY (owner_order_intent_id) REFERENCES order_intent (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE symbol_execution_lock DROP CONSTRAINT IF EXISTS FK_SYMBOL_EXECUTION_LOCK_OWNER_INTENT');
        $this->addSql('DROP INDEX IF EXISTS ux_symbol_execution_lock_active_symbol');
        $this->addSql('DROP INDEX IF EXISTS idx_symbol_execution_lock_active');
        $this->addSql('DROP INDEX IF EXISTS idx_symbol_execution_lock_owner_intent');
        $this->addSql('DROP TABLE IF EXISTS symbol_execution_lock');
    }
}
