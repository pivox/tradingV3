<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260808113000;
use DoctrineMigrations\Version20260808114000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Version20260808113000::class)]
#[CoversClass(Version20260808114000::class)]
final class CanonicalLot2bMigrationTest extends TestCase
{
    public function testPositionMigrationDropsLegacyUniqueIndexBeforeCreatingCycleIndexes(): void
    {
        $sql = $this->sql(Version20260808113000::class);

        $drop = array_search('DROP INDEX IF EXISTS ux_positions_exchange_market_symbol_side', $sql, true);
        $create = array_search('CREATE UNIQUE INDEX ux_positions_exchange_market_position_cycle ON positions (exchange, market_type, canonical_exchange_position_id) WHERE canonical_exchange_position_id IS NOT NULL', $sql, true);

        self::assertIsInt($drop);
        self::assertIsInt($create);
        self::assertLessThan($create, $drop);
    }

    public function testAnalyticsMigrationRejectsCrossNetworkAndVenueClosePairs(): void
    {
        $sql = implode("\n", $this->sql(Version20260808114000::class));

        self::assertStringContainsString('c.paper_network IS DISTINCT FROM e.paper_network', $sql);
        self::assertStringContainsString('c.market_data_venue IS DISTINCT FROM e.market_data_venue', $sql);
    }

    /**
     * @param class-string<Version20260808113000|Version20260808114000> $migrationClass
     * @return list<string>
     */
    private function sql(string $migrationClass): array
    {
        $shortName = substr($migrationClass, (int) strrpos($migrationClass, '\\') + 1);
        require_once \dirname(__DIR__, 3) . '/migrations/' . $shortName . '.php';
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $migration = new $migrationClass($connection, new NullLogger());
        $migration->up(new Schema());

        return array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
    }
}
