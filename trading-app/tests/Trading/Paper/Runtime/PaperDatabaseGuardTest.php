<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Runtime;

use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperDatabaseGuard::class)]
#[CoversClass(PaperDatabaseInspection::class)]
final class PaperDatabaseGuardTest extends TestCase
{
    #[DataProvider('readyDatabaseProvider')]
    public function testReadyPaperDatabaseIsAccepted(string $databaseName, string $environment): void
    {
        $guard = new PaperDatabaseGuard($this->inspector($databaseName));

        $guard->assertReady($environment);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function readyDatabaseProvider(): iterable
    {
        yield 'canonical database in production' => ['trading_paper', 'prod'];
        yield 'canonical database in development' => ['trading_paper', 'dev'];
        yield 'canonical database in tests' => ['trading_paper', 'test'];
        yield 'isolated test database' => ['feature_paper_test', 'test'];
    }

    #[DataProvider('unallowlistedDatabaseProvider')]
    public function testUnallowlistedDatabaseFailsWithStableReason(string $databaseName, string $environment): void
    {
        $guard = new PaperDatabaseGuard($this->inspector($databaseName));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_database_not_allowlisted');

        $guard->assertReady($environment);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unallowlistedDatabaseProvider(): iterable
    {
        yield 'application database' => ['trading_app', 'test'];
        yield 'empty connected name' => ['', 'test'];
        yield 'test suffix outside tests' => ['trading_paper_test', 'prod'];
        yield 'arbitrary paper name' => ['feature_paper', 'test'];
    }

    public function testPendingMigrationsFailWithStableReason(): void
    {
        $guard = new PaperDatabaseGuard($this->inspector('trading_paper', pendingMigrations: 2));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_database_migrations_pending');

        $guard->assertReady('prod');
    }

    public function testNegativePendingMigrationCountIsRejectedAtTheBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_database_pending_migrations_invalid');

        new PaperDatabaseInspection('trading_paper', -1);
    }

    public function testFailureDoesNotExposeConnectedDatabaseName(): void
    {
        $databaseName = 'customer_secret_database';

        try {
            (new PaperDatabaseGuard($this->inspector($databaseName)))->assertReady('prod');
            self::fail('Unallowlisted database must be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_database_not_allowlisted', $exception->getMessage());
            self::assertStringNotContainsString($databaseName, $exception->getMessage());
        }
    }

    private function inspector(string $databaseName, int $pendingMigrations = 0): PaperDatabaseInspectorInterface
    {
        $inspector = $this->createMock(PaperDatabaseInspectorInterface::class);
        $inspector->expects(self::once())
            ->method('inspect')
            ->willReturn(new PaperDatabaseInspection($databaseName, $pendingMigrations));

        return $inspector;
    }
}
