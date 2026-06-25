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
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final readonly class FakeExchangeAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    private const FEE_RATE = 0.0005;

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

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOrdersSnapshot(?string $symbol = null): array
    {
        return $this->stateStore->getOrders($symbol);
    }

    /**
     * @return ExchangeFillDto[]
     */
    public function getFillsSnapshot(?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;
        $fills = [];
        foreach ($this->stateStore->events() as $index => $event) {
            if (!\in_array($event->type, ['order.filled', 'order.partially_filled'], true)) {
                continue;
            }
            if ($normalizedSymbol !== null && $event->symbol !== $normalizedSymbol) {
                continue;
            }

            $fill = $this->fillFromEvent($event, $index);
            if ($fill instanceof ExchangeFillDto) {
                $fills[] = $fill;
            }
        }

        return $fills;
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return true;
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

    private function fillFromEvent(FakeExchangeEvent $event, int $index): ?ExchangeFillDto
    {
        $orderId = $event->payload['order_id'] ?? null;
        if (!\is_scalar($orderId) || trim((string)$orderId) === '') {
            return null;
        }

        $order = $this->stateStore->getOrder((string)$orderId);
        if (!$order instanceof ExchangeOrderDto) {
            return null;
        }

        $fillQuantity = isset($event->payload['fill_quantity']) && is_numeric($event->payload['fill_quantity'])
            ? (float)$event->payload['fill_quantity']
            : max(0.0, $order->filledQuantity);
        $fillPrice = isset($event->payload['fill_price']) && is_numeric($event->payload['fill_price'])
            ? (float)$event->payload['fill_price']
            : ($order->averagePrice ?? $order->price ?? 0.0);
        $fee = isset($event->payload['fill_fee']) && is_numeric($event->payload['fill_fee'])
            ? (float) $event->payload['fill_fee']
            : $this->fillFee($fillQuantity, $fillPrice);

        return new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            fillId: 'fake-fill-' . substr(hash('sha256', implode(':', [
                (string)($event->payload['event_sequence'] ?? $index),
                $event->type,
                $order->exchangeOrderId,
                $event->occurredAt->format('U.u'),
                (string)$fillQuantity,
                (string)$fillPrice,
            ])), 0, 32),
            side: $order->side,
            positionSide: $order->positionSide,
            quantity: $fillQuantity,
            price: $fillPrice,
            fee: $fee,
            feeCurrency: 'USDT',
            filledAt: $event->occurredAt,
            metadata: [
                'source' => 'fake_exchange_rest_reconciliation',
                'pnl_source' => 'fake_paper_fill_ledger_v1',
                'cost_completeness' => 'complete',
            ],
        );
    }

    private function fillFee(float $quantity, float $price): float
    {
        return round($quantity * $price * self::FEE_RATE, 12);
    }
}
