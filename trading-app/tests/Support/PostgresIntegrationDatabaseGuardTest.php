<?php

declare(strict_types=1);

namespace App\Tests\Support;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;

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

    #[DataProvider('unsafeDbnameOverrides')]
    public function testRejectsUnsafeDbnameQueryOverride(string $query): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('An isolated PostgreSQL database ending in "_test" is required.');

        PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase(
            'postgresql://user:password@localhost:5432/safe_test?' . $query,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeDbnameOverrides(): iterable
    {
        yield 'production database' => ['dbname=production'];
        yield 'empty database' => ['dbname='];
        yield 'array database value' => ['dbname[]=production'];
    }

    public function testConvertsParsingErrorsWithoutRetainingPreviousException(): void
    {
        try {
            PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase(
                'postgresql://user:parse-secret@localhost:invalid/safe_test',
            );
            self::fail('Expected a malformed DSN to be rejected.');
        } catch (LogicException $exception) {
            self::assertSame(
                'An isolated PostgreSQL database ending in "_test" is required.',
                $exception->getMessage(),
            );
            self::assertNull($exception->getPrevious());
        }
    }

    public function testRedactsDsnFromExceptionTrace(): void
    {
        $dsn = 'postgresql://trace_user:trace-secret@trace.internal:5439/production';
        $previousSetting = ini_get('zend.exception_ignore_args');
        self::assertIsString($previousSetting);

        try {
            self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'));

            try {
                PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase($dsn);
                self::fail('Expected an unsafe database name to be rejected.');
            } catch (LogicException $exception) {
                $guardFrame = null;

                foreach ($exception->getTrace() as $frame) {
                    if (($frame['class'] ?? null) === PostgresIntegrationDatabaseGuard::class
                        && $frame['function'] === 'assertIsolatedTestDatabase') {
                        $guardFrame = $frame;
                        break;
                    }
                }

                self::assertNotNull($guardFrame);
                self::assertInstanceOf(SensitiveParameterValue::class, $guardFrame['args'][0] ?? null);

                $traceDump = print_r($exception->getTrace(), true);
                self::assertStringNotContainsString($dsn, $traceDump);
                self::assertStringNotContainsString('trace-secret', $traceDump);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousSetting);
        }
    }
}
