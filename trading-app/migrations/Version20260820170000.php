<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the exact nullable modern strategy identity to Paper execution cells without backfill.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paper_execution_cell ADD mode_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE paper_execution_cell ADD mode_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE paper_execution_cell ADD setup_id VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE paper_execution_cell ADD setup_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE paper_execution_cell ADD canonical_side VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE paper_execution_cell ADD canonical_config_hash VARCHAR(71) DEFAULT NULL');
        $this->addSql('ALTER TABLE paper_execution_cell ADD condition_catalog_hash VARCHAR(71) DEFAULT NULL');
        $this->addSql(<<<'SQL'
ALTER TABLE paper_execution_cell ADD CONSTRAINT chk_paper_execution_cell_modern_identity_all_or_none CHECK (
    (mode_id IS NULL AND mode_version IS NULL AND setup_id IS NULL AND setup_version IS NULL
        AND canonical_side IS NULL AND canonical_config_hash IS NULL AND condition_catalog_hash IS NULL)
    OR
    (mode_id IS NOT NULL AND mode_version IS NOT NULL AND setup_id IS NOT NULL AND setup_version IS NOT NULL
        AND canonical_side IS NOT NULL AND canonical_config_hash IS NOT NULL AND condition_catalog_hash IS NOT NULL)
)
SQL);
        $this->addSql("ALTER TABLE paper_execution_cell ADD CONSTRAINT chk_paper_execution_cell_modern_profile CHECK (mode_id IS NULL OR strategy_profile = mode_id)");
        $this->addSql("ALTER TABLE paper_execution_cell ADD CONSTRAINT chk_paper_execution_cell_modern_versions CHECK (mode_id IS NULL OR (mode_version ~ '^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$' AND setup_version ~ '^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$'))");
        $this->addSql("ALTER TABLE paper_execution_cell ADD CONSTRAINT chk_paper_execution_cell_modern_side CHECK (canonical_side IS NULL OR canonical_side IN ('long', 'short'))");
        $this->addSql("ALTER TABLE paper_execution_cell ADD CONSTRAINT chk_paper_execution_cell_modern_hashes CHECK (mode_id IS NULL OR (canonical_config_hash ~ '^sha256:[a-f0-9]{64}$' AND condition_catalog_hash ~ '^sha256:[a-f0-9]{64}$'))");
        $this->addSql('CREATE INDEX idx_paper_execution_cell_modern_identity ON paper_execution_cell (network, market_data_venue, mode_id, setup_id, canonical_side) WHERE mode_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_paper_execution_cell_modern_identity');
        $this->addSql('ALTER TABLE paper_execution_cell DROP CONSTRAINT chk_paper_execution_cell_modern_hashes');
        $this->addSql('ALTER TABLE paper_execution_cell DROP CONSTRAINT chk_paper_execution_cell_modern_side');
        $this->addSql('ALTER TABLE paper_execution_cell DROP CONSTRAINT chk_paper_execution_cell_modern_versions');
        $this->addSql('ALTER TABLE paper_execution_cell DROP CONSTRAINT chk_paper_execution_cell_modern_profile');
        $this->addSql('ALTER TABLE paper_execution_cell DROP CONSTRAINT chk_paper_execution_cell_modern_identity_all_or_none');
        foreach (['condition_catalog_hash', 'canonical_config_hash', 'canonical_side', 'setup_version', 'setup_id', 'mode_version', 'mode_id'] as $column) {
            $this->addSql('ALTER TABLE paper_execution_cell DROP COLUMN ' . $column);
        }
    }
}
