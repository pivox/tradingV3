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

    protected function snapshotClientOrderId(string $clientOrderId): string
    {
        return $clientOrderId;
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
        $snapshotClientOrderId = $this->snapshotClientOrderId($clientOrderId);
        self::assertCount(1, array_filter(
            $openOrders,
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $snapshotClientOrderId,
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

    public function testFilledClientOrderIdReplayDoesNotCreateSecondPositionWhenAdapterExecutesImmediateFills(): void
    {
        if (!$this->marketOrdersFillImmediately()) {
            self::markTestSkipped('Adapter does not execute immediate fills in local contract tests.');
        }

        $adapter = $this->adapter();
        $clientOrderId = $this->clientOrderId('filled-replay');
        $first = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));
        $second = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        self::assertTrue($first->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $first->status);
        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $second->status);

        $positions = $adapter->getOpenPositions($this->symbol());
        self::assertCount(1, $positions);
        self::assertEqualsWithDelta(1.0, $positions[0]->size, 0.000001);

        if ($adapter instanceof ExchangeRestSnapshotProviderInterface) {
            $snapshotClientOrderId = $this->snapshotClientOrderId($clientOrderId);
            self::assertCount(1, array_filter(
                $adapter->getOrdersSnapshot($this->symbol()),
                static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $snapshotClientOrderId
                    && !$order->reduceOnly,
            ));
        }
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
            price: $this->restingLimitPrice(),
            postOnly: true,
        ));

        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        $snapshotClientOrderId = $this->snapshotClientOrderId($clientOrderId);
        self::assertCount(1, array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $snapshotClientOrderId,
        ));
    }

    public function testDuplicateClientOrderIdWithChangedIntentDoesNotCreateSecondActiveOrder(): void
    {
        $adapter = $this->adapter();
        $clientOrderId = $this->clientOrderId('duplicate-changed');

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

        if ($second->accepted) {
            self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        }

        $snapshotClientOrderId = $this->snapshotClientOrderId($clientOrderId);
        self::assertCount(1, array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $snapshotClientOrderId,
        ));
    }

    public function testStandaloneReduceOnlyStopCanBePlacedListedAndCancelledWhenSupported(): void
    {
        $adapter = $this->adapter();
        if (!$adapter->capabilities()->supportsTriggerOrders) {
            self::markTestSkipped('Adapter does not advertise standalone trigger orders.');
        }

        $clientOrderId = $this->clientOrderId('stop-listed');
        $stop = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            stopPrice: 24800.0,
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
        ));

        self::assertTrue($stop->accepted);
        self::assertSame($clientOrderId, $stop->clientOrderId);
        self::assertNotNull($stop->exchangeOrderId);

        $snapshotClientOrderId = $this->snapshotClientOrderId($clientOrderId);
        $listedStops = array_values(array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $snapshotClientOrderId
                && $order->orderType === ExchangeOrderType::STOP_LOSS
                && $order->reduceOnly
                && $order->stopPrice !== null,
        ));

        self::assertCount(1, $listedStops);
        self::assertSame($stop->exchangeOrderId, $listedStops[0]->exchangeOrderId);
        self::assertEqualsWithDelta(24800.0, $listedStops[0]->stopPrice, 0.000001);

        $cancelled = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $this->symbol(),
            exchangeOrderId: $adapter->capabilities()->supportsCancelByClientOrderId ? null : $stop->exchangeOrderId,
            clientOrderId: $clientOrderId,
        ));

        self::assertTrue($cancelled->cancelled);
        self::assertCount(0, array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->exchangeOrderId === $stop->exchangeOrderId,
        ));
    }

    public function testStandaloneReduceOnlyTakeProfitCanBePlacedListedAndCancelledWhenSupported(): void
    {
        $adapter = $this->adapter();
        if (!$adapter->capabilities()->supportsTriggerOrders) {
            self::markTestSkipped('Adapter does not advertise standalone trigger orders.');
        }

        $clientOrderId = $this->clientOrderId('take-profit-listed');
        $takeProfit = $adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::TAKE_PROFIT,
            price: null,
            stopPrice: 26000.0,
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
        ));

        self::assertTrue($takeProfit->accepted);
        self::assertSame($clientOrderId, $takeProfit->clientOrderId);
        self::assertNotNull($takeProfit->exchangeOrderId);

        $snapshotClientOrderId = $this->snapshotClientOrderId($clientOrderId);
        $listedTakeProfits = array_values(array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === $snapshotClientOrderId
                && $order->orderType === ExchangeOrderType::TAKE_PROFIT
                && $order->reduceOnly
                && $order->stopPrice !== null,
        ));

        self::assertCount(1, $listedTakeProfits);
        self::assertSame($takeProfit->exchangeOrderId, $listedTakeProfits[0]->exchangeOrderId);
        self::assertEqualsWithDelta(26000.0, $listedTakeProfits[0]->stopPrice, 0.000001);

        $cancelled = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $this->symbol(),
            exchangeOrderId: $adapter->capabilities()->supportsCancelByClientOrderId ? null : $takeProfit->exchangeOrderId,
            clientOrderId: $clientOrderId,
        ));

        self::assertTrue($cancelled->cancelled);
        self::assertCount(0, array_filter(
            $adapter->getOpenOrders($this->symbol()),
            static fn (ExchangeOrderDto $order): bool => $order->exchangeOrderId === $takeProfit->exchangeOrderId,
        ));
    }

    public function testAttachedProtectionOnEntryIsConfirmedWhenSupported(): void
    {
        $adapter = $this->adapter();
        $capabilities = $adapter->capabilities();
        if (!$capabilities->supportsAttachedStopLossOnEntry && !$capabilities->supportsAttachedTakeProfitOnEntry) {
            self::markTestSkipped('Adapter does not advertise attached entry protection.');
        }
        if (!$this->marketOrdersFillImmediately()) {
            self::markTestSkipped('Attached protection confirmation requires a locally filled entry.');
        }

        $stopLoss = $capabilities->supportsAttachedStopLossOnEntry ? 24800.0 : null;
        $takeProfit = $capabilities->supportsAttachedTakeProfitOnEntry ? 26000.0 : null;

        $placed = $adapter->placeOrder(new PlaceOrderRequest(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $this->symbol(),
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::IOC,
            quantity: 1.0,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $this->clientOrderId('attached-protection'),
            attachedStopLossPrice: $stopLoss,
            attachedTakeProfitPrice: $takeProfit,
        ));

        self::assertTrue($placed->accepted);
        self::assertNotNull($placed->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $placed->status);

        $openOrders = $adapter->getOpenOrders($this->symbol());
        if ($stopLoss !== null) {
            self::assertNotEmpty($this->matchingProtectionOrders(
                $openOrders,
                [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TRIGGER],
                $stopLoss,
                1.0,
            ), 'Attached stop-loss must be visible as a reduce-only protection order.');
        }
        if ($takeProfit !== null) {
            self::assertNotEmpty($this->matchingProtectionOrders(
                $openOrders,
                [ExchangeOrderType::TAKE_PROFIT, ExchangeOrderType::TRIGGER],
                $takeProfit,
                1.0,
            ), 'Attached take-profit must be visible as a reduce-only protection order.');
        }
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
        ?ExchangeTimeInForce $timeInForce = null,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $this->symbol(),
            side: $side,
            positionSide: ExchangePositionSide::LONG,
            orderType: $orderType,
            timeInForce: $timeInForce ?? ($orderType === ExchangeOrderType::MARKET ? ExchangeTimeInForce::IOC : ExchangeTimeInForce::GTC),
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

    /**
     * @param ExchangeOrderDto[] $orders
     * @param ExchangeOrderType[] $types
     * @return ExchangeOrderDto[]
     */
    private function matchingProtectionOrders(array $orders, array $types, float $stopPrice, float $minQuantity): array
    {
        return array_values(array_filter(
            $orders,
            static fn (ExchangeOrderDto $order): bool => $order->reduceOnly
                && $order->side === ExchangeOrderSide::SELL
                && $order->positionSide === ExchangePositionSide::LONG
                && \in_array($order->orderType, $types, true)
                && $order->stopPrice !== null
                && abs($order->stopPrice - $stopPrice) <= 0.000001
                && $order->remainingQuantity >= $minQuantity - 0.000001,
        ));
    }
}
