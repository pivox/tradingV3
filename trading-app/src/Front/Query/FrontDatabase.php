<?php

declare(strict_types=1);

namespace App\Front\Query;

use Doctrine\DBAL\Connection;

final class FrontDatabase
{
    /** @var array<string, bool> */
    private array $tableExists = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $tables
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(array $tables, string $sql, array $params = []): array
    {
        if (!$this->tablesExist($tables)) {
            return [];
        }

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $tables
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(array $tables, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($tables, $sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param list<string> $tables
     */
    public function tablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    public function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExists)) {
            return $this->tableExists[$table];
        }

        try {
            $tables = array_map('strtolower', $this->connection->createSchemaManager()->listTableNames());
            $this->tableExists[$table] = in_array(strtolower($table), $tables, true);
        } catch (\Throwable) {
            $this->tableExists[$table] = false;
        }

        return $this->tableExists[$table];
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
