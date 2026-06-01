<?php

declare(strict_types=1);

namespace App\Front\Query;

use App\Front\ViewModel\FrontAlert;
use App\Front\ViewModel\RiskSummaryView;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final class RiskSummaryQuery
{
    private readonly FrontDatabase $db;

    public function __construct(
        Connection $connection,
        private readonly ClockInterface $clock,
    ) {
        $this->db = new FrontDatabase($connection);
    }

    public function getSummary(): RiskSummaryView
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $positions = $this->loadOpenPositions();
        $orders = $this->loadOpenOrders();
        $planOrders = $this->loadOpenPlanOrders();
        $locks = $this->loadActiveLocks($now);
        $intents = $this->loadStaleOrderIntents($now);
        $alerts = [];

        foreach ($positions as $index => $position) {
            $payload = FrontDatabase::jsonObject($position['payload'] ?? null);
            $positions[$index]['payload'] = $payload;
            $positions[$index]['has_stop_loss'] = $this->hasStopLoss($payload);

            if (!$positions[$index]['has_stop_loss']) {
                $alerts[] = new FrontAlert(
                    severity: 'critical',
                    code: 'position_without_stop_loss',
                    title: 'Position sans SL',
                    message: sprintf('%s %s ouverte sans SL detecte.', (string) $position['symbol'], (string) $position['side']),
                    symbol: (string) $position['symbol'],
                    context: [
                        'side' => $position['side'] ?? null,
                        'size' => $position['size'] ?? null,
                        'updated_at' => $position['updated_at'] ?? null,
                    ],
                );
            }
        }

        foreach ($locks as $lock) {
            if (($lock['is_stale'] ?? false) !== true) {
                continue;
            }

            $alerts[] = new FrontAlert(
                severity: 'critical',
                code: 'stale_symbol_lock',
                title: 'Lock stale',
                message: sprintf('%s verrouille apres expiration.', (string) $lock['symbol']),
                symbol: (string) $lock['symbol'],
                context: [
                    'owner_decision_key' => $lock['owner_decision_key'] ?? null,
                    'expires_at' => $lock['expires_at'] ?? null,
                ],
            );
        }

        foreach ($intents as $intent) {
            $alerts[] = new FrontAlert(
                severity: 'warning',
                code: 'stale_order_intent',
                title: 'Ordre bloque',
                message: sprintf('%s %s en statut %s depuis plus de 10 minutes.', (string) $intent['symbol'], (string) ($intent['client_order_id'] ?? ''), (string) $intent['status']),
                symbol: (string) $intent['symbol'],
                context: [
                    'decision_key' => $intent['decision_key'] ?? null,
                    'updated_at' => $intent['updated_at'] ?? null,
                ],
            );
        }

        return new RiskSummaryView($positions, $orders, $planOrders, $locks, $alerts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadOpenPositions(): array
    {
        return $this->db->fetchAll(
            ['positions'],
            "SELECT id, exchange, market_type, symbol, side, size, avg_entry_price, leverage, unrealized_pnl, status, payload, updated_at
             FROM positions
             WHERE UPPER(status) = 'OPEN'
             ORDER BY updated_at DESC
             LIMIT 100",
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadOpenOrders(): array
    {
        return $this->db->fetchAll(
            ['futures_order'],
            "SELECT id, exchange, market_type, symbol, side, type, status, price, size, client_order_id, order_id, updated_at
             FROM futures_order
             WHERE LOWER(COALESCE(status, '')) IN ('new', 'open', 'pending', 'submitted', 'partially_filled', 'partially-filled')
             ORDER BY updated_at DESC
             LIMIT 100",
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadOpenPlanOrders(): array
    {
        return $this->db->fetchAll(
            ['futures_plan_order'],
            "SELECT id, exchange, market_type, symbol, side, type, status, trigger_price, price, size, client_order_id, order_id, updated_at
             FROM futures_plan_order
             WHERE LOWER(COALESCE(status, '')) IN ('new', 'open', 'pending', 'submitted', 'active', 'live', 'untriggered')
             ORDER BY updated_at DESC
             LIMIT 100",
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadActiveLocks(\DateTimeImmutable $now): array
    {
        $locks = $this->db->fetchAll(
            ['symbol_execution_lock'],
            "SELECT id, exchange, market_type, symbol, status, owner_profile, owner_decision_key, locked_at, expires_at, released_at
             FROM symbol_execution_lock
             WHERE released_at IS NULL
             ORDER BY expires_at ASC
             LIMIT 100",
        );

        foreach ($locks as $index => $lock) {
            $expiresAt = $this->dateOrNull($lock['expires_at'] ?? null);
            $locks[$index]['is_stale'] = $expiresAt !== null && $expiresAt <= $now;
        }

        return $locks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadStaleOrderIntents(\DateTimeImmutable $now): array
    {
        $threshold = $now->modify('-10 minutes')->format('Y-m-d H:i:s');

        return $this->db->fetchAll(
            ['order_intent'],
            "SELECT id, exchange, market_type, symbol, status, client_order_id, decision_key, updated_at
             FROM order_intent
             WHERE status IN ('DRAFT', 'VALIDATED', 'READY_TO_SEND', 'SENT')
               AND updated_at < :threshold
             ORDER BY updated_at ASC
             LIMIT 100",
            ['threshold' => $threshold],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasStopLoss(array $payload): bool
    {
        $keys = [
            'stop_loss',
            'stopLoss',
            'stop_loss_price',
            'stopLossPrice',
            'sl',
            'sl_price',
            'preset_stop_loss_price',
            'attached_stop_loss_price',
        ];

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $keys, true) && $this->isMeaningfulPrice($value)) {
                return true;
            }

            if (is_array($value) && $this->hasStopLoss($value)) {
                return true;
            }
        }

        return false;
    }

    private function isMeaningfulPrice(mixed $value): bool
    {
        if (is_numeric($value)) {
            return (float) $value > 0.0;
        }

        if (is_string($value)) {
            return trim($value) !== '' && strtolower(trim($value)) !== 'none';
        }

        return FrontDatabase::truthy($value);
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'));
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
