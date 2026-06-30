<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent Hyperliquid nonce state table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
        CREATE TABLE IF NOT EXISTS hyperliquid_nonce_state (
            id BIGSERIAL NOT NULL,
            environment VARCHAR(32) NOT NULL,
            network VARCHAR(32) NOT NULL,
            account_address VARCHAR(128) NOT NULL,
            signer_address VARCHAR(128) NOT NULL,
            last_nonce BIGINT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_hyperliquid_nonce_signer_scope ON hyperliquid_nonce_state (environment, network, signer_address)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_hyperliquid_nonce_account ON hyperliquid_nonce_state (environment, network, account_address)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_hyperliquid_nonce_updated_at ON hyperliquid_nonce_state (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_hyperliquid_nonce_updated_at');
        $this->addSql('DROP INDEX IF EXISTS idx_hyperliquid_nonce_account');
        $this->addSql('DROP INDEX IF EXISTS ux_hyperliquid_nonce_signer_scope');
        $this->addSql('DROP TABLE IF EXISTS hyperliquid_nonce_state');
    }
}
