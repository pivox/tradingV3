<?php

declare(strict_types=1);

namespace App\Application\Runner;

use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;

/**
 * Sérialise l'état ouvert (positions/ordres) en tableaux JSON-safe pour le
 * snapshot orchestrateur (SF-002b). Accepte aussi bien des DTO providers
 * (PositionDto/OrderDto) que des tableaux déjà normalisés afin de rester
 * tolérant aux différents providers.
 */
final class OpenStateSnapshotSerializer
{
    /**
     * @param array<int, mixed> $openPositions
     * @param array<int, mixed> $openOrders
     * @return array{open_positions: array<int, array<string, mixed>>, open_orders: array<int, array<string, mixed>>}
     */
    public function serialize(array $openPositions, array $openOrders): array
    {
        return [
            'open_positions' => array_values(array_map(
                fn ($position): array => $this->serializePosition($position),
                $openPositions,
            )),
            'open_orders' => array_values(array_map(
                fn ($order): array => $this->serializeOrder($order),
                $openOrders,
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePosition(mixed $position): array
    {
        if ($position instanceof PositionDto) {
            return [
                'symbol' => $position->symbol,
                'side' => $position->side->value,
                'size' => $position->size->__toString(),
                'entry_price' => $position->entryPrice->__toString(),
                'mark_price' => $position->markPrice->__toString(),
                'unrealized_pnl' => $position->unrealizedPnl->__toString(),
                'realized_pnl' => $position->realizedPnl->__toString(),
                'margin' => $position->margin->__toString(),
                'leverage' => $position->leverage->__toString(),
                'opened_at' => $position->openedAt->format(\DateTimeInterface::ATOM),
            ];
        }

        if (is_array($position)) {
            return $position;
        }

        return ['symbol' => $this->extractSymbol($position)];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(mixed $order): array
    {
        if ($order instanceof OrderDto) {
            return [
                'order_id' => $order->orderId,
                'symbol' => $order->symbol,
                'side' => $order->side->value,
                'type' => $order->type->value,
                'status' => $order->status->value,
                'quantity' => $order->quantity->__toString(),
                'price' => $order->price?->__toString(),
                'stop_price' => $order->stopPrice?->__toString(),
                'filled_quantity' => $order->filledQuantity->__toString(),
                'remaining_quantity' => $order->remainingQuantity->__toString(),
                'created_at' => $order->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        if (is_array($order)) {
            return $order;
        }

        return ['symbol' => $this->extractSymbol($order)];
    }

    private function extractSymbol(mixed $payload): string
    {
        if (is_object($payload) && is_string($payload->symbol ?? null)) {
            return $payload->symbol;
        }

        return '';
    }
}
