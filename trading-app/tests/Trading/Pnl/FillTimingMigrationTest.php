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
    public function testMigrationWidensLedgerFillTimestampBeforeRecreatingTheView(): void
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version20260820000000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260820000000($connection, new NullLogger());

        $migration->up(new Schema());
        $up = array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        );

        self::assertSame('DROP VIEW position_trade_analysis_v2', $up[0] ?? null);
        $upPrecision = $up[1] ?? '';
        self::assertStringContainsString('DROP VIEW position_trade_analysis_v2_legacy_source', $upPrecision);
        self::assertStringContainsString('DROP VIEW position_trade_ledger_aggregate_v1', $upPrecision);
        self::assertStringContainsString(
            'ALTER TABLE fill_cost_ledger ALTER COLUMN occurred_at TYPE TIMESTAMP(6) WITH TIME ZONE',
            $upPrecision,
        );
        self::assertStringContainsString('CREATE VIEW position_trade_ledger_aggregate_v1 AS ', $upPrecision);
        self::assertStringContainsString('CREATE VIEW position_trade_analysis_v2_legacy_source AS ', $upPrecision);

        $migration = new Version20260820000000($connection, new NullLogger());
        $migration->down(new Schema());
        $down = array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        );

        self::assertSame('DROP VIEW IF EXISTS position_trade_analysis_v2', $down[0] ?? null);
        $downPrecision = $down[1] ?? '';
        self::assertStringContainsString('DROP VIEW position_trade_analysis_v2_legacy_source', $downPrecision);
        self::assertStringContainsString('DROP VIEW position_trade_ledger_aggregate_v1', $downPrecision);
        self::assertStringContainsString(
            'ALTER TABLE fill_cost_ledger ALTER COLUMN occurred_at TYPE TIMESTAMP(0) WITH TIME ZONE',
            $downPrecision,
        );
        self::assertStringContainsString('CREATE VIEW position_trade_ledger_aggregate_v1 AS ', $downPrecision);
        self::assertStringContainsString('CREATE VIEW position_trade_analysis_v2_legacy_source AS ', $downPrecision);
    }

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

        self::assertStringContainsString('legacy.exit_last_fill_at - legacy.entry_first_fill_at', $sql);
        self::assertStringContainsString("'fill_cost_ledger_v1'", $sql);
        self::assertStringContainsString('ledger_fill_chronology_invalid', $sql);
        self::assertStringContainsString("identity.lineage_classification = 'canonical' AND legacy.quantity_status = 'complete'", $sql);
        self::assertStringContainsString('AS canonical_holding_time_sec', $sql);
        self::assertStringContainsString('AS mfe_mae_window_source', $sql);
        self::assertStringContainsString("c.extra->> 'mfe_mae_entry_price'", $sql);
        self::assertStringContainsString('legacy.entry_vwap', $sql);
        self::assertStringContainsString('legacy.entry_last_fill_at <= legacy.exit_last_fill_at', $sql);
        self::assertStringContainsString("ELSE 'incomplete_fill_ledger'", $sql);
        self::assertStringNotContainsString('ELSE legacy.holding_time_sec', $sql);
        self::assertStringNotContainsString('jsonb_populate_record', $sql);
        self::assertStringNotContainsString('timing.', $sql);
        self::assertDoesNotMatchRegularExpression('/\\?(?:[|&])?/', $sql);
    }
}
