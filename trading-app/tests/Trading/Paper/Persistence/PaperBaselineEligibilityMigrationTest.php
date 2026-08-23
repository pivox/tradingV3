<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260823190000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Version20260823190000::class)]
final class PaperBaselineEligibilityMigrationTest extends TestCase
{
    public function testMigrationAllowsOnlyReferenceOrBaselineEligibilityWithoutBackfill(): void
    {
        require_once dirname(__DIR__, 4) . '/migrations/Version20260823190000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260823190000($connection, new NullLogger());
        $migration->up(new Schema());
        $sql = implode("\n", array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        ));

        self::assertStringContainsString(
            "eligibility IN ('reference_only', 'baseline_eligible')",
            $sql,
        );
        foreach (['order_intent', 'trade_lineage', 'trade_lifecycle_event', 'fill_cost_ledger', 'trade_zone_events'] as $table) {
            self::assertStringContainsString('chk_' . $table . '_paper_eligibility', $sql);
        }
        self::assertStringNotContainsString('UPDATE ', $sql);
        self::assertStringNotContainsString('DELETE ', $sql);
    }
}
