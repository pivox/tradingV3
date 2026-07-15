<?php

declare(strict_types=1);

namespace App\Tests\Support;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostgresIntegrationDatabaseGuard::class)]
final class PostgresIntegrationDatabaseGuardTest extends TestCase
{
    public function testAcceptsDatabaseNameEndingInTest(): void
    {
        self::expectNotToPerformAssertions();

        PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase(
            'postgresql://user:password@localhost:5432/trading_app_test',
        );
    }

    public function testAcceptsDatabaseNamedTest(): void
    {
        self::expectNotToPerformAssertions();

        PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase(
            'postgresql://user:password@localhost:5432/test',
        );
    }

    public function testRejectsDatabaseWithoutTestSuffixWithoutLeakingConnectionDetails(): void
    {
        $dsn = 'postgresql://integration_user:ultra-secret@db.internal:5433/trading_app';

        try {
            PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase($dsn);
            self::fail('Expected an unsafe database name to be rejected.');
        } catch (LogicException $exception) {
            self::assertSame(
                'An isolated PostgreSQL database ending in "_test" is required.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($dsn, $exception->getMessage());
            self::assertStringNotContainsString('integration_user', $exception->getMessage());
            self::assertStringNotContainsString('ultra-secret', $exception->getMessage());
            self::assertStringNotContainsString('db.internal', $exception->getMessage());
            self::assertStringNotContainsString('5433', $exception->getMessage());
        }
    }

    public function testRejectsDsnWithoutDatabasePath(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('An isolated PostgreSQL database ending in "_test" is required.');

        PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase(
            'postgresql://user:password@localhost:5432',
        );
    }
}
