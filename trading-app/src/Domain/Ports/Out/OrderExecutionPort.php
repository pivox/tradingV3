<?php

declare(strict_types=1);

namespace App\Domain\Ports\Out;

use App\Domain\Common\Dto\OrderPlanDto;

interface OrderExecutionPort
{
    /**
     * Exécute un ordre sur l'API BitMart
     */
    public function executeOrder(OrderPlanDto $orderPlan): array;

    /**
     * Annule un ordre
     */
    public function cancelOrder(string $orderId): bool;

    /**
     * Récupère le statut d'un ordre
     */
    public function getOrderStatus(string $orderId): array;

    /**
     * Récupère les positions ouvertes
     */
    public function getOpenPositions(): array;

    /**
     * Récupère les détails des assets
     */
    public function getAssetsDetails(): array;

    /**
     * Définit le levier pour un symbole
     */
    public function setLeverage(string $symbol, int $leverage): bool;

    /**
     * Définit le mode de position (hedge/one-way)
     */
    public function setPositionMode(string $mode): bool;

    /**
     * Place un ordre de take profit / stop loss
     */
    public function placeTpSlOrder(OrderPlanDto $orderPlan): array;
}




