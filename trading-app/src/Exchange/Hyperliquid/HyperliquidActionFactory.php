<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
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
    public function positionTpsl(int $assetId, PlaceOrderRequest $entry, PlaceOrderRequest $stop): array
    {
        $this->assertAssetId($assetId);
        if ($entry->exchange !== $stop->exchange) {
            throw new \InvalidArgumentException('hyperliquid_orders_must_use_same_exchange');
        }
        if ($entry->marketType !== $stop->marketType) {
            throw new \InvalidArgumentException('hyperliquid_orders_must_use_same_market_type');
        }
        $this->assertHyperliquidPerpetual($entry);
        $this->assertHyperliquidPerpetual($stop);
        if ($this->normalizeSymbol($entry->symbol) !== $this->normalizeSymbol($stop->symbol)) {
            throw new \InvalidArgumentException('hyperliquid_orders_must_use_same_symbol');
        }
        if ($entry->quantity !== $stop->quantity) {
            throw new \InvalidArgumentException('hyperliquid_orders_must_use_same_quantity');
        }
        if ($entry->positionSide !== $stop->positionSide) {
            throw new \InvalidArgumentException('hyperliquid_orders_must_use_same_position_side');
        }
        if ($entry->reduceOnly) {
            throw new \InvalidArgumentException('hyperliquid_entry_must_not_be_reduce_only');
        }
        if (!$stop->reduceOnly) {
            throw new \InvalidArgumentException('hyperliquid_stop_must_be_reduce_only');
        }
        if ($stop->orderType !== ExchangeOrderType::STOP_LOSS) {
            throw new \InvalidArgumentException('hyperliquid_stop_must_be_stop_loss');
        }
        if ($entry->side === $stop->side) {
            throw new \InvalidArgumentException('hyperliquid_stop_must_close_entry_side');
        }
        if (!$this->isEntrySideCompatible($entry)) {
            throw new \InvalidArgumentException('hyperliquid_entry_side_incompatible_with_position');
        }
        if ($this->cloid($entry->clientOrderId) === $this->cloid($stop->clientOrderId)) {
            throw new \InvalidArgumentException('hyperliquid_order_cloids_must_be_distinct');
        }

        $entryWire = $this->order($assetId, $entry)['orders'][0];
        $stopWire = $this->order($assetId, $stop)['orders'][0];

        return [
            'type' => 'order',
            'orders' => [$entryWire, $stopWire],
            'grouping' => 'positionTpsl',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function emergencyClose(int $assetId, PlaceOrderRequest $request): array
    {
        $this->assertAssetId($assetId);
        $this->assertHyperliquidPerpetual($request);

        if (!$request->reduceOnly) {
            throw new \InvalidArgumentException('hyperliquid_emergency_close_must_be_reduce_only');
        }
        if ($request->orderType !== ExchangeOrderType::MARKET) {
            throw new \InvalidArgumentException('hyperliquid_emergency_close_must_be_market');
        }
        if ($request->timeInForce !== ExchangeTimeInForce::IOC) {
            throw new \InvalidArgumentException('hyperliquid_emergency_close_must_be_ioc');
        }
        if ($request->quantity <= 0.0 || !\is_finite($request->quantity)) {
            throw new \InvalidArgumentException('hyperliquid_emergency_close_requires_positive_finite_quantity');
        }
        if ($request->price === null || $request->price <= 0.0 || !\is_finite($request->price)) {
            throw new \InvalidArgumentException('hyperliquid_emergency_close_requires_positive_finite_slippage_cap_price');
        }
        if ($request->postOnly) {
            throw new \InvalidArgumentException('hyperliquid_emergency_close_must_not_be_post_only');
        }
        if ($request->side === $this->entrySide($request->positionSide)) {
            throw new \InvalidArgumentException('hyperliquid_emergency_close_must_close_position_side');
        }

        return $this->order($assetId, $request);
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

    private function assertAssetId(int $assetId): void
    {
        if ($assetId < 0) {
            throw new \InvalidArgumentException('hyperliquid_asset_id_must_be_non_negative');
        }
    }

    private function assertHyperliquidPerpetual(PlaceOrderRequest $request): void
    {
        if ($request->exchange !== Exchange::HYPERLIQUID || $request->marketType !== MarketType::PERPETUAL) {
            throw new \InvalidArgumentException('hyperliquid_orders_require_hyperliquid_perpetual');
        }
    }

    private function isEntrySideCompatible(PlaceOrderRequest $request): bool
    {
        return $request->side === $this->entrySide($request->positionSide);
    }

    private function entrySide(ExchangePositionSide $positionSide): ExchangeOrderSide
    {
        return match ($positionSide) {
            ExchangePositionSide::LONG => ExchangeOrderSide::BUY,
            ExchangePositionSide::SHORT => ExchangeOrderSide::SELL,
        };
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        foreach (['-PERP', 'PERP', '/USDC', '-USDC', 'USDC', '/USDT', '-USDT', 'USDT'] as $suffix) {
            if (str_ends_with($symbol, $suffix)) {
                return substr($symbol, 0, -strlen($suffix));
            }
        }

        return $symbol;
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
