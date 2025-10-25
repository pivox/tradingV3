<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Contract\Provider\Dto\OrderDto;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;

/**
 * Interface pour les providers d'ordres
 */
interface OrderProviderInterface
{
    /**
     * Place un ordre
     */
    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = []
    ): ?OrderDto;

    /**
     * Annule un ordre
     */
    public function cancelOrder(string $orderId): bool;

    /**
     * Récupère un ordre par ID
     */
    public function getOrder(string $orderId): ?OrderDto;

    /**
     * Récupère tous les ordres ouverts
     */
    public function getOpenOrders(?string $symbol = null): array;

    /**
     * Récupère l'historique des ordres
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array;

    /**
     * Annule tous les ordres ouverts pour un symbole
     */
    public function cancelAllOrders(string $symbol): bool;
}
