<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

// @tag:mtf-core  LECTURE & DISPATCH pour refresh-{tf}

final class RefreshTfService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SlotService $slot,
        private readonly LoggerInterface $logger,
        private readonly TemporalDispatcherInterface $dispatcher,
    ) {}

    /** Retourne et dispatch les symboles éligibles pour le TF. */
    public function refresh(string $tf, int $limit = 200, bool $excludeFreshOnCurrentSlot = true): int
    {
        $slot = $this->slot->currentSlot($tf);
        $params = ['tf' => $tf, 'slot' => $slot->format('Y-m-d H:i:s')];

        $sql = <<<SQL
SELECT e.symbol
FROM tf_eligibility e
WHERE e.tf = :tf
  AND e.status = 'ACTIVE'
  AND (e.cooldown_until IS NULL OR e.cooldown_until <= UTC_TIMESTAMP())
SQL;

        if ($excludeFreshOnCurrentSlot) {
            $sql .= <<<SQL

  AND NOT EXISTS (
      SELECT 1 FROM latest_signal_by_tf s
      WHERE s.symbol = e.symbol
        AND s.tf = :tf
        AND s.slot_start_utc = :slot
  )
SQL;
        }

        $sql .= " ORDER BY e.priority DESC, e.updated_at ASC LIMIT " . (int)$limit;

        $symbols = $this->db->fetchFirstColumn($sql, $params);
        $this->logger->debug('[pipeline] refresh symbols loaded', [
            'tf' => $tf,
            'slot' => $slot->format('Y-m-d H:i:s'),
            'exclude_fresh' => $excludeFreshOnCurrentSlot,
            'limit' => $limit,
            'symbols' => $symbols,
        ]);
        foreach ($symbols as $symbol) {
            $this->dispatcher->dispatchEvaluate($symbol, $tf, $slot, ['dedup' => sprintf('%s|%s|%s', $symbol, $tf, $slot->format('Y-m-d H:i:s'))]);
        }
        $this->logger->info('[refresh] dispatched', ['tf' => $tf, 'count' => count($symbols), 'slot' => $slot->format(DATE_ATOM)]);
        return count($symbols);
    }
}
