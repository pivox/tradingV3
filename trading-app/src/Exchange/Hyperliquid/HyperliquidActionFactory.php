<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangeTimeInForce;

final class HyperliquidActionFactory
{
    private const DEFAULT_MARKET_SLIPPAGE = 0.05;

    /**
     * @return array<string,mixed>
     */
    public function order(int $assetId, PlaceOrderRequest $request): array
    {
        if (trim($request->clientOrderId) === '') {
            throw new \InvalidArgumentException('Hyperliquid orders require clientOrderId');
        }
        if ($request->attachedStopLossPrice !== null || $request->attachedTakeProfitPrice !== null) {
            throw new \InvalidArgumentException('Hyperliquid adapter does not support attached TP/SL on entry');
        }

        return [
            'type' => 'order',
            'orders' => [[
                'a' => $assetId,
                'b' => $request->side === ExchangeOrderSide::BUY,
                'p' => $this->price($request),
                's' => $this->decimal($request->quantity),
                'r' => $request->reduceOnly,
                't' => $this->orderType($request),
                'c' => $this->cloid($request->clientOrderId),
            ]],
            'grouping' => 'na',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function cancel(int $assetId, CancelOrderRequest $request): array
    {
        if ($request->exchangeOrderId !== null && trim($request->exchangeOrderId) !== '') {
            return [
                'type' => 'cancel',
                'cancels' => [[
                    'a' => $assetId,
                    'o' => (int) $request->exchangeOrderId,
                ]],
            ];
        }

        if ($request->clientOrderId !== null && trim($request->clientOrderId) !== '') {
            return [
                'type' => 'cancelByCloid',
                'cancels' => [[
                    'asset' => $assetId,
                    'cloid' => $this->cloid($request->clientOrderId),
                ]],
            ];
        }

        throw new \InvalidArgumentException('Hyperliquid cancel requires exchangeOrderId or clientOrderId');
    }

    /**
     * @return array<string,mixed>
     */
    public function updateLeverage(int $assetId, int $leverage, string $marginMode): array
    {
        return [
            'type' => 'updateLeverage',
            'asset' => $assetId,
            'isCross' => strtolower($marginMode) === 'cross',
            'leverage' => $leverage,
        ];
    }

    public function cloid(string $clientOrderId): string
    {
        $candidate = trim($clientOrderId);
        if (preg_match('/^0x[0-9a-fA-F]{32}$/', $candidate) === 1) {
            return strtolower($candidate);
        }

        return '0x' . substr(hash('sha256', $candidate), 0, 32);
    }

    /**
     * @return array<string,mixed>
     */
    private function orderType(PlaceOrderRequest $request): array
    {
        if ($request->orderType === ExchangeOrderType::MARKET) {
            return ['limit' => ['tif' => 'Ioc']];
        }

        if ($this->isTriggerOrder($request)) {
            return ['trigger' => [
                'isMarket' => $request->price === null,
                'triggerPx' => $this->decimal($this->triggerPrice($request)),
                'tpsl' => $this->tpsl($request),
            ]];
        }

        if ($request->postOnly) {
            return ['limit' => ['tif' => 'Alo']];
        }

        return ['limit' => ['tif' => match ($request->timeInForce) {
            ExchangeTimeInForce::IOC => 'Ioc',
            ExchangeTimeInForce::FOK => 'Ioc',
            default => 'Gtc',
        }]];
    }

    private function price(PlaceOrderRequest $request): string
    {
        $price = $request->price;
        if ($request->orderType === ExchangeOrderType::MARKET && $price === null) {
            throw new \InvalidArgumentException('Hyperliquid market orders require an explicit slippage cap price');
        }
        if ($this->isTriggerOrder($request) && $price === null) {
            $triggerPrice = $this->triggerPrice($request);
            $price = $request->side === ExchangeOrderSide::BUY
                ? $triggerPrice * (1.0 + self::DEFAULT_MARKET_SLIPPAGE)
                : $triggerPrice * (1.0 - self::DEFAULT_MARKET_SLIPPAGE);
        }
        if ($price === null || $price <= 0.0 || !\is_finite($price)) {
            throw new \InvalidArgumentException('Hyperliquid order requires a positive limit or slippage cap price');
        }

        return $this->decimal($price);
    }

    private function isTriggerOrder(PlaceOrderRequest $request): bool
    {
        return \in_array($request->orderType, [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER,
        ], true);
    }

    private function triggerPrice(PlaceOrderRequest $request): float
    {
        if ($request->stopPrice === null || $request->stopPrice <= 0.0 || !\is_finite($request->stopPrice)) {
            throw new \InvalidArgumentException('Hyperliquid trigger orders require a positive stopPrice');
        }

        return $request->stopPrice;
    }

    private function tpsl(PlaceOrderRequest $request): string
    {
        if ($request->orderType === ExchangeOrderType::STOP_LOSS) {
            return 'sl';
        }
        if ($request->orderType === ExchangeOrderType::TAKE_PROFIT) {
            return 'tp';
        }

        $configured = strtolower(trim((string)($request->metadata['hyperliquid_tpsl'] ?? $request->metadata['tpsl'] ?? '')));
        if (\in_array($configured, ['sl', 'tp'], true)) {
            return $configured;
        }

        throw new \InvalidArgumentException('Hyperliquid generic trigger orders require metadata tpsl="sl" or "tp"');
    }

    private function decimal(float $value): string
    {
        if (!\is_finite($value)) {
            throw new \InvalidArgumentException('Hyperliquid decimal values must be finite');
        }

        $rounded = sprintf('%.8F', $value);
        if (abs((float) $rounded - $value) >= 1.0e-12) {
            throw new \InvalidArgumentException(sprintf('Hyperliquid decimal value "%s" has more than 8 wire decimals', $value));
        }

        return rtrim(rtrim($rounded, '0'), '.');
    }
}
