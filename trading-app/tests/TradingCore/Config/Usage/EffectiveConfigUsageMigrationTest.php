<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Usage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260820160000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class EffectiveConfigUsageMigrationTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../../migrations/Version20260820160000.php';
    private const INDEXES = [
        'idx_trade_lineage_effective_usage_run' => 'CREATE INDEX CONCURRENTLY idx_trade_lineage_effective_usage_run ON trade_lineage (orchestration_run_id) WHERE orchestration_run_id IS NOT NULL',
        'idx_trade_lineage_effective_usage_set' => 'CREATE INDEX CONCURRENTLY idx_trade_lineage_effective_usage_set ON trade_lineage (orchestration_set_id) WHERE orchestration_set_id IS NOT NULL',
        'idx_order_intent_effective_usage_set' => 'CREATE INDEX CONCURRENTLY idx_order_intent_effective_usage_set ON order_intent (orchestration_set_id) WHERE orchestration_set_id IS NOT NULL',
        'idx_tle_effective_usage_set' => 'CREATE INDEX CONCURRENTLY idx_tle_effective_usage_set ON trade_lifecycle_event (orchestration_set_id) WHERE orchestration_set_id IS NOT NULL',
        'idx_order_intent_effective_usage_decision' => 'CREATE INDEX CONCURRENTLY idx_order_intent_effective_usage_decision ON order_intent (decision_id) WHERE decision_id IS NOT NULL',
        'idx_tle_effective_usage_decision' => 'CREATE INDEX CONCURRENTLY idx_tle_effective_usage_decision ON trade_lifecycle_event (decision_id) WHERE decision_id IS NOT NULL',
        'idx_order_intent_effective_usage_trade' => 'CREATE INDEX CONCURRENTLY idx_order_intent_effective_usage_trade ON order_intent (trade_id) WHERE trade_id IS NOT NULL',
        'idx_tle_effective_usage_trade' => 'CREATE INDEX CONCURRENTLY idx_tle_effective_usage_trade ON trade_lifecycle_event (trade_id) WHERE trade_id IS NOT NULL',
    ];

    public function testMigrationUsesRecoverableConcurrentPostgresDdl(): void
    {
        self::assertFileExists(self::FILE);
        $source = file_get_contents(self::FILE);
        self::assertIsString($source);
        self::assertMatchesRegularExpression('/public function isTransactional\(\): bool\s*\{\s*return false;\s*\}/', $source);
        self::assertStringContainsString('PostgreSQLPlatform', $source);
        self::assertStringContainsString('pg_catalog.pg_index', $source);
        self::assertStringContainsString('pg_catalog.pg_get_indexdef', $source);
        self::assertStringNotContainsString('CONCURRENTLY IF NOT EXISTS', $source);
        foreach (self::INDEXES as $name => $create) {
            self::assertStringContainsString($create, $source);
            self::assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS ' . $name, $source);
        }
    }

    public function testAbsentIndexesAreAllCreated(): void
    {
        $migration = $this->migration([]);

        $migration->up(new Schema());

        self::assertSame(array_values(self::INDEXES), $this->plannedSql($migration));
    }

    public function testInvalidIndexIsDroppedAndRecreatedWhileExactIndexesArePreserved(): void
    {
        $states = [];
        foreach (self::INDEXES as $name => $definition) {
            $states[$name] = ['is_valid' => true, 'definition' => str_replace(' CONCURRENTLY', '', $definition)];
        }
        $first = (string) array_key_first(self::INDEXES);
        $states[$first]['is_valid'] = false;
        $migration = $this->migration($states);

        $migration->up(new Schema());

        self::assertSame([
            'DROP INDEX CONCURRENTLY IF EXISTS ' . $first,
            self::INDEXES[$first],
        ], $this->plannedSql($migration));
    }

    public function testValidMismatchedIndexAbortsWithoutDroppingIt(): void
    {
        $first = (string) array_key_first(self::INDEXES);
        $migration = $this->migration([
            $first => [
                'is_valid' => true,
                'definition' => 'CREATE INDEX ' . $first . ' ON trade_lineage (config_hash)',
            ],
        ]);

        try {
            $migration->up(new Schema());
            self::fail('A valid mismatched index must require manual intervention.');
        } catch (AbortMigration $exception) {
            self::assertStringContainsString($first, $exception->getMessage());
            self::assertStringContainsString('manual intervention required', $exception->getMessage());
        }
        self::assertSame([], $this->plannedSql($migration));
    }

    /** @param array<string,array{is_valid:bool|int|string,definition:string}> $states */
    private function migration(array $states): Version20260820160000
    {
        require_once self::FILE;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($states): array|false {
                self::assertStringContainsString('pg_catalog.pg_index', $sql);
                self::assertArrayHasKey('index_name', $params);
                self::assertArrayHasKey('table_name', $params);

                return $states[$params['index_name']] ?? false;
            },
        );

        return new Version20260820160000($connection, new NullLogger());
    }

    /** @return list<string> */
    private function plannedSql(Version20260820160000 $migration): array
    {
        return array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
    }
}
