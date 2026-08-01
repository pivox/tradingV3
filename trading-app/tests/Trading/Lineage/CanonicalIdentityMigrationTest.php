<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CanonicalIdentityMigrationTest extends TestCase
{
    private const COLUMN_MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260801090000.php';
    private const INDEX_MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260801093000.php';

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
        self::assertLessThan(strpos($source, $uniqueCreate), strpos($source, $uniqueDrop));
        self::assertLessThan(strpos($source, $contractCreate), strpos($source, $contractDrop));
        self::assertSame(2, substr_count($source, $uniqueDrop));
        self::assertSame(2, substr_count($source, $contractDrop));
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
}
