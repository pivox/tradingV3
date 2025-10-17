<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add source field to indicator_snapshots table for tracking data provenance (SQL/PHP)
 */
final class Version20250117000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source field to indicator_snapshots table for tracking data provenance';
    }

    public function up(Schema $schema): void
    {
        // Add source column to indicator_snapshots table
        $this->addSql('ALTER TABLE indicator_snapshots ADD COLUMN source TEXT NOT NULL DEFAULT \'PHP\'');
        
        // Add index on source for performance
        $this->addSql('CREATE INDEX idx_ind_snap_source ON indicator_snapshots(source)');
        
        // Add comment to document the field
        $this->addSql('COMMENT ON COLUMN indicator_snapshots.source IS \'Source of indicator calculation: PHP or SQL\'');
    }

    public function down(Schema $schema): void
    {
        // Remove index
        $this->addSql('DROP INDEX IF EXISTS idx_ind_snap_source');
        
        // Remove source column
        $this->addSql('ALTER TABLE indicator_snapshots DROP COLUMN IF EXISTS source');
    }
}
