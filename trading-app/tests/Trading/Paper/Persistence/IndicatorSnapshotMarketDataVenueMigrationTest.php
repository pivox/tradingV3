<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use App\Tests\Support\PostgresIntegrationDatabaseGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260719121000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class IndicatorSnapshotMarketDataVenueMigrationTest extends TestCase
{
    private const MIGRATION_FILE = __DIR__ . '/../../../../migrations/Version20260719121000.php';

    public function testPostgreSqlMigrationAddsConstrainedNullableVenueAndLookupIndexAndReversesCleanly(): void
    {
        $connection = $this->postgresConnectionOrSkip();
        $schemaName = sprintf('indicator_snapshot_venue_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $quotedSchema = $connection->getDatabasePlatform()->quoteSingleIdentifier($schemaName);

        try {
            $connection->executeStatement('CREATE SCHEMA ' . $quotedSchema);
            $connection->executeStatement('SET search_path TO ' . $quotedSchema . ', public');
            $connection->executeStatement(<<<'SQL'
CREATE TABLE indicator_snapshots (
    id BIGSERIAL PRIMARY KEY,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    timeframe VARCHAR(10) NOT NULL,
    kline_time TIMESTAMPTZ NOT NULL
);
CREATE UNIQUE INDEX ux_ind_snap_exchange_market_symbol_tf_time
    ON indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time)
SQL);

            $this->executeMigration($connection, 'up');

            self::assertSame(
                ['character varying', 32, 'YES'],
                array_values($connection->fetchAssociative(<<<'SQL'
SELECT data_type, character_maximum_length, is_nullable
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'indicator_snapshots'
  AND column_name = 'market_data_venue'
SQL) ?: []),
            );

            $indexDefinition = $connection->fetchOne(<<<'SQL'
SELECT indexdef
FROM pg_indexes
WHERE schemaname = current_schema()
  AND indexname = 'idx_ind_snap_paper_market_identity_time'
SQL);
            self::assertIsString($indexDefinition);
            self::assertStringContainsString(
                '(exchange, market_type, market_data_venue, symbol, timeframe, kline_time DESC, id DESC)',
                $indexDefinition,
            );

            $connection->executeStatement("INSERT INTO indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time, market_data_venue) VALUES ('fake', 'paper', 'BTCUSDT', '1m', '2026-08-24 07:00:00+00', NULL), ('fake', 'paper', 'BTCUSDT', '1m', '2026-08-24 07:00:00+00', 'okx'), ('fake', 'paper', 'BTCUSDT', '1m', '2026-08-24 07:00:00+00', 'hyperliquid')");
            self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM indicator_snapshots'));

            foreach ([null, 'okx'] as $venue) {
                try {
                    $connection->executeStatement(
                        "INSERT INTO indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time, market_data_venue) VALUES ('fake', 'paper', 'BTCUSDT', '1m', '2026-08-24 07:00:00+00', ?)",
                        [$venue],
                    );
                    self::fail('The indicator snapshot accepted a duplicate scoped identity.');
                } catch (DriverException $exception) {
                    self::assertSame('23505', $exception->getSQLState());
                }
            }

            try {
                $connection->executeStatement("INSERT INTO indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time, market_data_venue) VALUES ('fake', 'paper', 'BTCUSDT', '1m', NOW(), 'coinbase')");
                self::fail('The indicator snapshot accepted an unsupported market-data venue.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }

            $connection->executeStatement('DELETE FROM indicator_snapshots WHERE market_data_venue IS NOT NULL');
            $this->executeMigration($connection, 'down');
            self::assertFalse($connection->fetchOne(<<<'SQL'
SELECT 1
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'indicator_snapshots'
  AND column_name = 'market_data_venue'
SQL));
            self::assertIsString($connection->fetchOne(<<<'SQL'
SELECT indexdef
FROM pg_indexes
WHERE schemaname = current_schema()
  AND indexname = 'ux_ind_snap_exchange_market_symbol_tf_time'
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

    public function testMigrationReconcilesAnExistingManuallyAddedColumn(): void
    {
        $connection = $this->postgresConnectionOrSkip();
        $schemaName = sprintf('indicator_snapshot_existing_venue_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $quotedSchema = $connection->getDatabasePlatform()->quoteSingleIdentifier($schemaName);

        try {
            $connection->executeStatement('CREATE SCHEMA ' . $quotedSchema);
            $connection->executeStatement('SET search_path TO ' . $quotedSchema . ', public');
            $connection->executeStatement(<<<'SQL'
CREATE TABLE indicator_snapshots (
    id BIGSERIAL PRIMARY KEY,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    market_data_venue TEXT,
    symbol VARCHAR(50) NOT NULL,
    timeframe VARCHAR(10) NOT NULL,
    kline_time TIMESTAMPTZ NOT NULL
);
CREATE UNIQUE INDEX ux_ind_snap_exchange_market_symbol_tf_time
    ON indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time)
SQL);

            $this->executeMigration($connection, 'up');

            self::assertSame(32, (int) $connection->fetchOne(<<<'SQL'
SELECT character_maximum_length
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'indicator_snapshots'
  AND column_name = 'market_data_venue'
SQL));
            self::assertSame(2, (int) $connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM pg_indexes
WHERE schemaname = current_schema()
  AND indexname IN (
      'ux_ind_snap_exchange_market_symbol_tf_time',
      'ux_ind_snap_exchange_market_venue_symbol_tf_time'
  )
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
            self::markTestSkipped('An isolated PostgreSQL DATABASE_URL is required.');
        }

        PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase($dsn);

        return DriverManager::getConnection(['url' => $dsn]);
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
        $migration = new Version20260719121000($connection, new NullLogger());
        $migration->{$direction}(new Schema());

        return array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        );
    }
}
