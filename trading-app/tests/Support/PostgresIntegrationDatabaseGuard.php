<?php

declare(strict_types=1);

namespace App\Tests\Support;

use LogicException;
use ValueError;

final class PostgresIntegrationDatabaseGuard
{
    private const ERROR_MESSAGE = 'An isolated PostgreSQL database ending in "_test" is required.';

    public static function assertIsolatedTestDatabase(string $dsn): void
    {
        try {
            $path = parse_url($dsn, PHP_URL_PATH);
        } catch (ValueError) {
            throw new LogicException(self::ERROR_MESSAGE);
        }

        if (!is_string($path) || !str_starts_with($path, '/')) {
            throw new LogicException(self::ERROR_MESSAGE);
        }

        $databaseName = rawurldecode(substr($path, 1));

        if ($databaseName !== 'test' && !str_ends_with($databaseName, '_test')) {
            throw new LogicException(self::ERROR_MESSAGE);
        }
    }
}
