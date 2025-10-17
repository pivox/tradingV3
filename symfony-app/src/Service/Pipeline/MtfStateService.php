<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class MtfStateService
{
    /** @var string[] */
    private const DEFAULT_TIMEFRAMES = ['4h','1h','15m','5m','1m'];

    public function __construct(
        private readonly Connection $db,
        private readonly SlotService $slotService,
        private readonly LoggerInterface $logger,
    ) {}

    public function ensureSeeded(string $symbol): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach (self::DEFAULT_TIMEFRAMES as $tf) {
            $status = $tf === '4h' ? 'ACTIVE' : 'COOLDOWN';
            $priority = $tf === '4h' ? 100 : 0;
            $this->db->executeStatement(
                "INSERT INTO tf_eligibility(symbol, tf, status, priority, cooldown_until, reason, updated_at)
                 VALUES (:symbol, :tf, :status, :priority, NULL, 'seeded', :updated)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), priority = VALUES(priority), reason = VALUES(reason), updated_at = VALUES(updated_at)",
                [
                    'symbol' => $symbol,
                    'tf' => $tf,
                    'status' => $status,
                    'priority' => $priority,
                    'updated' => $now->format('Y-m-d H:i:s'),
                ]
            );

            $this->db->executeStatement(
                "INSERT INTO tf_retry_status(symbol, tf, retry_count, last_result, updated_at)
                 VALUES (:symbol, :tf, 0, 'NONE', :updated)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                [
                    'symbol' => $symbol,
                    'tf' => $tf,
                    'updated' => $now->format('Y-m-d H:i:s'),
                ]
            );
        }
    }

    public function applyPositionOpened(string $eventId, string $symbol, array $executionTfs = ['1m','5m','15m']): bool
    {
        if (!$this->guardEvent($eventId, 'POSITION_OPENED')) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($executionTfs as $tf) {
            $this->updateEligibility($symbol, $tf, 'LOCKED_POSITION', 0, null, 'position_opened', $now);
        }
        return true;
    }

    public function applyPositionClosed(string $eventId, string $symbol, array $executionTfs = ['1m','5m'], ?string $parentTf = null): bool
    {
        if (!$this->guardEvent($eventId, 'POSITION_CLOSED')) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cooldownUntil = $now->add(new DateInterval('PT10M'));
        foreach ($executionTfs as $tf) {
            $this->updateEligibility($symbol, $tf, 'COOLDOWN', 0, $cooldownUntil, 'position_closed_cooldown', $now);
            if ($parentTf === null) {
                $parentTf = $this->slotService->parentOf($tf);
            }
        }
        if ($parentTf) {
            $this->updateEligibility($symbol, $parentTf, 'ACTIVE', 100, null, 'position_closed_promote_tf', $now);
        }
        return true;
    }

    public function applyOrderPlaced(string $eventId, string $symbol, array $executionTfs = ['1m','5m']): bool
    {
        if (!$this->guardEvent($eventId, 'ORDER_PLACED')) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($executionTfs as $tf) {
            $this->updateEligibility($symbol, $tf, 'LOCKED_ORDER', 0, null, 'order_placed', $now);
        }
        return true;
    }

    public function applyOrderCanceled(string $eventId, string $symbol, array $executionTfs = ['1m','5m']): bool
    {
        if (!$this->guardEvent($eventId, 'ORDER_CANCELED')) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cooldownUntil = $now->add(new DateInterval('PT5M'));
        $parentTargets = [];
        foreach ($executionTfs as $tf) {
            $this->updateEligibility($symbol, $tf, 'COOLDOWN', 0, $cooldownUntil, 'order_canceled', $now);
            if ($parent = $this->slotService->parentOf($tf)) {
                $parentTargets[$parent] = true;
            }
        }
        foreach (array_keys($parentTargets) as $parentTf) {
            $this->updateEligibility($symbol, $parentTf, 'ACTIVE', 100, null, 'order_canceled_promote_tf', $now);
        }
        return true;
    }

    public function recordOrder(string $orderId, string $symbol, string $intent, ?string $dedupKey = null): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->db->executeStatement(
            "INSERT INTO outgoing_orders(order_id, symbol, intent, created_at, dedup_key)
             VALUES (:order_id, :symbol, :intent, :created_at, :dedup)
             ON DUPLICATE KEY UPDATE symbol = VALUES(symbol), intent = VALUES(intent), dedup_key = VALUES(dedup_key), created_at = VALUES(created_at)",
            [
                'order_id' => $orderId,
                'symbol' => $symbol,
                'intent' => strtoupper($intent),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'dedup' => $dedupKey,
            ]
        );
    }

    public function removeOrder(string $orderId): void
    {
        $this->db->executeStatement(
            'DELETE FROM outgoing_orders WHERE order_id = :order_id',
            ['order_id' => $orderId]
        );
    }

    private function updateEligibility(string $symbol, string $tf, string $status, int $priority, ?DateTimeImmutable $cooldownUntil, string $reason, DateTimeImmutable $updatedAt): void
    {
        $cooldownUntil = $this->normalizeCooldown($cooldownUntil);
        $params = [
            'symbol' => $symbol,
            'tf' => $tf,
            'status' => $status,
            'priority' => $priority,
            'reason' => $reason,
            'updated' => $updatedAt->format('Y-m-d H:i:s'),
        ];
        $sql = "INSERT INTO tf_eligibility(symbol, tf, status, priority, cooldown_until, reason, updated_at)
                VALUES (:symbol, :tf, :status, :priority, :cooldown, :reason, :updated)
                ON DUPLICATE KEY UPDATE status = VALUES(status), priority = VALUES(priority), cooldown_until = VALUES(cooldown_until), reason = VALUES(reason), updated_at = VALUES(updated_at)";
        $params['cooldown'] = $cooldownUntil?->format('Y-m-d H:i:s');
        $this->db->executeStatement($sql, $params);
        $this->logger->debug('[pipeline] eligibility updated', [
            'symbol' => $symbol,
            'tf' => $tf,
            'status' => $status,
            'priority' => $priority,
            'cooldown_until' => $params['cooldown'],
            'reason' => $reason,
            'updated_at' => $params['updated'],
        ]);
    }

    private function normalizeCooldown(?DateTimeImmutable $cooldownUntil): ?DateTimeImmutable
    {
        if ($cooldownUntil === null) {
            return null;
        }

        // Align cooldown to the start of the minute so minute-based crons re-enable slots on time.
        return $cooldownUntil->setTime(
            (int)$cooldownUntil->format('H'),
            (int)$cooldownUntil->format('i'),
            0,
        )->modify('-20 seconds');
    }

    private function guardEvent(string $eventId, string $source): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $affected = $this->db->executeStatement(
            "INSERT INTO event_dedup(event_id, source, processed_at)
             VALUES (:id, :source, :processed)
             ON DUPLICATE KEY UPDATE processed_at = processed_at",
            [
                'id' => $eventId,
                'source' => $source,
                'processed' => $now->format('Y-m-d H:i:s'),
            ]
        );
        if ($affected !== 1) {
            $this->logger->info('[pipeline] event dedup skipped', ['event_id' => $eventId, 'source' => $source]);
            return false;
        }
        $this->logger->debug('[pipeline] event accepted', ['event_id' => $eventId, 'source' => $source]);
        return true;
    }
}
