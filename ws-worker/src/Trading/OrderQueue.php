<?php

declare(strict_types=1);

namespace App\Trading;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File d'attente thread-safe pour les ordres en attente
 * 
 * Responsabilit?s:
 * - Stocker les ordres en attente de placement
 * - G?rer les timeouts (annulation automatique)
 * - Fournir un acc?s thread-safe aux ordres
 */
final class OrderQueue
{
    /**
     * @var array<string,array{
     *   id: string,
     *   symbol: string,
     *   side: string,
     *   entry_zone_min: float,
     *   entry_zone_max: float,
     *   quantity: float,
     *   leverage: ?int,
     *   stop_loss: ?float,
     *   take_profit: ?float,
     *   callback_url: ?string,
     *   timeout_at: int,
     *   created_at: int,
     *   metadata: array<string,mixed>
     * }>
     */
    private array $pendingOrders = [];

    /**
     * @var array<string,array{
     *   symbol: string,
     *   order_id: string,
     *   stop_loss: ?float,
     *   take_profit: ?float,
     *   callback_url: ?string,
     *   created_at: int
     * }>
     */
    private array $monitoredPositions = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Ajoute un ordre ? la file d'attente
     *
     * @param array{
     *   id: string,
     *   symbol: string,
     *   side: string,
     *   entry_zone_min: float,
     *   entry_zone_max: float,
     *   quantity: float,
     *   leverage: ?int,
     *   stop_loss: ?float,
     *   take_profit: ?float,
     *   callback_url: ?string,
     *   timeout_seconds: int,
     *   metadata: array<string,mixed>
     * } $order
     */
    public function addOrder(array $order): void
    {
        $orderId = $order['id'];
        $timeoutSeconds = $order['timeout_seconds'] ?? 300; // 5 minutes par d?faut
        $now = time();

        $this->pendingOrders[$orderId] = [
            'id' => $orderId,
            'symbol' => $order['symbol'],
            'side' => $order['side'],
            'entry_zone_min' => $order['entry_zone_min'],
            'entry_zone_max' => $order['entry_zone_max'],
            'quantity' => $order['quantity'],
            'leverage' => $order['leverage'] ?? null,
            'stop_loss' => $order['stop_loss'] ?? null,
            'take_profit' => $order['take_profit'] ?? null,
            'callback_url' => $order['callback_url'] ?? null,
            'timeout_at' => $now + $timeoutSeconds,
            'created_at' => $now,
            'metadata' => $order['metadata'] ?? [],
        ];

        $this->logger->info('[OrderQueue] Order added', [
            'order_id' => $orderId,
            'symbol' => $order['symbol'],
            'timeout_seconds' => $timeoutSeconds,
        ]);
    }

    /**
     * Ajoute une position ? monitorer pour SL/TP
     *
     * @param array{
     *   id: string,
     *   symbol: string,
     *   order_id: string,
     *   stop_loss: ?float,
     *   take_profit: ?float,
     *   callback_url: ?string
     * } $position
     */
    public function addMonitoredPosition(array $position): void
    {
        $positionId = $position['id'];

        $this->monitoredPositions[$positionId] = [
            'symbol' => $position['symbol'],
            'order_id' => $position['order_id'],
            'stop_loss' => $position['stop_loss'] ?? null,
            'take_profit' => $position['take_profit'] ?? null,
            'callback_url' => $position['callback_url'] ?? null,
            'created_at' => time(),
        ];

        $this->logger->info('[OrderQueue] Position added for monitoring', [
            'position_id' => $positionId,
            'symbol' => $position['symbol'],
            'sl' => $position['stop_loss'],
            'tp' => $position['take_profit'],
        ]);
    }

    /**
     * Retire un ordre de la file d'attente
     */
    public function removeOrder(string $orderId): void
    {
        if (isset($this->pendingOrders[$orderId])) {
            unset($this->pendingOrders[$orderId]);
            $this->logger->debug('[OrderQueue] Order removed', ['order_id' => $orderId]);
        }
    }

    /**
     * Retire une position monitor?e
     */
    public function removeMonitoredPosition(string $positionId): void
    {
        if (isset($this->monitoredPositions[$positionId])) {
            unset($this->monitoredPositions[$positionId]);
            $this->logger->debug('[OrderQueue] Position removed from monitoring', ['position_id' => $positionId]);
        }
    }

    /**
     * R?cup?re tous les ordres en attente
     *
     * @return array<string,array>
     */
    public function getPendingOrders(): array
    {
        return $this->pendingOrders;
    }

    /**
     * R?cup?re toutes les positions monitor?es
     *
     * @return array<string,array>
     */
    public function getMonitoredPositions(): array
    {
        return $this->monitoredPositions;
    }

    /**
     * R?cup?re et retire les ordres expir?s
     *
     * @return array<array>
     */
    public function getExpiredOrders(): array
    {
        $now = time();
        $expired = [];

        foreach ($this->pendingOrders as $orderId => $order) {
            if ($order['timeout_at'] <= $now) {
                $expired[] = $order;
                unset($this->pendingOrders[$orderId]);
                $this->logger->warning('[OrderQueue] Order expired', [
                    'order_id' => $orderId,
                    'symbol' => $order['symbol'],
                    'age_seconds' => $now - $order['created_at'],
                ]);
            }
        }

        return $expired;
    }

    /**
     * Compte le nombre d'ordres en attente
     */
    public function count(): int
    {
        return count($this->pendingOrders);
    }

    /**
     * Compte le nombre de positions monitor?es
     */
    public function countMonitoredPositions(): int
    {
        return count($this->monitoredPositions);
    }

    /**
     * V?rifie si un ordre existe dans la file
     */
    public function hasOrder(string $orderId): bool
    {
        return isset($this->pendingOrders[$orderId]);
    }

    /**
     * R?cup?re un ordre sp?cifique
     *
     * @return array|null
     */
    public function getOrder(string $orderId): ?array
    {
        return $this->pendingOrders[$orderId] ?? null;
    }

    /**
     * R?cup?re une position monitor?e sp?cifique
     *
     * @return array|null
     */
    public function getMonitoredPosition(string $positionId): ?array
    {
        return $this->monitoredPositions[$positionId] ?? null;
    }

    /**
     * Vide la file d'attente (pour tests ou reset)
     */
    public function clear(): void
    {
        $count = count($this->pendingOrders);
        $this->pendingOrders = [];
        $this->logger->info('[OrderQueue] Queue cleared', ['count' => $count]);
    }

    /**
     * Vide les positions monitor?es
     */
    public function clearMonitoredPositions(): void
    {
        $count = count($this->monitoredPositions);
        $this->monitoredPositions = [];
        $this->logger->info('[OrderQueue] Monitored positions cleared', ['count' => $count]);
    }
}
