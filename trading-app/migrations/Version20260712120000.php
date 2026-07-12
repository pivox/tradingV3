<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable singleton Hyperliquid testnet kill switch state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
        CREATE TABLE IF NOT EXISTS hyperliquid_testnet_kill_switch_state (
            scope VARCHAR(64) NOT NULL,
            tripped BOOLEAN NOT NULL,
            reason VARCHAR(128) NOT NULL,
            audit_context JSONB NOT NULL,
            tripped_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(scope)
        )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS hyperliquid_testnet_kill_switch_state');
    }
}
