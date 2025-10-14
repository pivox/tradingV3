<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class MtfEligibilityFinder
{
    public function __construct(
        private readonly Connection $db,
        private readonly SlotService $slotService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return string[]
     */
    public function eligibleSymbols(
        string $tf,
        int $limit = 200,
        bool $excludeFreshOnSlot = true,
        bool $includeCooldownReady = false,
        bool $ignoreCooldownDeadline = false
    ): array
    {
        $slot = $this->slotService->currentSlot($tf);
        $params = [
            'tf' => $tf,
            'slot' => $slot->format('Y-m-d H:i:s'),
            'include_cooldown' => 1,
           # 'include_cooldown' => $includeCooldownReady ? 1 : 0,
        ];
        $sql = <<<SQL
SELECT e.symbol
FROM tf_eligibility e
WHERE e.tf = :tf
  AND (
        e.status = 'ACTIVE'
        OR (:include_cooldown = 1 AND e.status = 'COOLDOWN')
      )
SQL;
        if (!$ignoreCooldownDeadline) {
            $sql .= "\n  AND (e.cooldown_until IS NULL OR e.cooldown_until <= UTC_TIMESTAMP())";
        }
        if ($excludeFreshOnSlot) {
            $sql .= <<<SQL
  AND NOT EXISTS (
      SELECT 1 FROM latest_signal_by_tf s
      WHERE s.symbol = e.symbol
        AND s.tf = :tf
        AND s.slot_start_utc = :slot
  )
SQL;
        }
        $sql .= ' ORDER BY e.priority DESC, e.updated_at ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
       // dd($sql, $params);
        $symbols = $this->db->fetchFirstColumn($sql, $params);
        $this->logger->debug('[pipeline] eligibleSymbols computed', [
            'tf' => $tf,
            'limit' => $limit,
            'excludeFreshOnSlot' => $excludeFreshOnSlot,
            'includeCooldown' => $includeCooldownReady,
            'ignoreCooldownDeadline' => $ignoreCooldownDeadline,
            'result_count' => count($symbols),
            'symbols' => $symbols,
        ]);
        return $symbols;
    }

    /**
     * Returns symbols considered stale on parent TFs compared to current slot.
     * @return array<string,string[]>
     */
    public function staleParentsForSymbols(array $symbols, string $currentTf): array
    {
        if ($symbols === []) {
            return [];
        }
        $ordered = ['4h','1h','15m','5m','1m'];
        $index = array_search($currentTf, $ordered, true);
        if ($index === false || $index === 0) {
            return [];
        }
        $parents = array_slice($ordered, 0, $index);
        $result = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($parents as $parentTf) {
            $slot = $this->slotService->currentSlot($parentTf, $now);
            $sql = <<<SQL
SELECT e.symbol
FROM tf_eligibility e
LEFT JOIN latest_signal_by_tf s ON s.symbol = e.symbol AND s.tf = :parent_tf
WHERE e.symbol IN (:symbols)
  AND e.tf = :parent_tf
  AND (s.slot_start_utc IS NULL OR s.slot_start_utc < :slot)
SQL;
            $rows = $this->db->fetchFirstColumn($sql, [
                'symbols' => $symbols,
                'parent_tf' => $parentTf,
                'slot' => $slot->format('Y-m-d H:i:s'),
            ], [
                'symbols' => ArrayParameterType::STRING,
            ]);
            if ($rows) {
                $result[$parentTf] = $rows;
            }
        }
        if ($result !== []) {
            $this->logger->debug('[pipeline] staleParentsForSymbols detected', [
                'current_tf' => $currentTf,
                'parents' => array_keys($result),
                'symbols' => $result,
            ]);
        }
        return $result;
    }
}
