<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Tools\DsnParser;
use LogicException;
use SensitiveParameter;
use Throwable;

final class PostgresIntegrationDatabaseGuard
{
    private const ERROR_MESSAGE = 'An isolated PostgreSQL database named "test" or ending in "_test" is required.';

    public static function assertIsolatedTestDatabase(
        #[SensitiveParameter]
        string $dsn,
    ): void {
        try {
            $params = (new DsnParser())->parse($dsn);
        } catch (Throwable) {
            throw new LogicException(self::ERROR_MESSAGE);
        }

        $databaseName = $params['dbname'] ?? null;

        if (!is_string($databaseName)
            || ($databaseName !== 'test' && !str_ends_with($databaseName, '_test'))) {
            throw new LogicException(self::ERROR_MESSAGE);
        }
    }
}
