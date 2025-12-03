<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill trade_lifecycle_event.side for ORDER_EXPIRED rows using submitted orders or futures_order side.
 */
final class Version20251209000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fill missing trade_lifecycle_event.side values for ORDER_EXPIRED events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
WITH resolved AS (
    SELECT
        target.id,
        COALESCE(sub.side, fo_map.side) AS resolved_side
    FROM trade_lifecycle_event target
    LEFT JOIN LATERAL (
        SELECT ref.side
        FROM trade_lifecycle_event ref
        WHERE ref.event_type = 'order_submitted'
          AND ref.side IS NOT NULL
          AND (
            (target.order_id IS NOT NULL AND ref.order_id = target.order_id)
            OR (target.client_order_id IS NOT NULL AND ref.client_order_id = target.client_order_id)
          )
        ORDER BY ref.happened_at DESC
        LIMIT 1
    ) sub ON TRUE
    LEFT JOIN LATERAL (
        SELECT CASE
            WHEN fo.side IN (1, 3) THEN 'BUY'
            WHEN fo.side IN (2, 4) THEN 'SELL'
            ELSE NULL
        END AS side
        FROM futures_order fo
        WHERE
            (target.order_id IS NOT NULL AND fo.order_id = target.order_id)
            OR (target.client_order_id IS NOT NULL AND fo.client_order_id = target.client_order_id)
        ORDER BY fo.updated_at DESC NULLS LAST
        LIMIT 1
    ) fo_map ON TRUE
    WHERE target.event_type = 'order_expired'
      AND target.side IS NULL
)
UPDATE trade_lifecycle_event t
SET side = resolved.resolved_side
FROM resolved
WHERE resolved.id = t.id
  AND resolved.resolved_side IS NOT NULL;
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
UPDATE trade_lifecycle_event
SET side = NULL
WHERE event_type = 'order_expired';
SQL);
    }
}
