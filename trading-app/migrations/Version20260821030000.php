<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the exact verified Paper dataset source build version without backfill.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paper_execution_cell ADD dataset_source_build_version TEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
ALTER TABLE paper_execution_cell ADD CONSTRAINT chk_paper_execution_cell_dataset_source_build_version CHECK (
    dataset_source_build_version IS NULL
    OR (dataset_id IS NOT NULL AND dataset_events_sha256 IS NOT NULL
        AND dataset_source_build_version <> ''
        AND btrim(dataset_source_build_version) = dataset_source_build_version)
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paper_execution_cell DROP CONSTRAINT chk_paper_execution_cell_dataset_source_build_version');
        $this->addSql('ALTER TABLE paper_execution_cell DROP COLUMN dataset_source_build_version');
    }
}
