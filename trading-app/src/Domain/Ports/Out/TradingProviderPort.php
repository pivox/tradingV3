<?php

declare(strict_types=1);

namespace App\Domain\Ports\Out;

interface TradingProviderPort
{
    /**
     * Soumet un ordre de trading
     */
    public function submitOrder(array $orderData): array;

    /**
     * Annule un ordre spécifique
     */
    public function cancelOrder(string $symbol, string $orderId): array;

    /**
     * Annule tous les ordres pour un symbole
     */
    public function cancelAllOrders(string $symbol): array;

    /**
     * Récupère les positions
     */
    public function getPositions(?string $symbol = null): array;

    /**
     * Récupère les détails des assets
     */
    public function getAssetsDetail(): array;

    /**
     * Vérifie la santé de l'API
     */
    public function healthCheck(): array;

    /**
     * Configure le levier sur un symbole donné.
     */
    public function setLeverage(string $symbol, int $leverage, string $openType = 'cross'): array;

    /**
     * Soumet un ordre TP/SL lié à une position ouverte.
     */
    public function submitTpSlOrder(array $payload): array;

    /**
     * Retourne la liste des ordres ouverts et planifiés pour un symbole (ou tous).
     *
     * @return array{orders: array<mixed>, plan_orders: array<mixed>}|
     *         array<string,array<mixed>>
     */
    public function getOpenOrders(?string $symbol = null): array;
}
