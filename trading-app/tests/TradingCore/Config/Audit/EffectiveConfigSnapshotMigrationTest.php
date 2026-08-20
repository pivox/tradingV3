<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Audit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260820150000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class EffectiveConfigSnapshotMigrationTest extends TestCase
{
    private const MIGRATION_FILE = __DIR__ . '/../../../../migrations/Version20260820150000.php';

    public function testMigrationDefinesConstrainedAppendOnlyRegistry(): void
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;

        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $migration = new Version20260820150000($connection, new NullLogger());
        $migration->up(new Schema());
        $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));

        self::assertStringContainsString('CREATE TABLE effective_trading_config_snapshot', $sql);
        self::assertStringContainsString("snapshot_hash ~ '^sha256:[a-f0-9]{64}$'", $sql);
        self::assertStringContainsString("validation_status IN ('valid')", $sql);
        self::assertStringContainsString("jsonb_typeof(redacted_snapshot) = 'object'", $sql);
        self::assertStringContainsString('idx_effective_config_snapshot_config_hash', $sql);
        self::assertStringContainsString('idx_effective_config_snapshot_identity', $sql);
        self::assertStringContainsString('effective_trading_config_snapshot_append_only', $sql);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE', $sql);
    }
}
