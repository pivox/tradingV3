<?php

declare(strict_types=1);

namespace App\Service\Trading;

/**
 * Interface d'abstraction de l'exchange.
 * Implémentez un adapter (Binance, Bybit, etc.) qui respecte ces signatures.
 */
interface ExchangeClient
{
    /**
     * Place un ordre LIMIT. Retourne un identifiant d'ordre exchange.
     * @param 'long'|'short' $side
     */
    public function placeLimitOrder(
        string $symbol,
        string $side,
        float $price,
        float $qty,
        bool $postOnly = true
    ): string;

    /**
     * Place un OCO (Take-Profit + Stop) pour fermer une position partielle/complète.
     * Le side est inversé par rapport à l'entrée (sell si long, buy si short).
     */
    public function placeOco(
        string $symbol,
        string $side,
        float $takeProfitPrice,
        float $stopPrice,
        float $qty
    ): string;

    /**
     * Enregistre un callback exécuté lorsque l'ordre d'entrée est complètement rempli.
     * L'implémentation peut aussi supporter les fills partiels.
     *
     * @param callable(float $filledQty):void $callback
     */
    public function onFilled(string $orderId, callable $callback): void;
}
