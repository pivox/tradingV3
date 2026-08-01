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
 * name. The catalog state is inspected before planning DDL: only invalid indexes are
 * replaced, while valid indexes are preserved or rejected if their definition differs.
 */
final class Version20260801093000 extends AbstractMigration
{
    private const UNIQUE_INDEX = 'ux_trade_lineage_decision_id';
    private const UNIQUE_INDEX_CREATE = 'CREATE UNIQUE INDEX CONCURRENTLY ux_trade_lineage_decision_id ON trade_lineage (decision_id) WHERE decision_id IS NOT NULL';
    private const CONTRACT_INDEX = 'idx_trade_lineage_canonical_contract';
    private const CONTRACT_INDEX_CREATE = 'CREATE INDEX CONCURRENTLY idx_trade_lineage_canonical_contract ON trade_lineage (mode_id, mode_version, setup_id, setup_version)';

    private const INDEX_STATE_SQL = <<<'SQL'
        SELECT index_state.indisvalid AS is_valid,
               pg_catalog.pg_get_indexdef(index_state.indexrelid) AS definition
        FROM pg_catalog.pg_class AS index_class
        INNER JOIN pg_catalog.pg_index AS index_state ON index_state.indexrelid = index_class.oid
        INNER JOIN pg_catalog.pg_class AS table_class ON table_class.oid = index_state.indrelid
        INNER JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid = table_class.relnamespace
        WHERE index_class.relname = :index_name
          AND table_class.relname = 'trade_lineage'
          AND table_namespace.nspname = current_schema()
        LIMIT 1
        SQL;

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
        $this->ensureIndex(self::UNIQUE_INDEX, self::UNIQUE_INDEX_CREATE);
        $this->ensureIndex(self::CONTRACT_INDEX, self::CONTRACT_INDEX_CREATE);
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

    private function ensureIndex(string $indexName, string $createSql): void
    {
        $state = $this->connection->fetchAssociative(self::INDEX_STATE_SQL, ['index_name' => $indexName]);
        if ($state === false) {
            $this->addSql($createSql);

            return;
        }

        $isValid = self::postgresBoolean($state['is_valid'] ?? null);
        $this->abortIf(
            $isValid === null,
            sprintf(
                'Cannot determine validity of index "%s"; manual intervention required before rerunning migration.',
                $indexName,
            ),
        );
        if ($isValid === false) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ' . $indexName);
            $this->addSql($createSql);

            return;
        }

        $actualDefinition = $state['definition'] ?? null;
        $definitionMatches = is_string($actualDefinition)
            && self::normalizeIndexDefinition($actualDefinition) === self::normalizeIndexDefinition($createSql);
        $this->abortIf(
            !$definitionMatches,
            sprintf(
                'Valid index "%s" has an unexpected definition and will not be dropped; manual intervention required.',
                $indexName,
            ),
        );
    }

    private static function postgresBoolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => null,
        };
    }

    private static function normalizeIndexDefinition(string $definition): string
    {
        $normalized = strtolower(trim($definition, " \n\r\t\v\0;"));
        $normalized = str_replace('"', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = str_replace(' concurrently ', ' ', $normalized);
        $normalized = preg_replace(
            '/\bon\s+(?:[a-z0-9_]+\.)?trade_lineage(?:\s+using\s+btree)?\s+/',
            'on trade_lineage ',
            $normalized,
        ) ?? $normalized;
        $normalized = preg_replace('/\s*,\s*/', ', ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\(\s*/', '(', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*\)/', ')', $normalized) ?? $normalized;
        $normalized = preg_replace(
            '/\s+where\s+\((decision_id\s+is\s+not\s+null)\)$/',
            ' where $1',
            $normalized,
        ) ?? $normalized;

        return $normalized;
    }
}
