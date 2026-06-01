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
        $stopLossProtections = $this->loadStopLossProtections();
        $locks = $this->loadActiveLocks($now);
        $intents = $this->loadStaleOrderIntents($now);
        $alerts = [];

        foreach ($positions as $index => $position) {
            $payload = FrontDatabase::jsonObject($position['payload'] ?? null);
            $positions[$index]['payload'] = $payload;
            $positions[$index]['has_stop_loss'] = $this->hasStopLoss($payload)
                || $this->hasActiveStopLossProtection($position, $orders, $planOrders, $stopLossProtections);

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
             WHERE LOWER(COALESCE(status, '')) IN ('new', 'open', 'pending', 'sent', 'submitted', 'partially_filled', 'partially-filled', '1', '2')
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
             "SELECT id, exchange, market_type, symbol, side, type, status, trigger_price, price, size, client_order_id, order_id, plan_type, raw_data, updated_at
              FROM futures_plan_order
             WHERE LOWER(COALESCE(status, '')) IN ('new', 'open', 'pending', 'submitted', 'active', 'live', 'untriggered', '1')
                OR (
                    (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('cancelled', 'canceled', 'closed', 'filled', 'failed', 'rejected', 'expired', 'triggered', 'executed', 'completed', 'complete'))
                    AND (
                        CAST(raw_data AS TEXT) LIKE '%\"state\":1%'
                        OR CAST(raw_data AS TEXT) LIKE '%\"state\": 1%'
                        OR CAST(raw_data AS TEXT) LIKE '%\"state\":\"1\"%'
                        OR CAST(raw_data AS TEXT) LIKE '%\"state\": \"1\"%'
                    )
                )
              ORDER BY updated_at DESC
              LIMIT 100",
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadStopLossProtections(): array
    {
        return $this->db->fetchAll(
            ['order_protection', 'order_intent', 'futures_plan_order', 'futures_order'],
            "SELECT op.id,
                    oi.exchange,
                    oi.market_type,
                    oi.symbol,
                    COALESCE(fpo.side, fo.side, oi.side) AS side,
                    op.type,
                    op.price,
                    op.client_order_id,
                    op.order_id,
                    op.updated_at
             FROM order_protection op
             INNER JOIN order_intent oi ON oi.id = op.order_intent_id
             LEFT JOIN futures_plan_order fpo
               ON fpo.exchange = oi.exchange
              AND fpo.market_type = oi.market_type
               AND (
                   fpo.order_id = op.order_id
                   OR fpo.order_id = oi.order_id
                   OR fpo.order_id = oi.exchange_order_id
                   OR fpo.client_order_id = op.client_order_id
                   OR fpo.client_order_id = oi.client_order_id
               )
             LEFT JOIN futures_order fo
                ON fo.exchange = oi.exchange
               AND fo.market_type = oi.market_type
               AND (
                   (op.order_id IS NOT NULL AND op.order_id <> '' AND fo.order_id = op.order_id)
                   OR (op.client_order_id IS NOT NULL AND op.client_order_id <> '' AND fo.client_order_id = op.client_order_id)
               )
             WHERE LOWER(COALESCE(op.type, '')) = 'stop_loss'
               AND op.price > 0
               AND oi.status = 'SENT'
               AND (
                   (
                       (
                           LOWER(COALESCE(fpo.status, '')) IN ('new', 'open', 'pending', 'submitted', 'active', 'live', 'untriggered', '1')
                           OR (
                               (fpo.status IS NULL OR TRIM(fpo.status) = '' OR LOWER(TRIM(fpo.status)) NOT IN ('cancelled', 'canceled', 'closed', 'filled', 'failed', 'rejected', 'expired', 'triggered', 'executed', 'completed', 'complete'))
                               AND (
                                   CAST(fpo.raw_data AS TEXT) LIKE '%\"state\":1%'
                                   OR CAST(fpo.raw_data AS TEXT) LIKE '%\"state\": 1%'
                                   OR CAST(fpo.raw_data AS TEXT) LIKE '%\"state\":\"1\"%'
                                   OR CAST(fpo.raw_data AS TEXT) LIKE '%\"state\": \"1\"%'
                               )
                           )
                       )
                       AND (
                           LOWER(COALESCE(fpo.type, '')) LIKE '%stop%'
                           OR LOWER(COALESCE(fpo.plan_type, '')) LIKE '%stop%'
                           OR LOWER(COALESCE(fpo.client_order_id, '')) LIKE '%sl%'
                           OR LOWER(COALESCE(fpo.order_id, '')) LIKE '%sl%'
                       )
                   )
                   OR LOWER(COALESCE(fo.status, '')) IN ('new', 'open', 'pending', 'sent', 'submitted', 'partially_filled', 'partially-filled', '1', '2')
               )
             ORDER BY op.updated_at DESC
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

    /**
     * @param array<string, mixed> $position
     * @param list<array<string, mixed>> $orders
     * @param list<array<string, mixed>> $planOrders
     * @param list<array<string, mixed>> $stopLossProtections
     */
    private function hasActiveStopLossProtection(array $position, array $orders, array $planOrders, array $stopLossProtections): bool
    {
        $symbol = strtoupper((string) ($position['symbol'] ?? ''));
        if ($symbol === '') {
            return false;
        }

        foreach (array_merge($orders, $planOrders, $stopLossProtections) as $protection) {
            if (strtoupper((string) ($protection['symbol'] ?? '')) !== $symbol) {
                continue;
            }

            if (!$this->protectionMatchesPositionScope($protection, $position)) {
                continue;
            }

            if (!$this->rowIndicatesStopLoss($protection)) {
                continue;
            }

            if ($this->isMeaningfulPrice($protection['trigger_price'] ?? null)
                || $this->isMeaningfulPrice($protection['price'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $protection
     * @param array<string, mixed> $position
     */
    private function protectionMatchesPositionScope(array $protection, array $position): bool
    {
        foreach (['exchange', 'market_type'] as $key) {
            $positionValue = strtolower((string) ($position[$key] ?? ''));
            $protectionValue = strtolower((string) ($protection[$key] ?? ''));
            if ($positionValue !== '' && $protectionValue !== '' && $positionValue !== $protectionValue) {
                return false;
            }
        }

        $positionSide = $this->normalizePositionSide($position['side'] ?? null);
        $protectionSide = $this->normalizePositionSide($protection['side'] ?? null);

        return $positionSide !== null && $protectionSide !== null && $positionSide === $protectionSide;
    }

    private function normalizePositionSide(mixed $side): ?string
    {
        if (is_numeric($side)) {
            return match ((int) $side) {
                1, 2 => 'LONG',
                3, 4 => 'SHORT',
                default => null,
            };
        }

        $normalized = strtolower(trim((string) $side));

        return match ($normalized) {
            'long', 'open_long', 'close_long' => 'LONG',
            'short', 'open_short', 'close_short' => 'SHORT',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowIndicatesStopLoss(array $row): bool
    {
        foreach (['type', 'plan_type', 'client_order_id', 'order_id'] as $key) {
            $value = strtolower((string) ($row[$key] ?? ''));
            if (str_contains($value, 'stop') || str_contains($value, 'sl')) {
                return true;
            }
        }

        $rawData = FrontDatabase::jsonObject($row['raw_data'] ?? null);

        return $rawData !== [] && $this->hasStopLoss($rawData);
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
