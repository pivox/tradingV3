<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable, account-serializing Hyperliquid testnet execution attempts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
        CREATE TABLE hyperliquid_testnet_execution_attempt (
            idempotency_key VARCHAR(128) NOT NULL,
            scope VARCHAR(64) NOT NULL,
            active_slot SMALLINT DEFAULT 1,
            plan_fingerprint VARCHAR(64) NOT NULL,
            client_order_id VARCHAR(128) NOT NULL,
            correlation_id VARCHAR(128) NOT NULL,
            state VARCHAR(32) NOT NULL,
            result_payload JSONB DEFAULT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(idempotency_key),
            CONSTRAINT hl_testnet_execution_attempt_active_slot CHECK (active_slot IS NULL OR active_slot = 1),
            CONSTRAINT hl_testnet_execution_attempt_state CHECK (
                state IN ('reserved', 'submitted', 'compensating', 'terminal_accepted', 'terminal_rejected', 'terminal_failed')
            ),
            CONSTRAINT hl_testnet_execution_attempt_active_uniq UNIQUE (scope, active_slot)
        )
        SQL);
        $this->addSql('CREATE INDEX hl_testnet_execution_attempt_updated_idx ON hyperliquid_testnet_execution_attempt (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS hyperliquid_testnet_execution_attempt');
    }
}
