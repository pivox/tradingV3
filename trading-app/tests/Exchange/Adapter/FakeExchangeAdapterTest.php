<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeExchangeAdapter::class)]
#[CoversClass(FakeExchangeMatchingEngine::class)]
#[CoversClass(FakeExchangeOrderBook::class)]
#[CoversClass(FakeExchangeScenarioService::class)]
#[CoversClass(FakeExchangeStateStore::class)]
final class FakeExchangeAdapterTest extends TestCase
{
    private FakeExchangeAdapter $adapter;
    private FakeExchangeScenarioService $scenario;

    protected function setUp(): void
    {
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());

        $this->adapter = new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
        $this->scenario = new FakeExchangeScenarioService($state, $book, $engine);
    }

    public function testPlaceLimitOrderLeavesOpenMakerOrder(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::LIMIT,
            price: 24950.0,
            postOnly: true,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::OPEN, $result->status);
        self::assertSame('cid-1', $result->order?->clientOrderId);
        self::assertCount(1, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testCancelOrderClosesOpenOrder(): void
    {
        $placed = $this->adapter->placeOrder($this->request(price: 24950.0, postOnly: true));

        $cancelled = $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $placed->exchangeOrderId,
        ));

        self::assertTrue($cancelled->cancelled);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $cancelled->status);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testMovePriceFillsLimitOrderAndCreatesPosition(): void
    {
        $placed = $this->adapter->placeOrder($this->request(price: 25000.0));

        $result = $this->scenario->movePrice('BTCUSDT', 24990.0, 0.0);
        $filled = $this->adapter->getOrder('BTCUSDT', (string) $placed->exchangeOrderId);
        $positions = $this->adapter->getOpenPositions('BTCUSDT');

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderStatus::FILLED, $filled?->status);
        self::assertCount(1, $positions);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertSame(1.0, $positions[0]->size);
    }

    public function testMarketOrderFillsImmediately(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertSame(1.0, $result->order?->filledQuantity);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testNonCrossingIocLimitExpiresWithoutResting(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::LIMIT,
            price: 24950.0,
            postOnly: false,
            timeInForce: ExchangeTimeInForce::IOC,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::EXPIRED, $result->status);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertSame('immediate_execution_not_available', $result->order?->metadata['reason'] ?? null);
    }

    public function testCanPartiallyFillThenCompleteOrder(): void
    {
        $placed = $this->adapter->placeOrder($this->request(price: 24950.0, postOnly: true));

        $partial = $this->scenario->fillOrder((string) $placed->exchangeOrderId, 0.4, 24950.0);
        self::assertSame(ExchangeOrderStatus::PARTIALLY_FILLED, $partial?->status);
        self::assertEqualsWithDelta(0.4, $partial?->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $partial?->remainingQuantity, 0.000001);

        $complete = $this->scenario->fillOrder((string) $placed->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $complete?->status);
        self::assertEqualsWithDelta(1.0, $complete?->filledQuantity, 0.000001);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testAcceptedAttachedProtectionCreatesReduceOnlyStopOrder(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));

        $openOrders = $this->adapter->getOpenOrders('BTCUSDT');

        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertSame('accepted', $result->order?->metadata['protection_status'] ?? null);
        self::assertCount(1, $openOrders);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $openOrders[0]->orderType);
        self::assertTrue($openOrders[0]->reduceOnly);
        self::assertCount(1, $this->scenario->events('protection_order.created'));
    }

    public function testRejectedAttachedProtectionKeepsEntryFillAndEmitsEvent(): void
    {
        $this->scenario->rejectNextProtectionOrder();

        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertSame('rejected', $result->order?->metadata['protection_status'] ?? null);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $this->scenario->events('protection_order.rejected'));
    }

    public function testMovePriceTriggersAttachedStopLossAndClosesPosition(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));

        $result = $this->scenario->movePrice('BTCUSDT', 24790.0, 0.0);

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $result['matched_orders'][0]->orderType);
        self::assertSame(ExchangeOrderStatus::FILLED, $result['matched_orders'][0]->status);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $this->scenario->events('position.closed'));
    }

    public function testMovePriceTriggersAttachedTakeProfitAndClosesPosition(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedTakeProfitPrice: 25200.0,
        ));

        $result = $this->scenario->movePrice('BTCUSDT', 25210.0, 0.0);

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderType::TAKE_PROFIT, $result['matched_orders'][0]->orderType);
        self::assertSame(ExchangeOrderStatus::FILLED, $result['matched_orders'][0]->status);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testReduceOnlyProtectionFillIsCappedToRemainingPositionSize(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'manual-reduce',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            quantity: 0.6,
        ));

        $result = $this->scenario->movePrice('BTCUSDT', 24790.0, 0.0);

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $result['matched_orders'][0]->status);
        self::assertEqualsWithDelta(0.4, $result['matched_orders'][0]->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $result['matched_orders'][0]->remainingQuantity, 0.000001);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testTriggeringOneAttachedProtectionCancelsSiblingOrder(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
            attachedTakeProfitPrice: 25200.0,
        ));
        self::assertCount(2, $this->adapter->getOpenOrders('BTCUSDT'));

        $this->scenario->movePrice('BTCUSDT', 24790.0, 0.0);

        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(1, array_filter(
            $this->scenario->events('order.cancelled'),
            static fn ($event): bool => ($event->payload['reason'] ?? null) === 'sibling_protection_filled',
        ));
    }

    public function testStandaloneProtectionOrderIsReduceOnlyEvenWhenPayloadOmitsFlag(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            side: ExchangeOrderSide::SELL,
            reduceOnly: false,
            postOnly: false,
            stopPrice: 24800.0,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::OPEN, $result->status);
        self::assertTrue($result->order?->reduceOnly);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $result->order?->orderType);
        self::assertCount(1, $this->scenario->events('protection_order.created'));
    }

    public function testStandaloneProtectionOrderRequiresStopPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trigger orders require a stop price');

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            side: ExchangeOrderSide::SELL,
            reduceOnly: false,
            postOnly: false,
        ));
    }

    public function testReduceOnlyOrderRejectsAttachedProtection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attached SL/TP is only supported for entry orders');

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));
    }

    public function testClientOrderIdReplayDoesNotCreateSecondActiveOrder(): void
    {
        $first = $this->adapter->placeOrder($this->request(price: 24950.0, postOnly: true));
        $second = $this->adapter->placeOrder($this->request(price: 24900.0, postOnly: true));

        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertTrue($second->metadata['idempotent_replay'] ?? false);
        self::assertCount(1, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testCrossingLimitFillsAtBookPriceInsteadOfLimitPrice(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::LIMIT,
            price: 26000.0,
            postOnly: false,
        ));
        $position = $this->adapter->getOpenPositions('BTCUSDT')[0] ?? null;

        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertEqualsWithDelta(25000.5, $result->order?->averagePrice, 0.000001);
        self::assertEqualsWithDelta(25000.5, $position?->entryPrice, 0.000001);
    }

    public function testCancelWithWrongSymbolDoesNotCancelExchangeOrderId(): void
    {
        $placed = $this->adapter->placeOrder($this->request(
            symbol: 'ETHUSDT',
            price: 1800.0,
            postOnly: true,
        ));

        $cancelled = $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $placed->exchangeOrderId,
        ));

        self::assertFalse($cancelled->cancelled);
        self::assertSame(ExchangeOrderStatus::UNKNOWN, $cancelled->status);
        self::assertCount(1, $this->adapter->getOpenOrders('ETHUSDT'));
    }

    public function testPartialReduceRecomputesRemainingPositionMargin(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'reduce-1',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            quantity: 0.4,
        ));
        $position = $this->adapter->getOpenPositions('BTCUSDT')[0] ?? null;

        self::assertNotNull($position);
        self::assertEqualsWithDelta(0.6, $position->size, 0.000001);
        self::assertEqualsWithDelta(($position->entryPrice * 0.6) / 3.0, $position->margin, 0.000001);
    }

    private function request(
        string $symbol = 'BTCUSDT',
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ?float $price = 24950.0,
        string $clientOrderId = 'cid-1',
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        bool $reduceOnly = false,
        bool $postOnly = false,
        ExchangeTimeInForce $timeInForce = ExchangeTimeInForce::GTC,
        float $quantity = 1.0,
        ?float $stopPrice = null,
        ?float $attachedStopLossPrice = null,
        ?float $attachedTakeProfitPrice = null,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: $timeInForce,
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachedStopLossPrice,
            attachedTakeProfitPrice: $attachedTakeProfitPrice,
        );
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        };
    }
}
