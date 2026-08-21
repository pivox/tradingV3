<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260821020000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version20260821020000::class)]
final class PaperCanonicalOrderIntentQuantityMigrationTest extends TestCase
{
    public function testMigrationPreservesFractionalCanonicalQuantityAndRefusesLossyRollback(): void
    {
        require_once \dirname(__DIR__, 4) . '/migrations/Version20260821020000.php';
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $migration = new Version20260821020000($connection, new \Psr\Log\NullLogger());

        $migration->up(new Schema());
        $upSql = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
        self::assertContains(
            'ALTER TABLE order_intent ALTER COLUMN size TYPE NUMERIC(36, 18) USING size::numeric',
            $upSql,
        );
        self::assertContains(
            'ALTER TABLE order_intent ALTER COLUMN price TYPE NUMERIC(65, 30) USING price::numeric',
            $upSql,
        );
        self::assertContains(
            'ALTER TABLE order_protection ALTER COLUMN price TYPE NUMERIC(65, 30) USING price::numeric',
            $upSql,
        );

        $migration = new Version20260821020000($connection, new \Psr\Log\NullLogger());
        $migration->down(new Schema());
        $downSql = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
        self::assertStringContainsString('size <> trunc(size)', $downSql[0]);
        self::assertStringContainsString('round(price, 12)', $downSql[0]);
        self::assertContains(
            'ALTER TABLE order_intent ALTER COLUMN size TYPE INTEGER USING size::integer',
            $downSql,
        );
        self::assertContains(
            'ALTER TABLE order_intent ALTER COLUMN price TYPE NUMERIC(24, 12) USING price::numeric',
            $downSql,
        );
        self::assertContains(
            'ALTER TABLE order_protection ALTER COLUMN price TYPE NUMERIC(24, 12) USING price::numeric',
            $downSql,
        );
    }
}
