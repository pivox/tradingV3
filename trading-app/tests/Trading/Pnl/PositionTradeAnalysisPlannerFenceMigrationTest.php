<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PositionTradeAnalysisPlannerFenceMigrationTest extends TestCase
{
    public function testMigrationEvaluatesTheLegacyCompositeOnceAndIsReversible(): void
    {
        $path = \dirname(__DIR__, 3) . '/migrations/Version20260826120000.php';

        self::assertFileExists($path);
        $migration = file_get_contents($path);
        self::assertIsString($migration);
        self::assertStringContainsString(
            'CROSS JOIN LATERAL',
            $migration,
        );
        self::assertStringContainsString(
            "SELECT pg_get_viewdef('position_trade_analysis_v2_legacy_source'::regclass, true)",
            $migration,
        );
        self::assertStringContainsString('regexp_match(', $migration);
        self::assertStringContainsString("'old_source.'", $migration);
        self::assertStringNotContainsString('old_1', $migration);
        self::assertStringNotContainsString('AS MATERIALIZED', $migration);
        self::assertStringContainsString(
            "ELSE format('%I.%I AS %I', source_alias, attribute.attname, attribute.attname)",
            $migration,
        );
        self::assertStringContainsString(
            'original_definition:',
            $migration,
        );
        self::assertStringContainsString(
            'COMMENT ON VIEW position_trade_analysis_v2_legacy_source IS NULL',
            $migration,
        );
    }
}
