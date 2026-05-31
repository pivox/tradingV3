<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;

final class OkxActionFactory
{
    /**
     * @return array<string,mixed>
     */
    public function order(string $instId, PlaceOrderRequest $request): array
    {
        if ($request->attachedStopLossPrice !== null || $request->attachedTakeProfitPrice !== null) {
            throw new \InvalidArgumentException('OKX adapter does not support attached TP/SL on entry');
        }

        return [
            'instId' => $instId,
            'tdMode' => $this->tdMode($request->marginMode),
            'clOrdId' => $this->clientOrderId($request->clientOrderId),
            'side' => $request->side->value,
            'posSide' => $this->posSide($request->positionSide),
            'ordType' => $this->ordType($request),
            'sz' => $this->decimal($request->quantity),
            'reduceOnly' => $request->reduceOnly ? 'true' : 'false',
        ] + $this->priceFields($request);
    }

    /**
     * @return array<string,mixed>
     */
    public function algoOrder(string $instId, PlaceOrderRequest $request): array
    {
        if (!$this->isTriggerOrder($request)) {
            throw new \InvalidArgumentException('OKX algo order requires a trigger order type');
        }
        if ($request->stopPrice === null) {
            throw new \InvalidArgumentException('OKX trigger orders require stopPrice');
        }

        $body = [
            'instId' => $instId,
            'tdMode' => $this->tdMode($request->marginMode),
            'algoClOrdId' => $this->clientOrderId($request->clientOrderId),
            'side' => $request->side->value,
            'posSide' => $this->posSide($request->positionSide),
            'ordType' => 'conditional',
            'sz' => $this->decimal($request->quantity),
            'reduceOnly' => $request->reduceOnly ? 'true' : 'false',
        ];

        $orderPrice = $request->price !== null ? $this->decimal($request->price) : '-1';
        if ($request->orderType === ExchangeOrderType::TAKE_PROFIT || $this->triggerKind($request) === 'tp') {
            $body['tpTriggerPx'] = $this->decimal($request->stopPrice);
            $body['tpOrdPx'] = $orderPrice;
            $body['tpTriggerPxType'] = 'mark';
        } else {
            $body['slTriggerPx'] = $this->decimal($request->stopPrice);
            $body['slOrdPx'] = $orderPrice;
            $body['slTriggerPxType'] = 'mark';
        }

        return $body;
    }

    /**
     * @return array<string,mixed>
     */
    public function cancelOrder(string $instId, CancelOrderRequest $request): array
    {
        $body = ['instId' => $instId];
        if ($request->exchangeOrderId !== null && trim($request->exchangeOrderId) !== '') {
            $body['ordId'] = $request->exchangeOrderId;

            return $body;
        }
        if ($request->clientOrderId !== null && trim($request->clientOrderId) !== '') {
            $body['clOrdId'] = $this->clientOrderId($request->clientOrderId);

            return $body;
        }

        throw new \InvalidArgumentException('OKX cancel requires exchangeOrderId or clientOrderId');
    }

    /**
     * @return array<string,mixed>
     */
    public function cancelAlgo(string $instId, CancelOrderRequest $request): array
    {
        $body = ['instId' => $instId];
        if ($request->exchangeOrderId !== null && str_starts_with($request->exchangeOrderId, 'algo:')) {
            $body['algoId'] = substr($request->exchangeOrderId, 5);

            return $body;
        }
        if ($request->clientOrderId !== null && trim($request->clientOrderId) !== '') {
            $body['algoClOrdId'] = $this->clientOrderId($request->clientOrderId);

            return $body;
        }

        throw new \InvalidArgumentException('OKX algo cancel requires algo exchangeOrderId or clientOrderId');
    }

    /**
     * @return array<string,string>
     */
    public function setLeverage(
        string $instId,
        int $leverage,
        string $marginMode,
        ?ExchangePositionSide $positionSide = null,
    ): array {
        $body = [
            'instId' => $instId,
            'lever' => (string) $leverage,
            'mgnMode' => $this->tdMode($marginMode),
        ];
        if ($positionSide !== null) {
            $body['posSide'] = $this->posSide($positionSide);
        }

        return $body;
    }

    /**
     * @return list<array<string,string>>
     */
    public function setLeverageRequests(string $instId, int $leverage, string $marginMode): array
    {
        $base = $this->setLeverage($instId, $leverage, $marginMode);
        if ($base['mgnMode'] !== 'isolated') {
            return [$base];
        }

        return [
            $this->setLeverage($instId, $leverage, $marginMode, ExchangePositionSide::LONG),
            $this->setLeverage($instId, $leverage, $marginMode, ExchangePositionSide::SHORT),
        ];
    }

    public function clientOrderId(string $clientOrderId): string
    {
        $candidate = trim($clientOrderId);
        if (preg_match('/^[A-Za-z0-9]{1,32}$/', $candidate) === 1) {
            return $candidate;
        }

        return 'OKX' . substr(strtoupper(hash('sha256', $candidate)), 0, 29);
    }

    public function isTriggerOrder(PlaceOrderRequest $request): bool
    {
        return \in_array($request->orderType, [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER,
        ], true);
    }

    private function tdMode(string $marginMode): string
    {
        $mode = strtolower(trim($marginMode));

        return \in_array($mode, ['cross', 'isolated', 'cash'], true) ? $mode : 'cross';
    }

    private function posSide(ExchangePositionSide $side): string
    {
        return $side === ExchangePositionSide::SHORT ? 'short' : 'long';
    }

    private function ordType(PlaceOrderRequest $request): string
    {
        if ($request->orderType === ExchangeOrderType::MARKET) {
            return 'market';
        }
        if ($request->postOnly) {
            return 'post_only';
        }

        return match ($request->timeInForce) {
            ExchangeTimeInForce::IOC => 'ioc',
            ExchangeTimeInForce::FOK => 'fok',
            default => 'limit',
        };
    }

    /**
     * @return array<string,string>
     */
    private function priceFields(PlaceOrderRequest $request): array
    {
        if ($request->orderType === ExchangeOrderType::MARKET) {
            return [];
        }
        if ($request->price === null) {
            throw new \InvalidArgumentException('OKX limit orders require price');
        }

        return ['px' => $this->decimal($request->price)];
    }

    private function triggerKind(PlaceOrderRequest $request): string
    {
        $kind = strtolower(trim((string)($request->metadata['okx_trigger_kind'] ?? $request->metadata['tpsl'] ?? '')));
        if (\in_array($kind, ['tp', 'sl'], true)) {
            return $kind;
        }

        return 'sl';
    }

    private function decimal(float $value): string
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new \InvalidArgumentException('OKX decimal values must be positive and finite');
        }

        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }
}
