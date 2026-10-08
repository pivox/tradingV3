<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Support;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;

final class ScriptedExchangeAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    /** @var list<PlaceOrderRequest> */
    public array $placed = [];

    /** @var list<CancelOrderRequest> */
    public array $cancelled = [];

    /** @var list<array{string,int,string}> */
    public array $leverage = [];

    /** @var list<ExchangeOrderDto> */
    public array $openOrders = [];

    /** @var list<ExchangePositionDto> */
    public array $positions = [];

    /** @var list<ExchangeFillDto> */
    public array $fills = [];

    public ExchangeOrderStatus $placeStatus = ExchangeOrderStatus::PENDING;

    public bool $throwOnGetOrder = false;

    public bool $leverageResult = true;

    public bool $cancelAccepted = true;

    public int $cancelEffectiveOnAttempt = 1;

    private int $cancelAttempts = 0;

    /** @var (callable(): void)|null */
    public $onCancelEffective = null;

    public function exchange(): Exchange
    {
        return Exchange::OKX;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(supportsReduceOnly: true, supportsTriggerOrders: true, requiresSeparateLeverageSubmit: true, supportsPerSymbolLeverage: true);
    }

    public function getBalances(): array
    {
        return [];
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->positions;
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->openOrders;
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->placed[] = $request;
        $id = 'ord-' . \count($this->placed);
        $order = new ExchangeOrderDto(
            Exchange::OKX, MarketType::PERPETUAL, strtoupper($request->symbol), $id, $request->clientOrderId, $request->side, $request->positionSide,
            $request->orderType, $this->placeStatus, $request->quantity, 0.0, $request->quantity, $request->price, null, $request->stopPrice,
            $request->reduceOnly, $request->postOnly, $request->timeInForce, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        if ($request->reduceOnly) {
            $this->openOrders[] = $order;
        }

        return new PlaceOrderResult(true, $request->symbol, $request->clientOrderId, $id, $this->placeStatus, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), $order);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        $this->cancelled[] = $request;
        ++$this->cancelAttempts;
        if ($this->cancelAccepted && $this->cancelAttempts >= $this->cancelEffectiveOnAttempt) {
            $this->openOrders = array_values(array_filter(
                $this->openOrders,
                static fn (ExchangeOrderDto $o): bool => $o->exchangeOrderId !== $request->exchangeOrderId,
            ));
            if ($this->onCancelEffective !== null) {
                ($this->onCancelEffective)();
                $this->onCancelEffective = null;
            }
        }

        return new CancelOrderResult($this->cancelAccepted, $request->symbol, $request->exchangeOrderId, $request->clientOrderId, $this->cancelAccepted ? ExchangeOrderStatus::CANCELLED : ExchangeOrderStatus::REJECTED);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        if ($this->throwOnGetOrder) {
            throw new \RuntimeException('exchange down');
        }
        foreach ($this->openOrders as $order) {
            if ($order->exchangeOrderId === $exchangeOrderId) {
                return $order;
            }
        }

        return null;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return new SymbolBidAskDto(strtoupper($symbol), 99.0, 101.0, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        $this->leverage[] = [$symbol, $leverage, $marginMode];

        return $this->leverageResult;
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new ExchangeReconciliationResult(Exchange::OKX, MarketType::PERPETUAL, $symbol, $now, $now);
    }

    public function getOrdersSnapshot(?string $symbol = null): array
    {
        return $this->openOrders;
    }

    public function getFillsSnapshot(?string $symbol = null): array
    {
        return $this->fills;
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function order(string $id, ExchangeOrderStatus $status, float $filled, float $remaining, string $client = 'client-1', ExchangeOrderSide $side = ExchangeOrderSide::BUY, array $metadata = []): ExchangeOrderDto
    {
        return new ExchangeOrderDto(
            Exchange::OKX, MarketType::PERPETUAL, 'BTCUSDT', $id, $client, $side, ExchangePositionSide::LONG,
            ExchangeOrderType::LIMIT, $status, $filled + $remaining, $filled, $remaining, 100.0, $filled > 0 ? 100.5 : null, null,
            false, false, ExchangeTimeInForce::GTC, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, $metadata,
        );
    }

    public function position(float $size, float $entry = 100.0, ExchangePositionSide $side = ExchangePositionSide::LONG): ExchangePositionDto
    {
        return new ExchangePositionDto(Exchange::OKX, MarketType::PERPETUAL, 'BTCUSDT', $side, $size, $entry, null, null, null, null, null, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }
}
