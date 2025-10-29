<?php

declare(strict_types=1);

namespace App\Order;

final class OrderSignalFactory
{
    /**
     * Construit un signal à partir d'un évènement d'ordre Bitmart reçu via WS.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $rawEvent
     */
    public function createFromBitmartEvent(array $order, array $rawEvent, int $action, string $actionText, string $stateText): ?OrderSignal
    {
        $clientOrderId = (string)($order['client_order_id'] ?? '');
        if ($clientOrderId === '') {
            return null;
        }

        $timestampIso = $this->resolveTimestampIso($order);
        $payload = [
            'kind' => $this->determineKind($clientOrderId, $order),
            'status' => $this->determineStatusFromAction($action),
            'client_order_id' => $clientOrderId,
            'parent_client_order_id' => $this->extractParentClientOrderId($clientOrderId),
            'order_id' => $this->stringOrNull($order['order_id'] ?? null),
            'symbol' => strtoupper((string)($order['symbol'] ?? '')),
            'side' => (string)($order['side'] ?? ''),
            'type' => (string)($order['type'] ?? $order['orderType'] ?? ''),
            'price' => (string)($order['price'] ?? $order['trigger_price'] ?? $order['executive_price'] ?? '0'),
            'size' => (string)($order['size'] ?? $order['plan_size'] ?? '0'),
            'leverage' => $this->stringOrNull($order['leverage'] ?? null),
            'submitted_at' => $timestampIso,
            'context' => $this->buildContext($order, $actionText, $stateText),
            'exchange_response' => [
                'order' => $order,
                'action' => $action,
                'raw' => $rawEvent,
            ],
        ];

        if ($payload['parent_client_order_id'] === null) {
            unset($payload['parent_client_order_id']);
        }
        if ($payload['order_id'] === null) {
            unset($payload['order_id']);
        }
        if ($payload['leverage'] === null) {
            unset($payload['leverage']);
        }

        return OrderSignal::fromArray($payload);
    }

    private function determineKind(string $clientOrderId, array $order): string
    {
        $upperId = strtoupper($clientOrderId);

        if (str_contains($upperId, '_SL_') || str_contains($upperId, 'STOP')) {
            return 'STOP_LOSS';
        }
        if (str_contains($upperId, '_TP_') || str_contains($upperId, 'TAKE_PROFIT') || str_contains($upperId, '_TP')) {
            return 'TAKE_PROFIT';
        }

        $type = strtolower((string)($order['type'] ?? $order['orderType'] ?? ''));
        return match ($type) {
            'stop_loss' => 'STOP_LOSS',
            'take_profit' => 'TAKE_PROFIT',
            default => 'ENTRY',
        };
    }

    private function extractParentClientOrderId(string $clientOrderId): ?string
    {
        if (preg_match('/^(MTF_[A-Z0-9]+)_([A-Z]+)_(.+)$/', strtoupper($clientOrderId), $matches)) {
            return sprintf('%s_OPEN_%s', $matches[1], $matches[3]);
        }

        return null;
    }

    private function determineStatusFromAction(int $action): string
    {
        return match ($action) {
            3, 4, 5 => 'CANCELLED',
            default => 'SUBMITTED',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function buildContext(array $order, string $actionText, string $stateText): array
    {
        return [
            'source' => 'bitmart_ws_worker',
            'action' => $actionText,
            'state' => $stateText,
            'deal_size' => $order['deal_size'] ?? null,
            'deal_avg_price' => $order['deal_avg_price'] ?? null,
            'trigger_price' => $order['trigger_price'] ?? null,
            'executive_price' => $order['executive_price'] ?? null,
        ];
    }

    private function resolveTimestampIso(array $order): string
    {
        $timestamp = $order['update_time'] ?? $order['update_time_ms'] ?? $order['c_time'] ?? null;
        if ($timestamp !== null) {
            $timestamp = (int) $timestamp;
            if ($timestamp > 9_999_999_999) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            return (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::ATOM);
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
