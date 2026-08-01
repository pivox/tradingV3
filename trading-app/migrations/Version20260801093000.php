<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #302 — online indexes for canonical trade lineage.
 *
 * A failed PostgreSQL concurrent build can leave an INVALID index with the requested
 * name. Each build therefore drops that name first instead of using IF NOT EXISTS,
 * making an unrecorded/failed migration deterministic to resume.
 */
final class Version20260801093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Build canonical trade_lineage indexes concurrently with deterministic recovery from invalid indexes.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->assertPostgreSql();
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ux_trade_lineage_decision_id');
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY ux_trade_lineage_decision_id ON trade_lineage (decision_id) WHERE decision_id IS NOT NULL');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_trade_lineage_canonical_contract');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_trade_lineage_canonical_contract ON trade_lineage (mode_id, mode_version, setup_id, setup_version)');
    }

    public function down(Schema $schema): void
    {
        $this->assertPostgreSql();
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ux_trade_lineage_decision_id');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_trade_lineage_canonical_contract');
    }

    private function assertPostgreSql(): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform),
            'This migration can only be executed safely on PostgreSQL.',
        );
    }
}
