<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use App\Tests\Support\PostgresIntegrationDatabaseGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260719120000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class MarketDataVenueMigrationTest extends TestCase
{
    private const MIGRATION_FILE = __DIR__ . '/../../../../migrations/Version20260719120000.php';

    /** @var array<string,list<string>> */
    private const INDEX_COLUMNS = [
        'idx_order_intent_market_data_venue' => ['market_data_venue', 'strategy_profile', 'created_at'],
        'idx_trade_lineage_market_data_venue' => ['market_data_venue', 'profile', 'created_at'],
        'idx_trade_lifecycle_market_data_venue' => ['market_data_venue', 'config_profile', 'happened_at', 'id'],
        'idx_fill_cost_ledger_market_data_venue' => ['market_data_venue', 'internal_trade_id', 'occurred_at', 'id'],
        'idx_trade_zone_market_data_venue' => ['market_data_venue', 'config_profile', 'happened_at', 'id'],
    ];

    /** @var list<string> */
    private const TABLES = [
        'order_intent',
        'trade_lineage',
        'trade_lifecycle_event',
        'fill_cost_ledger',
        'trade_zone_events',
    ];

    public function testMigrationExistsAndDownDropsIndexesAndConstraintsBeforeColumnsInReverseTableOrder(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $downSql = $this->migrationSql($connection, 'down');

        self::assertSame([
            'DROP INDEX idx_trade_zone_market_data_venue',
            'ALTER TABLE trade_zone_events DROP CONSTRAINT chk_trade_zone_events_market_data_venue',
            'DROP INDEX idx_fill_cost_ledger_market_data_venue',
            'ALTER TABLE fill_cost_ledger DROP CONSTRAINT chk_fill_cost_ledger_market_data_venue',
            'DROP INDEX idx_trade_lifecycle_market_data_venue',
            'ALTER TABLE trade_lifecycle_event DROP CONSTRAINT chk_trade_lifecycle_event_market_data_venue',
            'DROP INDEX idx_trade_lineage_market_data_venue',
            'ALTER TABLE trade_lineage DROP CONSTRAINT chk_trade_lineage_market_data_venue',
            'DROP INDEX idx_order_intent_market_data_venue',
            'ALTER TABLE order_intent DROP CONSTRAINT chk_order_intent_market_data_venue',
            'ALTER TABLE trade_zone_events DROP COLUMN market_data_venue',
            'ALTER TABLE fill_cost_ledger DROP COLUMN market_data_venue',
            'ALTER TABLE trade_lifecycle_event DROP COLUMN market_data_venue',
            'ALTER TABLE trade_lineage DROP COLUMN market_data_venue',
            'ALTER TABLE order_intent DROP COLUMN market_data_venue',
        ], $downSql);

        $connection->close();
    }

    public function testPostgreSqlMigrationAddsConstrainedNullableColumnsAndLeadingIndexesAndReversesCleanly(): void
    {
        $connection = $this->postgresConnectionOrSkip();
        $schemaName = sprintf('market_data_venue_contract_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $quotedSchema = $connection->getDatabasePlatform()->quoteSingleIdentifier($schemaName);

        try {
            $connection->executeStatement('CREATE SCHEMA ' . $quotedSchema);
            $connection->executeStatement('SET search_path TO ' . $quotedSchema . ', public');
            $this->createMinimalTables($connection);
            $this->executeMigration($connection, 'up');

            $columns = $connection->fetchAllAssociative(<<<'SQL'
SELECT table_name, data_type, character_maximum_length, is_nullable
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND column_name = 'market_data_venue'
ORDER BY table_name
SQL);

            self::assertCount(5, $columns);
            self::assertSame(
                ['fill_cost_ledger', 'order_intent', 'trade_lifecycle_event', 'trade_lineage', 'trade_zone_events'],
                array_column($columns, 'table_name'),
            );
            foreach ($columns as $column) {
                self::assertSame('character varying', $column['data_type']);
                self::assertSame(32, (int) $column['character_maximum_length']);
                self::assertSame('YES', $column['is_nullable']);
            }

            $indexDefinitions = $connection->fetchAllKeyValue(<<<'SQL'
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = current_schema()
  AND indexname LIKE '%_market_data_venue'
SQL);
            self::assertCount(5, $indexDefinitions);
            foreach (self::INDEX_COLUMNS as $indexName => $expectedColumns) {
                self::assertArrayHasKey($indexName, $indexDefinitions);
                self::assertStringContainsString('(' . implode(', ', $expectedColumns) . ')', $indexDefinitions[$indexName]);
            }

            foreach (self::TABLES as $table) {
                $connection->executeStatement(sprintf('INSERT INTO %s (market_data_venue) VALUES (NULL), (?), (?)', $table), [
                    'okx',
                    'hyperliquid',
                ]);
                self::assertSame(3, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table)));

                try {
                    $connection->executeStatement(sprintf('INSERT INTO %s (market_data_venue) VALUES (?)', $table), ['coinbase']);
                    self::fail(sprintf('%s accepted an unsupported market-data venue.', $table));
                } catch (DriverException $exception) {
                    self::assertSame('23514', $exception->getSQLState());
                }
            }

            $this->executeMigration($connection, 'down');
            self::assertSame(0, (int) $connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND column_name = 'market_data_venue'
SQL));
        } finally {
            try {
                $connection->executeStatement('SET search_path TO public');
                $connection->executeStatement('DROP SCHEMA IF EXISTS ' . $quotedSchema . ' CASCADE');
            } finally {
                $connection->close();
            }
        }
    }

    private function postgresConnectionOrSkip(): Connection
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if (!is_string($dsn) || preg_match('/^(postgres|postgresql|pdo-pgsql)/', $dsn) !== 1) {
            self::markTestSkipped('An isolated PostgreSQL DATABASE_URL is required for the market-data venue migration integration test.');
        }

        PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase($dsn);

        try {
            $connection = DriverManager::getConnection(['url' => $dsn]);
            $connection->executeQuery('SELECT 1');
            $connection->executeStatement("SET TIME ZONE 'UTC'");

            return $connection;
        } catch (\Throwable) {
            self::markTestSkipped('The isolated PostgreSQL integration environment is unreachable.');
        }
    }

    private function createMinimalTables(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE order_intent (id BIGSERIAL PRIMARY KEY, strategy_profile VARCHAR(80), created_at TIMESTAMPTZ)');
        $connection->executeStatement('CREATE TABLE trade_lineage (id BIGSERIAL PRIMARY KEY, profile VARCHAR(80), created_at TIMESTAMPTZ)');
        $connection->executeStatement('CREATE TABLE trade_lifecycle_event (id BIGSERIAL PRIMARY KEY, config_profile VARCHAR(64), happened_at TIMESTAMPTZ)');
        $connection->executeStatement('CREATE TABLE fill_cost_ledger (id BIGSERIAL PRIMARY KEY, internal_trade_id VARCHAR(96), occurred_at TIMESTAMPTZ)');
        $connection->executeStatement('CREATE TABLE trade_zone_events (id BIGSERIAL PRIMARY KEY, config_profile VARCHAR(50), happened_at TIMESTAMPTZ)');
    }

    private function executeMigration(Connection $connection, string $direction): void
    {
        foreach ($this->migrationSql($connection, $direction) as $sql) {
            $connection->executeStatement($sql);
        }
    }

    /** @return list<string> */
    private function migrationSql(Connection $connection, string $direction): array
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;

        /** @var AbstractMigration $migration */
        $migration = new Version20260719120000($connection, new NullLogger());
        $migration->{$direction}(new Schema());

        return array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        );
    }
}
