<?php

declare(strict_types=1);

namespace App\Provider\Service;

use App\Common\Enum\Exchange;
use App\Provider\Bitmart\Http\BitmartHttpClientPrivate;
use App\Service\FuturesOrderSyncService;
use Brick\Math\BigDecimal;

final class PositionProtectionService
{
    public function __construct(
        private readonly BitmartHttpClientPrivate $bitmartClient,
        private readonly FuturesOrderSyncService $futuresOrderSyncService,
    ) {
    }

    /**
     * Modifie les protections (TP/SL) d'une position pour un exchange donné.
     *
     * @param string|float|null $stopLossPrice
     * @param string|float|null $takeProfitPrice
     */
    public function modifyProtection(
        Exchange $exchange,
        string $symbol,
        string $planOrderId,
        ?string $clientOrderId,
        string|float|null $stopLossPrice,
        string|float|null $takeProfitPrice,
    ): array {
        return match ($exchange) {
            Exchange::BITMART => $this->modifyBitmartProtection(
                symbol: $symbol,
                planOrderId: $planOrderId,
                clientOrderId: $clientOrderId,
                stopLossPrice: $stopLossPrice,
                takeProfitPrice: $takeProfitPrice,
            ),
        };
    }

    /**
     * Appelle l'API BitMart pour mettre à jour un plan order (TP/SL).
     *
     * @param string|float|null $stopLossPrice
     * @param string|float|null $takeProfitPrice
     */
    private function modifyBitmartProtection(
        string $symbol,
        string $planOrderId,
        ?string $clientOrderId,
        string|float|null $stopLossPrice,
        string|float|null $takeProfitPrice,
    ): array {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \InvalidArgumentException('Symbol is required to update protections.');
        }

        $planOrderId = trim($planOrderId);
        if ($planOrderId === '') {
            throw new \InvalidArgumentException('Plan order identifier is required.');
        }

        $payload = [
            'symbol' => $symbol,
            'plan_order_id' => $planOrderId,
            'order_id' => $planOrderId,
        ];

        if ($clientOrderId !== null && $clientOrderId !== '') {
            $payload['client_order_id'] = $clientOrderId;
        }

        $stopLoss = $this->normalizePrice($stopLossPrice);
        $takeProfit = $this->normalizePrice($takeProfitPrice);

        if ($stopLoss === null && $takeProfit === null) {
            throw new \InvalidArgumentException('At least one of stop_loss_price or take_profit_price must be provided.');
        }

        if ($stopLoss !== null) {
            $payload['preset_stop_loss_price'] = $stopLoss;
            $payload['preset_stop_loss_price_type'] = 1;
        }

        if ($takeProfit !== null) {
            $payload['preset_take_profit_price'] = $takeProfit;
            $payload['preset_take_profit_price_type'] = 1;
        }

        $response = $this->bitmartClient->modifyPlanOrder($payload);

        if (isset($response['data']) && is_array($response['data'])) {
            $this->futuresOrderSyncService->syncPlanOrderFromApi($response['data']);
        }

        return $response;
    }

    /**
     * Normalise le prix renseigné et retire les zéros inutiles.
     *
     * @param string|float|null $value
     */
    private function normalizePrice(string|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = is_string($value) ? trim($value) : (string) $value;
        if ($raw === '') {
            return null;
        }

        $decimal = BigDecimal::of($raw)->stripTrailingZeros();
        return $decimal->isZero() ? '0' : $decimal->toPlainString();
    }
}
