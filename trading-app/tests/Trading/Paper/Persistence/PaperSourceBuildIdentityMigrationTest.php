<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260821030000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Version20260821030000::class)]
final class PaperSourceBuildIdentityMigrationTest extends TestCase
{
    public function testMigrationIsAdditiveNullableConstrainedAndHasNoBackfill(): void
    {
        require_once \dirname(__DIR__, 4) . '/migrations/Version20260821030000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260821030000($connection, new NullLogger());
        $migration->up(new Schema());
        $sql = implode("\n", array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        ));

        self::assertStringContainsString(
            'ADD dataset_source_build_version TEXT DEFAULT NULL',
            $sql,
        );
        self::assertStringContainsString(
            'chk_paper_execution_cell_dataset_source_build_version',
            $sql,
        );
        self::assertStringContainsString('btrim(dataset_source_build_version)', $sql);
        self::assertStringNotContainsString('UPDATE paper_execution_cell', $sql);
        self::assertStringNotContainsString('DEFAULT \'', $sql);
    }
}
