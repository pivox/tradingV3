<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Usage;

use Doctrine\DBAL\Connection;

final readonly class DoctrineEffectiveConfigUsageStore implements EffectiveConfigUsageStoreInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(EffectiveConfigUsageScope $scope, string $identifier): array
    {
        [$lineagePredicate, $intentPredicate, $eventPredicate, $parameters] = match ($scope) {
            EffectiveConfigUsageScope::RUN => [
                'orchestration_run_id = ?',
                'orchestration_run_id = ?',
                'orchestration_run_id = ?',
                [$identifier, $identifier, $identifier],
            ],
            EffectiveConfigUsageScope::SET => [
                'orchestration_set_id = ?',
                'orchestration_set_id = ?',
                'orchestration_set_id = ?',
                [$identifier, $identifier, $identifier],
            ],
            EffectiveConfigUsageScope::DECISION => [
                'decision_id = ?',
                'decision_id = ?',
                'decision_id = ?',
                [$identifier, $identifier, $identifier],
            ],
            EffectiveConfigUsageScope::TRADE => [
                'internal_trade_id = ?',
                '(trade_id = ? OR internal_trade_id = ?)',
                '(trade_id = ? OR internal_trade_id = ?)',
                [$identifier, $identifier, $identifier, $identifier, $identifier],
            ],
        };

        $sql = sprintf(<<<'SQL'
SELECT source, row_identity, config_hash, effective_config_reference,
       decision_id, trade_id, internal_trade_id
FROM (
    SELECT 'trade_lineage' AS source, id AS row_order, CAST(id AS TEXT) AS row_identity,
           config_hash, effective_config_reference, decision_id,
           NULL AS trade_id, internal_trade_id
    FROM trade_lineage
    WHERE %s
    UNION ALL
    SELECT 'order_intent' AS source, id AS row_order, CAST(id AS TEXT) AS row_identity,
           config_hash, effective_config_reference, decision_id,
           trade_id, internal_trade_id
    FROM order_intent
    WHERE %s
    UNION ALL
    SELECT 'trade_lifecycle_event' AS source, id AS row_order, CAST(id AS TEXT) AS row_identity,
           config_hash, effective_config_reference, decision_id,
           trade_id, internal_trade_id
    FROM trade_lifecycle_event
    WHERE %s
) AS usage_facts
ORDER BY source, row_order
SQL, $lineagePredicate, $intentPredicate, $eventPredicate);

        return array_map(
            static fn (array $row): EffectiveConfigUsageFact => new EffectiveConfigUsageFact(
                self::requiredString($row, 'source'),
                self::requiredString($row, 'row_identity'),
                self::nullableString($row, 'config_hash'),
                self::nullableString($row, 'effective_config_reference'),
                self::nullableString($row, 'decision_id'),
                self::nullableString($row, 'trade_id'),
                self::nullableString($row, 'internal_trade_id'),
            ),
            $this->connection->fetchAllAssociative($sql, $parameters),
        );
    }

    /** @param array<string,mixed> $row */
    private static function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new \LogicException('effective_config_usage_row_invalid');
        }

        return (string) $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \LogicException('effective_config_usage_row_invalid');
        }

        return $value;
    }
}
