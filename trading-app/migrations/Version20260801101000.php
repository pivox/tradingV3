<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue 302 Lot 2A: nullable canonical identity columns on lifecycle events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS mode_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS mode_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS setup_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS setup_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS condition_catalog_hash VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS decision_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS decision_key VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS intent_id VARCHAR(96) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS trade_id VARCHAR(96) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS effective_config_reference VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS effective_config_snapshot JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (['mode_id', 'mode_version', 'setup_id', 'setup_version', 'condition_catalog_hash', 'decision_id', 'decision_key', 'intent_id', 'trade_id', 'effective_config_reference', 'effective_config_snapshot'] as $column) {
            $this->addSql(sprintf('ALTER TABLE trade_lifecycle_event DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
