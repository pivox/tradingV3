<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class PostgresTimestampType extends Type
{
    public const POSTGRES_TIMESTAMP = 'postgres_timestamp';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMPTZ';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        // Handle PostgreSQL timestamp format
        if (is_string($value)) {
            // Remove timezone info if present and parse
            $value = preg_replace('/\+\d{2}$/', '', $value);
            return new DateTimeImmutable($value);
        }

        return null;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
    }

    public function getName(): string
    {
        return self::POSTGRES_TIMESTAMP;
    }
}




