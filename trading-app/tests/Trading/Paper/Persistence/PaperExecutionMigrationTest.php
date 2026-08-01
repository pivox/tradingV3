<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260801120000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class PaperExecutionMigrationTest extends TestCase
{
    private const MIGRATION_FILE = __DIR__ . '/../../../../migrations/Version20260801120000.php';

    /** @var array<string, int> */
    private const PROVENANCE = [
        'paper_network' => 16,
        'paper_execution_cell_id' => 71,
        'configuration_snapshot_id' => 71,
        'paper_eligibility' => 32,
    ];

    public function testPostgreSqlSchemaIsConstrainedAndJournalIsAppendOnly(): void
    {
        $connection = $this->postgresConnection();
        $schemaName = sprintf('paper_execution_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $quotedSchema = $connection->getDatabasePlatform()->quoteSingleIdentifier($schemaName);

        try {
            $connection->executeStatement('CREATE SCHEMA ' . $quotedSchema);
            $connection->executeStatement('SET search_path TO ' . $quotedSchema . ', public');
            $this->createMinimalTradeTables($connection);
            $this->executeMigration($connection, 'up');

            self::assertSame(
                ['paper_configuration_snapshot', 'paper_execution_cell', 'paper_execution_checkpoint', 'paper_execution_event'],
                $connection->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE 'paper_%' ORDER BY table_name"),
            );
            $this->assertNullableProvenanceColumns($connection);

            $snapshotId = 'sha256:' . str_repeat('a', 64);
            $cellId = 'sha256:' . str_repeat('b', 64);
            $connection->executeStatement('INSERT INTO paper_configuration_snapshot (id, schema_version, canonical_json, content_checksum, created_at) VALUES (?, 1, ?::jsonb, ?, NOW())', [$snapshotId, '{}', str_repeat('a', 64)]);
            $connection->executeStatement("INSERT INTO paper_execution_cell (id, network, market_data_venue, configuration_snapshot_id, strategy_profile, run_id, account_namespace, eligibility, terminal_state, created_at) VALUES (?, 'testnet', 'hyperliquid', ?, 'scalper_micro', 'run-1', ?, 'reference_only', 'active', NOW())", [$cellId, $snapshotId, 'paper:cell:v1:' . str_repeat('b', 64)]);

            $this->assertConstraintViolation($connection, '23514', "INSERT INTO paper_execution_cell (id, network, market_data_venue, configuration_snapshot_id, strategy_profile, run_id, account_namespace, eligibility, terminal_state, created_at) VALUES (?, 'legacy_unknown', 'hyperliquid', ?, 'regular', 'run-2', ?, 'reference_only', 'active', NOW())", ['sha256:' . str_repeat('c', 64), $snapshotId, 'paper:cell:v1:' . str_repeat('c', 64)]);
            $this->assertConstraintViolation($connection, '23514', "INSERT INTO paper_execution_cell (id, network, market_data_venue, configuration_snapshot_id, strategy_profile, run_id, account_namespace, eligibility, terminal_state, created_at) VALUES (?, 'mainnet', 'okx', ?, 'regular', 'run-3', ?, 'baseline', 'active', NOW())", ['sha256:' . str_repeat('d', 64), $snapshotId, 'paper:cell:v1:' . str_repeat('d', 64)]);
            $this->assertConstraintViolation($connection, '23505', "INSERT INTO paper_execution_cell (id, network, market_data_venue, configuration_snapshot_id, strategy_profile, run_id, account_namespace, eligibility, terminal_state, created_at) VALUES (?, 'mainnet', 'okx', ?, 'regular', 'run-1', ?, 'reference_only', 'active', NOW())", ['sha256:' . str_repeat('e', 64), $snapshotId, 'paper:cell:v1:' . str_repeat('e', 64)]);

            $connection->executeStatement("INSERT INTO paper_execution_event (cell_id, journal_ordinal, event_type, payload, payload_checksum, appended_at) VALUES (?, 1, 'source_claimed', ?::jsonb, ?, NOW())", [$cellId, '{}', str_repeat('f', 64)]);
            $this->assertConstraintViolation($connection, '23505', "INSERT INTO paper_execution_event (cell_id, journal_ordinal, event_type, payload, payload_checksum, appended_at) VALUES (?, 1, 'source_claimed', ?::jsonb, ?, NOW())", [$cellId, '{}', str_repeat('f', 64)]);
            $this->assertAppendOnlyViolation($connection, 'UPDATE paper_execution_event SET event_type = event_type WHERE cell_id = ?', [$cellId]);
            $this->assertAppendOnlyViolation($connection, 'DELETE FROM paper_execution_event WHERE cell_id = ?', [$cellId]);

            $this->executeMigration($connection, 'down');
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE 'paper_%'"));
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND column_name IN ('paper_network', 'paper_execution_cell_id', 'configuration_snapshot_id', 'paper_eligibility')"));
        } finally {
            try {
                $connection->executeStatement('SET search_path TO public');
                $connection->executeStatement('DROP SCHEMA IF EXISTS ' . $quotedSchema . ' CASCADE');
            } finally {
                $connection->close();
            }
        }
    }

    private function postgresConnection(): Connection
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        $database = is_string($dsn) ? ltrim((string) parse_url($dsn, PHP_URL_PATH), '/') : '';
        if (!str_ends_with($database, '_paper_test')) {
            self::markTestSkipped('Paper execution integration tests require a database ending in _paper_test.');
        }

        return DriverManager::getConnection(['url' => $dsn]);
    }

    private function createMinimalTradeTables(Connection $connection): void
    {
        foreach (['order_intent', 'trade_lineage', 'trade_lifecycle_event', 'fill_cost_ledger', 'trade_zone_events'] as $table) {
            $connection->executeStatement(sprintf('CREATE TABLE %s (id BIGSERIAL PRIMARY KEY)', $table));
        }
    }

    private function assertNullableProvenanceColumns(Connection $connection): void
    {
        foreach (self::PROVENANCE as $name => $length) {
            $columns = $connection->fetchAllAssociative("SELECT table_name, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN ('order_intent', 'trade_lineage', 'trade_lifecycle_event', 'fill_cost_ledger', 'trade_zone_events') AND column_name = ? ORDER BY table_name", [$name]);
            self::assertCount(5, $columns, $name);
            foreach ($columns as $column) {
                self::assertSame($length, (int) $column['character_maximum_length']);
                self::assertSame('YES', $column['is_nullable']);
            }
        }
    }

    /** @param list<mixed> $params */
    private function assertConstraintViolation(Connection $connection, string $state, string $sql, array $params): void
    {
        try {
            $connection->executeStatement($sql, $params);
            self::fail('Expected PostgreSQL constraint violation.');
        } catch (DriverException $exception) {
            self::assertSame($state, $exception->getSQLState());
        }
    }

    /** @param list<mixed> $params */
    private function assertAppendOnlyViolation(Connection $connection, string $sql, array $params): void
    {
        try {
            $connection->executeStatement($sql, $params);
            self::fail('Paper journal mutation was accepted.');
        } catch (DriverException $exception) {
            self::assertSame('P0001', $exception->getSQLState());
            self::assertStringContainsString('paper_execution_event_append_only', $exception->getMessage());
        }
    }

    private function executeMigration(Connection $connection, string $direction): void
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;
        /** @var AbstractMigration $migration */
        $migration = new Version20260801120000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
