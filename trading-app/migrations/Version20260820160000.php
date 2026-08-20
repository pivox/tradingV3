<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Issue #192 — online indexes for exact effective-config usage navigation. */
final class Version20260820160000 extends AbstractMigration
{
    /** @var array<string,array{table:string,create:string}> */
    private const INDEXES = [
        'idx_trade_lineage_effective_usage_run' => [
            'table' => 'trade_lineage',
            'create' => 'CREATE INDEX CONCURRENTLY idx_trade_lineage_effective_usage_run ON trade_lineage (orchestration_run_id) WHERE orchestration_run_id IS NOT NULL',
        ],
        'idx_trade_lineage_effective_usage_set' => [
            'table' => 'trade_lineage',
            'create' => 'CREATE INDEX CONCURRENTLY idx_trade_lineage_effective_usage_set ON trade_lineage (orchestration_set_id) WHERE orchestration_set_id IS NOT NULL',
        ],
        'idx_order_intent_effective_usage_set' => [
            'table' => 'order_intent',
            'create' => 'CREATE INDEX CONCURRENTLY idx_order_intent_effective_usage_set ON order_intent (orchestration_set_id) WHERE orchestration_set_id IS NOT NULL',
        ],
        'idx_tle_effective_usage_set' => [
            'table' => 'trade_lifecycle_event',
            'create' => 'CREATE INDEX CONCURRENTLY idx_tle_effective_usage_set ON trade_lifecycle_event (orchestration_set_id) WHERE orchestration_set_id IS NOT NULL',
        ],
        'idx_order_intent_effective_usage_decision' => [
            'table' => 'order_intent',
            'create' => 'CREATE INDEX CONCURRENTLY idx_order_intent_effective_usage_decision ON order_intent (decision_id) WHERE decision_id IS NOT NULL',
        ],
        'idx_tle_effective_usage_decision' => [
            'table' => 'trade_lifecycle_event',
            'create' => 'CREATE INDEX CONCURRENTLY idx_tle_effective_usage_decision ON trade_lifecycle_event (decision_id) WHERE decision_id IS NOT NULL',
        ],
        'idx_order_intent_effective_usage_trade' => [
            'table' => 'order_intent',
            'create' => 'CREATE INDEX CONCURRENTLY idx_order_intent_effective_usage_trade ON order_intent (trade_id) WHERE trade_id IS NOT NULL',
        ],
        'idx_tle_effective_usage_trade' => [
            'table' => 'trade_lifecycle_event',
            'create' => 'CREATE INDEX CONCURRENTLY idx_tle_effective_usage_trade ON trade_lifecycle_event (trade_id) WHERE trade_id IS NOT NULL',
        ],
    ];

    private const INDEX_STATE_SQL = <<<'SQL'
        SELECT index_state.indisvalid AS is_valid,
               pg_catalog.pg_get_indexdef(index_state.indexrelid) AS definition
        FROM pg_catalog.pg_class AS index_class
        INNER JOIN pg_catalog.pg_index AS index_state ON index_state.indexrelid = index_class.oid
        INNER JOIN pg_catalog.pg_class AS table_class ON table_class.oid = index_state.indrelid
        INNER JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid = table_class.relnamespace
        WHERE index_class.relname = :index_name
          AND table_class.relname = :table_name
          AND table_namespace.nspname = current_schema()
        LIMIT 1
        SQL;

    public function getDescription(): string
    {
        return 'Build exact effective-config usage lookup indexes concurrently with invalid-index recovery.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->assertPostgreSql();
        foreach (self::INDEXES as $name => $index) {
            $this->ensureIndex($name, $index['table'], $index['create']);
        }
    }

    public function down(Schema $schema): void
    {
        $this->assertPostgreSql();
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_tle_effective_usage_trade');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_order_intent_effective_usage_trade');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_tle_effective_usage_decision');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_order_intent_effective_usage_decision');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_tle_effective_usage_set');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_order_intent_effective_usage_set');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_trade_lineage_effective_usage_set');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_trade_lineage_effective_usage_run');
    }

    private function assertPostgreSql(): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform),
            'This migration can only be executed safely on PostgreSQL.',
        );
    }

    private function ensureIndex(string $name, string $table, string $createSql): void
    {
        $state = $this->connection->fetchAssociative(self::INDEX_STATE_SQL, [
            'index_name' => $name,
            'table_name' => $table,
        ]);
        if ($state === false) {
            $this->addSql($createSql);

            return;
        }

        $isValid = self::postgresBoolean($state['is_valid'] ?? null);
        $this->abortIf(
            $isValid === null,
            sprintf('Cannot determine validity of index "%s"; manual intervention required before rerunning migration.', $name),
        );
        if ($isValid === false) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ' . $name);
            $this->addSql($createSql);

            return;
        }

        $definition = $state['definition'] ?? null;
        $this->abortIf(
            !is_string($definition) || self::normalizeDefinition($definition) !== self::normalizeDefinition($createSql),
            sprintf('Valid index "%s" has an unexpected definition and will not be dropped; manual intervention required.', $name),
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

    private static function normalizeDefinition(string $definition): string
    {
        $normalized = strtolower(trim($definition, " \n\r\t\v\0;"));
        $normalized = str_replace('"', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = str_replace([' concurrently ', ' using btree '], ' ', $normalized);
        $normalized = preg_replace('/\bon\s+[a-z0-9_]+\./', 'on ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+where\s+\(([^()]*)\)$/', ' where $1', $normalized) ?? $normalized;
        $normalized = preg_replace('/\(\s*/', '(', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*\)/', ')', $normalized) ?? $normalized;

        return $normalized;
    }
}
