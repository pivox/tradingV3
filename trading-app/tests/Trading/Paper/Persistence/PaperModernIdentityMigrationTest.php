<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260820170000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Version20260820170000::class)]
final class PaperModernIdentityMigrationTest extends TestCase
{
    public function testMigrationIsAdditiveNullableConstrainedAndHasNoBackfill(): void
    {
        require_once \dirname(__DIR__, 4) . '/migrations/Version20260820170000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260820170000($connection, new NullLogger());
        $migration->up(new Schema());
        $sql = implode("\n", array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        ));

        foreach ([
            'mode_id VARCHAR(64)',
            'mode_version VARCHAR(32)',
            'setup_id VARCHAR(160)',
            'setup_version VARCHAR(32)',
            'canonical_side VARCHAR(8)',
            'canonical_config_hash VARCHAR(71)',
            'condition_catalog_hash VARCHAR(71)',
        ] as $column) {
            self::assertStringContainsString('ADD ' . $column . ' DEFAULT NULL', $sql);
        }
        self::assertStringContainsString('chk_paper_execution_cell_modern_identity_all_or_none', $sql);
        self::assertStringContainsString('strategy_profile = mode_id', $sql);
        self::assertStringContainsString("canonical_side IN ('long', 'short')", $sql);
        self::assertStringContainsString("canonical_config_hash ~ '^sha256:[a-f0-9]{64}$'", $sql);
        self::assertStringContainsString('idx_paper_execution_cell_modern_identity', $sql);
        self::assertStringNotContainsString('UPDATE paper_execution_cell', $sql);
        self::assertStringNotContainsString('baseline_eligible', $sql);
    }
}
