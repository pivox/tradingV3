<?php
declare(strict_types=1);

namespace App\Service\Trading;

final class InMemoryExchangeClient implements ExchangeClient
{
    /** @var array<string, callable(float):void> */
    private array $filledCallbacks = [];

    /** @var array<string, array{symbol:string, side:string, price:float, qty:float, postOnly:bool}> */
    private array $limitOrders = [];

    /** @var array<string, array{symbol:string, side:string, tp:float, stop:float, qty:float}> */
    private array $ocoOrders = [];

    public function placeLimitOrder(
        string $symbol,
        string $side,
        float $price,
        float $qty,
        bool $postOnly = true
    ): string {
        $orderId = 'stub_' . bin2hex(random_bytes(6));
        $this->limitOrders[$orderId] = [
            'symbol'   => $symbol,
            'side'     => $side,   // 'long' | 'short'
            'price'    => $price,
            'qty'      => $qty,
            'postOnly' => $postOnly,
        ];
        return $orderId;
    }

    public function placeOco(
        string $symbol,
        string $side,
        float $takeProfitPrice,
        float $stopPrice,
        float $qty
    ): string {
        $ocoId = 'stub_oco_' . bin2hex(random_bytes(6));
        $this->ocoOrders[$ocoId] = [
            'symbol' => $symbol,
            'side'   => $side, // 'buy' | 'sell' (inverse de l’entrée)
            'tp'     => $takeProfitPrice,
            'stop'   => $stopPrice,
            'qty'    => $qty,
        ];
        return $ocoId;
    }

    public function onFilled(string $orderId, callable $callback): void
    {
        $this->filledCallbacks[$orderId] = $callback;
    }

    /**
     * Méthode utilitaire pour les tests/démo : simule un fill (total/partiel).
     */
    public function triggerFill(string $orderId, float $filledQty): void
    {
        if (isset($this->filledCallbacks[$orderId])) {
            ($this->filledCallbacks[$orderId])($filledQty);
            unset($this->filledCallbacks[$orderId]);
        }
    }
}
