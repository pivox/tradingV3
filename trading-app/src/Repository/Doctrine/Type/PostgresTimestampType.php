<?php

declare(strict_types=1);

namespace App\Repository\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Type de timestamp PostgreSQL
 * Convertit les timestamps PostgreSQL en DateTimeImmutable PHP
 */
class PostgresTimestampType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMP';
    }

    public function getName(): string
    {
        return 'postgres_timestamp';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \Doctrine\DBAL\Types\ConversionException(
                sprintf('Could not convert database value "%s" to Doctrine Type %s', $value, $this->getName())
            );
        }
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable || $value instanceof \DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        throw new \Doctrine\DBAL\Types\ConversionException(
            sprintf('Expected DateTimeImmutable or DateTime, got %s', is_object($value) ? get_class($value) : gettype($value))
        );
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
