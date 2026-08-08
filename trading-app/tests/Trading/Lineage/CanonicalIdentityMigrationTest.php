<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260801093000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class CanonicalIdentityMigrationTest extends TestCase
{
    private const COLUMN_MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260801090000.php';
    private const INDEX_MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260801093000.php';
    private const ORDER_INTENT_MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260801100000.php';
    private const LIFECYCLE_MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260801101000.php';

    public function testColumnChangesRemainAtomicAndContainNoConcurrentIndexDdl(): void
    {
        $source = file_get_contents(self::COLUMN_MIGRATION_FILE);

        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            '/public function isTransactional\(\): bool\s*\{\s*return true;\s*\}/',
            $source,
        );
        self::assertStringNotContainsString('CREATE INDEX', $source);
        self::assertStringNotContainsString('CREATE UNIQUE INDEX', $source);
        self::assertStringNotContainsString('DROP INDEX', $source);
        self::assertStringNotContainsString('CONCURRENTLY', $source);
    }

    public function testLaterMigrationRebuildsIndexesOutsideTransactionsForSafeRecovery(): void
    {
        self::assertFileExists(self::INDEX_MIGRATION_FILE);
        self::assertLessThan(0, strcmp(basename(self::COLUMN_MIGRATION_FILE), basename(self::INDEX_MIGRATION_FILE)));
        $source = file_get_contents(self::INDEX_MIGRATION_FILE);

        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            '/public function isTransactional\(\): bool\s*\{\s*return false;\s*\}/',
            $source,
        );
        self::assertStringContainsString('PostgreSQLPlatform', $source);

        $uniqueDrop = 'DROP INDEX CONCURRENTLY IF EXISTS ux_trade_lineage_decision_id';
        $uniqueCreate = 'CREATE UNIQUE INDEX CONCURRENTLY ux_trade_lineage_decision_id ON trade_lineage (decision_id) WHERE decision_id IS NOT NULL';
        $contractDrop = 'DROP INDEX CONCURRENTLY IF EXISTS idx_trade_lineage_canonical_contract';
        $contractCreate = 'CREATE INDEX CONCURRENTLY idx_trade_lineage_canonical_contract ON trade_lineage (mode_id, mode_version, setup_id, setup_version)';

        self::assertStringContainsString($uniqueDrop, $source);
        self::assertStringContainsString($uniqueCreate, $source);
        self::assertStringContainsString($contractDrop, $source);
        self::assertStringContainsString($contractCreate, $source);
        self::assertStringContainsString('pg_catalog.pg_index', $source);
        self::assertStringContainsString('pg_catalog.pg_get_indexdef', $source);
        self::assertStringContainsString('$this->ensureIndex(self::UNIQUE_INDEX', $source);
        self::assertStringContainsString('$this->ensureIndex(self::CONTRACT_INDEX', $source);
        self::assertStringNotContainsString('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS', $source);
        self::assertStringNotContainsString('CREATE INDEX CONCURRENTLY IF NOT EXISTS', $source);
    }

    public function testLegacyRowsRemainNullableAndAreNotBackfilled(): void
    {
        $source = file_get_contents(self::COLUMN_MIGRATION_FILE);

        self::assertIsString($source);
        foreach (
            [
                'condition_catalog_hash',
                'mode_id',
                'mode_version',
                'setup_id',
                'setup_version',
                'decision_id',
                'decision_key',
                'effective_config_reference',
            ] as $column
        ) {
            self::assertMatchesRegularExpression(
                sprintf('/ADD %s VARCHAR\([0-9]+\) DEFAULT NULL/', $column),
                $source,
                $column,
            );
        }
        self::assertStringNotContainsString('UPDATE trade_lineage', $source);
    }

    public function testEffectiveConfigSnapshotsUsePostgreSqlJsonbLikeTheirOrmMappings(): void
    {
        foreach (
            [
                self::ORDER_INTENT_MIGRATION_FILE => 'order_intent',
                self::LIFECYCLE_MIGRATION_FILE => 'trade_lifecycle_event',
            ] as $file => $table
        ) {
            $source = file_get_contents($file);

            self::assertIsString($source);
            self::assertStringContainsString(
                sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS effective_config_snapshot JSONB DEFAULT NULL', $table),
                $source,
            );
        }
    }

    public function testAbsentIndexesAreCreatedWithoutDrop(): void
    {
        $migration = $this->indexMigration([]);

        $migration->up(new Schema());

        self::assertSame(
            [
                'CREATE UNIQUE INDEX CONCURRENTLY ux_trade_lineage_decision_id ON trade_lineage (decision_id) WHERE decision_id IS NOT NULL',
                'CREATE INDEX CONCURRENTLY idx_trade_lineage_canonical_contract ON trade_lineage (mode_id, mode_version, setup_id, setup_version)',
            ],
            $this->plannedSql($migration),
        );
    }

    public function testInvalidIndexIsDroppedThenRecreatedWhileValidMatchingIndexIsPreserved(): void
    {
        $migration = $this->indexMigration([
            'ux_trade_lineage_decision_id' => [
                'is_valid' => 'f',
                'definition' => 'CREATE UNIQUE INDEX ux_trade_lineage_decision_id ON public.trade_lineage USING btree (decision_id) WHERE (decision_id IS NOT NULL)',
            ],
            'idx_trade_lineage_canonical_contract' => [
                'is_valid' => 't',
                'definition' => 'CREATE INDEX "idx_trade_lineage_canonical_contract" ON "paper_test"."trade_lineage" USING btree (mode_id, mode_version, setup_id, setup_version)',
            ],
        ]);

        $migration->up(new Schema());

        self::assertSame(
            [
                'DROP INDEX CONCURRENTLY IF EXISTS ux_trade_lineage_decision_id',
                'CREATE UNIQUE INDEX CONCURRENTLY ux_trade_lineage_decision_id ON trade_lineage (decision_id) WHERE decision_id IS NOT NULL',
            ],
            $this->plannedSql($migration),
        );
    }

    public function testValidExactIndexesAreNoOpDespitePostgreSqlFormatting(): void
    {
        $migration = $this->indexMigration([
            'ux_trade_lineage_decision_id' => [
                'is_valid' => true,
                'definition' => 'CREATE UNIQUE INDEX "ux_trade_lineage_decision_id" ON public.trade_lineage USING btree (decision_id) WHERE (decision_id IS NOT NULL)',
            ],
            'idx_trade_lineage_canonical_contract' => [
                'is_valid' => 1,
                'definition' => 'CREATE INDEX idx_trade_lineage_canonical_contract ON public.trade_lineage USING btree (mode_id, mode_version, setup_id, setup_version)',
            ],
        ]);

        $migration->up(new Schema());

        self::assertSame([], $this->plannedSql($migration));
    }

    public function testValidMismatchedUniqueIndexFailsClosedWithoutDrop(): void
    {
        $migration = $this->indexMigration([
            'ux_trade_lineage_decision_id' => [
                'is_valid' => 't',
                'definition' => 'CREATE INDEX ux_trade_lineage_decision_id ON public.trade_lineage USING btree (decision_id)',
            ],
        ]);

        try {
            $migration->up(new Schema());
            self::fail('A valid index with a mismatched definition must require manual intervention.');
        } catch (AbortMigration $exception) {
            self::assertStringContainsString('ux_trade_lineage_decision_id', $exception->getMessage());
            self::assertStringContainsString('manual intervention required', $exception->getMessage());
        }

        self::assertSame([], $this->plannedSql($migration));
    }

    /**
     * @param array<string, array{is_valid: bool|int|string, definition: string}> $states
     */
    private function indexMigration(array $states): Version20260801093000
    {
        require_once self::INDEX_MIGRATION_FILE;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($states): array|false {
                self::assertStringContainsString('pg_catalog.pg_index', $sql);
                self::assertStringContainsString('pg_get_indexdef', $sql);
                self::assertArrayHasKey('index_name', $params);

                return $states[$params['index_name']] ?? false;
            },
        );

        return new Version20260801093000($connection, new NullLogger());
    }

    /** @return list<string> */
    private function plannedSql(Version20260801093000 $migration): array
    {
        return array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        );
    }
}
