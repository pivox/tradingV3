<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260820000000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Version20260820000000::class)]
final class FillTimingMigrationTest extends TestCase
{
    public function testMigrationUsesExactFillChronologyAndFailsClosed(): void
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version20260820000000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260820000000($connection, new NullLogger());

        $migration->up(new Schema());
        $sql = implode("\n", array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        ));

        self::assertStringContainsString('old.exit_last_fill_at - old.entry_first_fill_at', $sql);
        self::assertStringContainsString("'fill_cost_ledger_v1'", $sql);
        self::assertStringContainsString('ledger_fill_chronology_invalid', $sql);
        self::assertStringContainsString("'net_pnl_usdt', CASE WHEN timing.chronology_valid IS FALSE THEN NULL", $sql);
        self::assertStringContainsString("'mfe_mae_window_source'", $sql);
        self::assertDoesNotMatchRegularExpression('/\\?(?:[|&])?/', $sql);
    }
}
