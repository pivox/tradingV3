<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CanonicalIdentityMigrationTest extends TestCase
{
    private const MIGRATION_FILE = __DIR__ . '/../../../migrations/Version20260801090000.php';

    public function testIndexesAreBuiltAndRemovedOutsideTransactionsWithoutBlockingWrites(): void
    {
        $source = file_get_contents(self::MIGRATION_FILE);

        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            '/public function isTransactional\(\): bool\s*\{\s*return false;\s*\}/',
            $source,
        );
        self::assertStringContainsString('PostgreSQLPlatform', $source);
        self::assertStringContainsString(
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS ux_trade_lineage_decision_id ON trade_lineage (decision_id) WHERE decision_id IS NOT NULL',
            $source,
        );
        self::assertStringContainsString(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_trade_lineage_canonical_contract ON trade_lineage (mode_id, mode_version, setup_id, setup_version)',
            $source,
        );
        self::assertStringContainsString(
            'DROP INDEX CONCURRENTLY IF EXISTS ux_trade_lineage_decision_id',
            $source,
        );
        self::assertStringContainsString(
            'DROP INDEX CONCURRENTLY IF EXISTS idx_trade_lineage_canonical_contract',
            $source,
        );
    }

    public function testLegacyRowsRemainNullableAndAreNotBackfilled(): void
    {
        $source = file_get_contents(self::MIGRATION_FILE);

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
