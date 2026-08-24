<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824123000 extends AbstractMigration
{
    /** @var array<string, string> */
    private const INDEXES = [
        'idx_paper_execution_event_typed_source' => "CREATE INDEX CONCURRENTLY idx_paper_execution_event_typed_source ON paper_execution_event (cell_id, event_type, source_position) WHERE source_position IS NOT NULL",
        'idx_paper_execution_event_typed_effect' => "CREATE INDEX CONCURRENTLY idx_paper_execution_event_typed_effect ON paper_execution_event (cell_id, event_type, effect_key) WHERE effect_key IS NOT NULL",
    ];

    public function getDescription(): string
    {
        return 'Keep Paper journal recovery indexed by event type before PostgreSQL statistics exist.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->assertPostgreSql();
        foreach (self::INDEXES as $name => $definition) {
            $this->ensureIndex($name, $definition);
        }
    }

    public function down(Schema $schema): void
    {
        $this->assertPostgreSql();
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_paper_execution_event_typed_effect');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_paper_execution_event_typed_source');
    }

    private function assertPostgreSql(): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform),
            'This migration can only be executed safely on PostgreSQL.',
        );
    }

    private function ensureIndex(string $name, string $definition): void
    {
        $state = $this->connection->fetchAssociative(<<<'SQL'
SELECT index_state.indisvalid AS is_valid,
       pg_catalog.pg_get_indexdef(index_state.indexrelid) AS definition
FROM pg_catalog.pg_class AS index_class
INNER JOIN pg_catalog.pg_index AS index_state ON index_state.indexrelid = index_class.oid
INNER JOIN pg_catalog.pg_class AS table_class ON table_class.oid = index_state.indrelid
INNER JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid = table_class.relnamespace
WHERE index_class.relname = :index_name
  AND table_class.relname = 'paper_execution_event'
  AND table_namespace.nspname = current_schema()
LIMIT 1
SQL, ['index_name' => $name]);
        if ($state === false) {
            $this->addSql($definition);

            return;
        }
        $valid = match ($state['is_valid'] ?? null) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => null,
        };
        $this->abortIf($valid === null, sprintf('Cannot determine validity of index "%s".', $name));
        if ($valid === false) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ' . $name);
            $this->addSql($definition);

            return;
        }
        $actual = $state['definition'] ?? null;
        $this->abortIf(
            !is_string($actual) || self::normalize($actual) !== self::normalize($definition),
            sprintf('Valid index "%s" has an unexpected definition.', $name),
        );
    }

    private static function normalize(string $definition): string
    {
        $normalized = strtolower(trim($definition, " \n\r\t\v\0;"));
        $normalized = str_replace(['"', ' concurrently ', ' using btree '], ['', ' ', ' '], $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bon\s+[a-z0-9_]+\./', 'on ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+where\s+\(([^()]*)\)$/', ' where $1', $normalized) ?? $normalized;

        return $normalized;
    }
}
