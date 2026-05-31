<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Contract;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use PHPUnit\Framework\TestCase;

abstract class ExchangeAdapterContractTestCase extends TestCase
{
    abstract protected function adapter(): ExchangeAdapterInterface;

    abstract protected function exchange(): Exchange;

    abstract protected function marketType(): MarketType;

    protected function symbol(): string
    {
        return 'BTCUSDT';
    }

    protected function restingLimitPrice(): float
    {
        return 24950.0;
    }

    protected function marketOrdersFillImmediately(): bool
    {
        return false;
    }

    public function testAdapterIdentityAndCapabilitiesAreConsistent(): void
    {
        $adapter = $this->adapter();
        $capabilities = $adapter->capabilities();

        self::assertSame($this->exchange(), $adapter->exchange());
        self::assertSame($this->marketType(), $adapter->marketType());
        self::assertTrue($capabilities->supportsClientOrderId);
        self::assertFalse($capabilities->supportsAttachedStopLossOnEntry && !$capabilities->supportsReduceOnly);
        self::assertFalse($capabilities->supportsTriggerOrders && !$capabilities->supportsReduceOnly);
    }

    public function testLimitOrderCanBePlacedListedFetchedAndCancelled(): void
    {
        $adapter = $this->adapter();
        $clientOrderId = $this->clientOrderId('limit');

        $placed = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        ));

        self::assertTrue($placed->accepted);
        self::assertContains($placed->status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
        ]);
        self::assertSame($clientOrderId, $placed->clientOrderId);
        self::assertNotNull($placed->exchangeOrderId);

        $openOrders = $adapter->getOpenOrders($this->symbol());
        self::assertCount(1, array_filter(
            $openOrders,
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $clientOrderId,
        ));
        self::assertNotNull($adapter->getOrder($this->symbol(), (string) $placed->exchangeOrderId));

        $cancelled = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $this->symbol(),
            exchangeOrderId: $placed->exchangeOrderId,
            clientOrderId: $clientOrderId,
        ));

        self::assertTrue($cancelled->cancelled);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $cancelled->status);
        self::assertCount(0, array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->exchangeOrderId === $placed->exchangeOrderId,
        ));
    }

    public function testMarketOrderCreatesPositionWhenAdapterExecutesImmediateFills(): void
    {
        if (!$this->marketOrdersFillImmediately()) {
            self::markTestSkipped('Adapter does not execute immediate fills in local contract tests.');
        }

        $adapter = $this->adapter();
        $placed = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $this->clientOrderId('market'),
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        self::assertTrue($placed->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $placed->status);
        self::assertEqualsWithDelta(1.0, $placed->order?->filledQuantity, 0.000001);

        $positions = $adapter->getOpenPositions($this->symbol());
        self::assertCount(1, $positions);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertGreaterThan(0.0, $positions[0]->size);
    }

    public function testDuplicateClientOrderIdDoesNotCreateSecondActiveOrder(): void
    {
        $adapter = $this->adapter();
        $clientOrderId = $this->clientOrderId('duplicate');

        $first = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        ));
        $second = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice() - 10.0,
            postOnly: true,
        ));

        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertCount(1, array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $clientOrderId,
        ));
    }

    public function testStandaloneReduceOnlyStopCanBeConfirmedWhenSupported(): void
    {
        $adapter = $this->adapter();
        if (!$adapter->capabilities()->supportsTriggerOrders) {
            self::markTestSkipped('Adapter does not advertise standalone trigger orders.');
        }
        if (!$this->marketOrdersFillImmediately()) {
            self::markTestSkipped('Contract stop confirmation requires a locally filled position.');
        }

        $adapter->placeOrder($this->placeRequest(
            clientOrderId: $this->clientOrderId('entry'),
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));
        $stop = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $this->clientOrderId('stop'),
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            stopPrice: 24800.0,
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
        ));

        self::assertTrue($stop->accepted);
        self::assertTrue($stop->order?->reduceOnly);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $stop->order?->orderType);

        $confirmed = array_values(array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->orderType === ExchangeOrderType::STOP_LOSS
                && $order->reduceOnly
                && $order->stopPrice !== null,
        ));
        self::assertCount(1, $confirmed);
    }

    public function testRestSnapshotProviderReportsOrdersPositionsAndFills(): void
    {
        $adapter = $this->adapter();
        if (!$adapter instanceof ExchangeRestSnapshotProviderInterface) {
            self::markTestSkipped('Adapter does not expose REST snapshot provider contract.');
        }
        if (!$this->marketOrdersFillImmediately()) {
            self::markTestSkipped('Snapshot fill assertion requires local immediate fills.');
        }

        $adapter->placeOrder($this->placeRequest(
            clientOrderId: $this->clientOrderId('snapshot'),
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        self::assertCount(1, $adapter->getOpenPositions($this->symbol()));
        self::assertGreaterThanOrEqual(1, \count($adapter->getFillsSnapshot($this->symbol())));

        $result = $adapter->reconcile($this->symbol());
        self::assertSame($this->exchange(), $result->exchange);
        self::assertSame($this->marketType(), $result->marketType);
        self::assertSame($this->symbol(), $result->symbol);
        self::assertGreaterThanOrEqual(1, $result->positionsChecked);
    }

    protected function clientOrderId(string $suffix): string
    {
        return 'contract-' . $suffix;
    }

    protected function placeRequest(
        string $clientOrderId,
        ExchangeOrderType $orderType,
        ?float $price,
        bool $postOnly,
        ?float $stopPrice = null,
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        bool $reduceOnly = false,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $this->symbol(),
            side: $side,
            positionSide: ExchangePositionSide::LONG,
            orderType: $orderType,
            timeInForce: $orderType === ExchangeOrderType::MARKET ? ExchangeTimeInForce::IOC : ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: $price,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
        );
    }
}
