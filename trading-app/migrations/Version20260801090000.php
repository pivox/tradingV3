<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #302 — canonical modern identity on the durable lineage row.
 *
 * Columns intentionally remain nullable at the database level: historical rows are not
 * backfilled from profile/symbol/time. Modern completeness is enforced before execution.
 */
final class Version20260801090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable canonical mode/setup/decision identity to trade_lineage without guessing legacy history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_lineage ADD condition_catalog_hash VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD mode_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD mode_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD setup_id VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD setup_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD decision_id VARCHAR(96) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD decision_key VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD effective_config_reference VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX ux_trade_lineage_decision_id ON trade_lineage (decision_id) WHERE decision_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_trade_lineage_canonical_contract ON trade_lineage (mode_id, mode_version, setup_id, setup_version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS ux_trade_lineage_decision_id');
        $this->addSql('DROP INDEX IF EXISTS idx_trade_lineage_canonical_contract');
        $this->addSql('ALTER TABLE trade_lineage DROP condition_catalog_hash');
        $this->addSql('ALTER TABLE trade_lineage DROP mode_id');
        $this->addSql('ALTER TABLE trade_lineage DROP mode_version');
        $this->addSql('ALTER TABLE trade_lineage DROP setup_id');
        $this->addSql('ALTER TABLE trade_lineage DROP setup_version');
        $this->addSql('ALTER TABLE trade_lineage DROP decision_id');
        $this->addSql('ALTER TABLE trade_lineage DROP decision_key');
        $this->addSql('ALTER TABLE trade_lineage DROP effective_config_reference');
    }
}
