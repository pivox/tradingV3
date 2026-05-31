<?php

declare(strict_types=1);

namespace App\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final readonly class FakeExchangeAdapter implements ExchangeAdapterInterface
{
    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private FakeExchangeOrderBook $orderBook,
        private FakeExchangeMatchingEngine $matchingEngine,
        private ClockInterface $clock,
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::FAKE;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsTestnet: true,
            supportsWebSocketPrivate: true,
            supportsClientOrderId: true,
            supportsCancelByClientOrderId: true,
            supportsPostOnly: true,
            supportsIoc: true,
            supportsReduceOnly: true,
            supportsAttachedStopLossOnEntry: true,
            supportsAttachedTakeProfitOnEntry: true,
            supportsTriggerOrders: true,
            supportsModifyOrder: false,
            requiresSeparateLeverageSubmit: false,
            supportsPerSymbolLeverage: true,
        );
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->stateStore->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->stateStore->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->stateStore->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        return $this->matchingEngine->submit($request);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return $this->matchingEngine->cancel($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        $order = $this->stateStore->getOrder($exchangeOrderId);
        if (!$order instanceof ExchangeOrderDto || $order->symbol !== strtoupper($symbol)) {
            return null;
        }

        return $order;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->orderBook->top($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        if (trim($symbol) === '' || $leverage <= 0 || trim($marginMode) === '') {
            return false;
        }

        return true;
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        $startedAt = $this->clock->now();

        return new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol !== null ? strtoupper($symbol) : null,
            startedAt: $startedAt,
            completedAt: $this->clock->now(),
            ordersChecked: $this->stateStore->openOrderCount($symbol),
            positionsChecked: $this->stateStore->openPositionCount($symbol),
            metadata: [
                'source' => 'fake_exchange',
                'events_seen' => \count($this->stateStore->events()),
            ],
        );
    }
}
