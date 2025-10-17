<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour la table mtf_lock
 */
final class Version20250101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mtf_lock table for MTF execution locking';
    }

    public function up(Schema $schema): void
    {
        // Table mtf_lock
        $this->addSql('CREATE TABLE mtf_lock (
            lock_key VARCHAR(50) PRIMARY KEY,
            process_id VARCHAR(100) NOT NULL,
            acquired_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            expires_at TIMESTAMPTZ,
            metadata TEXT
        )');

        $this->addSql('CREATE INDEX idx_mtf_lock_expires_at ON mtf_lock(expires_at)');
        $this->addSql('CREATE INDEX idx_mtf_lock_process_id ON mtf_lock(process_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mtf_lock');
    }
}




