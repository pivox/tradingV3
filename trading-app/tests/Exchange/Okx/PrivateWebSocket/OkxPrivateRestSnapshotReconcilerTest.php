<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\AbstractExchangeOrderEvent;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\PrivateWebSocket\FillSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshot;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshotReconciler;
use App\Exchange\Okx\PrivateWebSocket\OrderSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\PositionSnapshotItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxPrivateRestSnapshotReconciler::class)]
final class OkxPrivateRestSnapshotReconcilerTest extends TestCase
{
    private const NOW = '2026-07-13T10:00:00+00:00';

    public function testProjectsOrdersPositionsAndFillsWithMinimalSourceData(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $reconciler = $this->reconciler($store);

        $count = $reconciler->reconcile($this->snapshot(
            positions: [$this->position()],
            orders: [$this->order()],
            fills: [$this->fill()],
        ));

        self::assertSame(3, $count);
        self::assertCount(3, $store->events);
        self::assertInstanceOf(AbstractExchangeOrderEvent::class, $store->events[0]);
        self::assertInstanceOf(ExchangePositionUpdated::class, $store->events[1]);
        self::assertInstanceOf(ExchangeFillReceived::class, $store->events[2]);
        foreach ($store->events as $event) {
            self::assertSame(Exchange::OKX, $event->exchange());
            self::assertSame(MarketType::PERPETUAL, $event->marketType());
            self::assertSame(['source' => 'okx_private_rest_snapshot'], $event->payload());
        }

        $orderEvent = $store->events[0];
        self::assertInstanceOf(AbstractExchangeOrderEvent::class, $orderEvent);
        self::assertNull($orderEvent->order()->clientOrderId);
        self::assertNull($orderEvent->order()->positionSide);
        self::assertFalse($orderEvent->order()->reduceOnly);
        self::assertFalse($orderEvent->order()->postOnly);
        self::assertSame([
            'source' => 'okx_private_rest_snapshot',
            'quantity_decimal' => '1',
            'filled_quantity_decimal' => '0',
            'remaining_quantity_decimal' => '1',
        ], $orderEvent->order()->metadata);
    }

    public function testEmptyAuthoritativeSnapshotClosesMissingLocalPosition(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $store->localOpenPositions = [[
            'symbol' => 'BTCUSDT',
            'side' => ExchangePositionSide::LONG,
            'size' => 0.25,
        ]];

        $count = $this->reconciler($store)->reconcile($this->snapshot());

        self::assertSame(1, $count);
        self::assertInstanceOf(ExchangePositionClosed::class, $store->events[0]);
        self::assertSame([
            'source' => 'okx_private_rest_snapshot',
            'reason' => 'missing_from_rest_position_snapshot',
        ], $store->events[0]->payload());
    }

    public function testCompleteSnapshotProjectsMissingLocalOpenOrderAsUnknown(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $store->localOpenOrders = [$this->localOrder()];

        self::assertSame(1, $this->reconciler($store)->reconcile($this->snapshot()));
        self::assertInstanceOf(AbstractExchangeOrderEvent::class, $store->events[0]);
        self::assertSame(ExchangeOrderStatus::UNKNOWN, $store->events[0]->order()->status);
        self::assertSame('snapshot_order_missing', $store->events[0]->order()->metadata['quality_flag']);
        self::assertSame('snapshot_order_missing', $store->events[0]->payload()['reason']);
    }

    public function testIncompleteSnapshotDoesNotProjectMissingLocalOrder(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $store->localOpenOrders = [$this->localOrder()];
        $snapshot = new OkxPrivateRestSnapshot(new \DateTimeImmutable(self::NOW), false, [], [], [], false, ['okx_private_rest_orders_snapshot_failed']);

        $this->expectException(\InvalidArgumentException::class);
        $this->reconciler($store)->reconcile($snapshot);
        self::assertSame([], $store->events);
    }

    public function testPresentLocalOrderIsNotReconciled(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $store->localOpenOrders = [$this->localOrder()];

        self::assertSame(1, $this->reconciler($store)->reconcile($this->snapshot(orders: [$this->order()])));
        $event = $store->events[0];
        self::assertInstanceOf(AbstractExchangeOrderEvent::class, $event);
        self::assertSame(ExchangeOrderStatus::OPEN, $event->order()->status);
    }

    private function localOrder(): ExchangeOrderDto
    {
        return new ExchangeOrderDto(Exchange::OKX, MarketType::PERPETUAL, 'BTCUSDT', 'order-1', null, ExchangeOrderSide::BUY, null, ExchangeOrderType::LIMIT, ExchangeOrderStatus::OPEN, 1.0, 0.0, 1.0, 25000.0, null, null, false, false, null, new \DateTimeImmutable(self::NOW));
    }

    public function testExactDuplicatesAreProjectedOnlyOnce(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $position = $this->position();
        $order = $this->order(clientOrderId: 'client-1');
        $fill = $this->fill();

        $count = $this->reconciler($store)->reconcile($this->snapshot(
            positions: [$position, $position],
            orders: [$order, $order],
            fills: [$fill, $fill],
        ));

        self::assertSame(3, $count);
        self::assertCount(3, $store->events);
    }

    public function testRestAndWebSocketFillUseTheSameInstrumentScopedFillId(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $restFill = FillSnapshotItem::fromProviderArray([
            'exchange' => 'okx',
            'instrument_id' => 'BTC-USDT-SWAP',
            'symbol' => 'BTCUSDT',
            'order_id' => 'order-1',
            'trade_id' => 'trade-1',
            'side' => 'buy',
            'position_side' => 'long',
            'size' => '0.25',
            'price' => '25000',
            'create_time' => 1783936800000,
        ]);
        $this->reconciler($store)->reconcile($this->snapshot(fills: [$restFill]));

        $restEvent = $store->events[0];
        self::assertInstanceOf(ExchangeFillReceived::class, $restEvent);
        $wsEvents = (new OkxExchangeEventNormalizer(
            new OkxInstrumentResolver(),
            new MockClock('2026-07-13T10:00:00Z'),
        ))->normalize([
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
            'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'order-1',
                'tradeId' => 'trade-1',
                'side' => 'buy',
                'posSide' => 'long',
                'fillSz' => '0.25',
                'fillPx' => '25000',
                'fillTime' => '1783936800000',
            ]],
        ]);
        self::assertCount(1, $wsEvents);
        self::assertInstanceOf(ExchangeFillReceived::class, $wsEvents[0]);
        self::assertSame($wsEvents[0]->fill()->fillId, $restEvent->fill()->fillId);
    }

    public function testSameTradeIdOnTwoInstrumentsProducesTwoFills(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $btc = $this->fill(instrumentId: 'BTC-USDT-SWAP', symbol: 'BTCUSDT');
        $eth = $this->fill(instrumentId: 'ETH-USDT-SWAP', symbol: 'ETHUSDT');

        $count = $this->reconciler($store)->reconcile($this->snapshot(fills: [$btc, $eth]));

        self::assertSame(2, $count);
        self::assertCount(2, $store->events);
        $btcEvent = $store->events[0];
        $ethEvent = $store->events[1];
        self::assertInstanceOf(ExchangeFillReceived::class, $btcEvent);
        self::assertInstanceOf(ExchangeFillReceived::class, $ethEvent);
        self::assertNotSame(
            $btcEvent->fill()->fillId,
            $ethEvent->fill()->fillId,
        );
    }

    public function testMapsProviderStopOrderToGenericTrigger(): void
    {
        $store = new SnapshotRecordingProjectionStore();

        $count = $this->reconciler($store)->reconcile($this->snapshot(
            orders: [$this->order(type: 'stop')],
        ));

        self::assertSame(1, $count);
        self::assertCount(1, $store->events);
        $event = $store->events[0];
        self::assertInstanceOf(AbstractExchangeOrderEvent::class, $event);
        self::assertSame(ExchangeOrderType::TRIGGER, $event->order()->orderType);
    }

    public function testProjectsCompleteAllowlistedProtectiveOrder(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $updatedAt = new \DateTimeImmutable('2026-07-13T10:01:00Z');
        $item = new OrderSnapshotItem(
            orderId: 'algo:algo-1',
            symbol: 'BTCUSDT',
            side: 'sell',
            type: 'stop_loss',
            status: 'partially_filled',
            quantity: '1',
            filledQuantity: '0.4',
            remainingQuantity: '0.6',
            price: null,
            stopPrice: '24000',
            createdAt: new \DateTimeImmutable(self::NOW),
            clientOrderId: 'algo-client-1',
            positionSide: 'long',
            reduceOnly: true,
            postOnly: false,
            averagePrice: '24500',
            updatedAt: $updatedAt,
            timeInForce: 'fok',
            openType: 'isolated',
            leverage: '3',
        );

        $this->reconciler($store)->reconcile($this->snapshot(orders: [$item]));

        $event = $store->events[0];
        self::assertInstanceOf(AbstractExchangeOrderEvent::class, $event);
        $order = $event->order();
        self::assertSame('algo-client-1', $order->clientOrderId);
        self::assertSame(ExchangePositionSide::LONG, $order->positionSide);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $order->orderType);
        self::assertTrue($order->reduceOnly);
        self::assertFalse($order->postOnly);
        self::assertSame(24500.0, $order->averagePrice);
        self::assertSame(24000.0, $order->stopPrice);
        self::assertEquals($updatedAt, $order->updatedAt);
        self::assertSame(ExchangeTimeInForce::FOK, $order->timeInForce);
        self::assertSame([
            'source' => 'okx_private_rest_snapshot',
            'open_type' => 'isolated',
            'leverage' => '3',
            'quantity_decimal' => '1',
            'filled_quantity_decimal' => '0.4',
            'remaining_quantity_decimal' => '0.6',
        ], $order->metadata);
    }

    public function testNumericallyEquivalentButTextuallyDifferentDuplicateIsAConflict(): void
    {
        $store = new SnapshotRecordingProjectionStore();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_duplicate_conflict');
        try {
            $this->reconciler($store)->reconcile($this->snapshot(
                orders: [$this->order(quantity: '1'), $this->order(quantity: '1.0')],
            ));
        } finally {
            self::assertSame([], $store->events);
        }
    }

    public function testConflictingDuplicateFailsBeforeAnyProjection(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $conflict = $this->order(quantity: '2');

        try {
            $this->reconciler($store)->reconcile($this->snapshot(
                orders: [$this->order(), $conflict],
            ));
            self::fail('Conflicting duplicate should fail.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('okx_private_rest_snapshot_duplicate_conflict', $error->getMessage());
        }

        self::assertSame([], $store->events);
    }

    public function testDistinctOrderIdsWithSameClientOrderIdFailBeforeProjection(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $first = $this->order(orderId: 'order-1', clientOrderId: 'client-1');
        $second = $this->order(orderId: 'order-2', clientOrderId: 'client-1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_duplicate_conflict');
        try {
            $this->reconciler($store)->reconcile($this->snapshot(orders: [$first, $second]));
        } finally {
            self::assertSame([], $store->events);
        }
    }

    public function testStandardAndAlgoOrderIdsWithSameClientOrderIdFailBeforeProjection(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $standard = $this->order(orderId: 'order-1', clientOrderId: 'client-1');
        $algo = $this->order(orderId: 'algo:algo-1', clientOrderId: 'client-1', type: 'stop_loss');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_duplicate_conflict');
        try {
            $this->reconciler($store)->reconcile($this->snapshot(orders: [$standard, $algo]));
        } finally {
            self::assertSame([], $store->events);
        }
    }

    public function testMultipleOrdersWithoutClientOrderIdRemainAllowed(): void
    {
        $store = new SnapshotRecordingProjectionStore();

        $count = $this->reconciler($store)->reconcile($this->snapshot(orders: [
            $this->order(orderId: 'order-1'),
            $this->order(orderId: 'order-2'),
        ]));

        self::assertSame(2, $count);
        self::assertCount(2, $store->events);
    }

    public function testInvalidEnumFailsBeforeAnyProjection(): void
    {
        $store = new SnapshotRecordingProjectionStore();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_value_invalid');
        try {
            $this->reconciler($store)->reconcile($this->snapshot(
                positions: [$this->position(side: 'both')],
            ));
        } finally {
            self::assertSame([], $store->events);
        }
    }

    public function testInvalidNumericValueFailsBeforeAnyProjection(): void
    {
        $store = new SnapshotRecordingProjectionStore();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_value_invalid');
        try {
            $this->reconciler($store)->reconcile($this->snapshot(
                fills: [$this->fill(size: 'NaN')],
            ));
        } finally {
            self::assertSame([], $store->events);
        }
    }

    /**
     * @param array{quantity: string, filled: string, remaining: string, status: string} $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrderQuantityInvariantProvider')]
    public function testRejectsInvalidOrderQuantityInvariants(array $values): void
    {
        $store = new SnapshotRecordingProjectionStore();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_value_invalid');
        try {
            $this->reconciler($store)->reconcile($this->snapshot(orders: [new OrderSnapshotItem(
                orderId: 'order-1',
                symbol: 'BTCUSDT',
                side: 'buy',
                type: 'limit',
                status: $values['status'],
                quantity: $values['quantity'],
                filledQuantity: $values['filled'],
                remainingQuantity: $values['remaining'],
                price: '25000',
                stopPrice: null,
                createdAt: new \DateTimeImmutable(self::NOW),
            )]));
        } finally {
            self::assertSame([], $store->events);
        }
    }

    /**
     * @return iterable<string,array{array{quantity: string, filled: string, remaining: string, status: string}}>
     */
    public static function invalidOrderQuantityInvariantProvider(): iterable
    {
        yield 'exact sum mismatch' => [[
            'quantity' => '1',
            'filled' => '0.7',
            'remaining' => '0.4',
            'status' => 'partially_filled',
        ]];
        yield 'filled exceeds quantity' => [[
            'quantity' => '1',
            'filled' => '1.1',
            'remaining' => '0',
            'status' => 'open',
        ]];
        yield 'partial status without a fill' => [[
            'quantity' => '1',
            'filled' => '0',
            'remaining' => '1',
            'status' => 'partially_filled',
        ]];
    }

    public function testAmbiguousUnknownOrderStatusFailsBeforeAnyProjection(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $order = new OrderSnapshotItem(
            'order-1',
            'BTCUSDT',
            'buy',
            'limit',
            'unknown',
            '1',
            '0',
            '1',
            '25000',
            null,
            new \DateTimeImmutable(self::NOW),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_value_invalid');
        try {
            $this->reconciler($store)->reconcile($this->snapshot(orders: [$order]));
        } finally {
            self::assertSame([], $store->events);
        }
    }

    private function reconciler(SnapshotRecordingProjectionStore $store): OkxPrivateRestSnapshotReconciler
    {
        return new OkxPrivateRestSnapshotReconciler(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
        );
    }

    /**
     * @param list<PositionSnapshotItem> $positions
     * @param list<OrderSnapshotItem> $orders
     * @param list<FillSnapshotItem> $fills
     */
    private function snapshot(array $positions = [], array $orders = [], array $fills = []): OkxPrivateRestSnapshot
    {
        return new OkxPrivateRestSnapshot(
            observedAt: new \DateTimeImmutable(self::NOW),
            accountReadable: true,
            positions: $positions,
            openOrders: $orders,
            fills: $fills,
            complete: true,
            blockingErrors: [],
        );
    }

    private function position(string $side = 'long'): PositionSnapshotItem
    {
        return new PositionSnapshotItem('BTCUSDT', $side, '0.25', '25000', '25100', new \DateTimeImmutable(self::NOW));
    }

    private function order(
        string $quantity = '1',
        string $type = 'limit',
        string $orderId = 'order-1',
        ?string $clientOrderId = null,
    ): OrderSnapshotItem
    {
        return new OrderSnapshotItem(
            $orderId,
            'BTCUSDT',
            'buy',
            $type,
            'open',
            $quantity,
            '0',
            $quantity,
            '25000',
            null,
            new \DateTimeImmutable(self::NOW),
            $clientOrderId,
        );
    }

    private function fill(
        string $size = '0.25',
        string $instrumentId = 'BTC-USDT-SWAP',
        string $symbol = 'BTCUSDT',
    ): FillSnapshotItem
    {
        return new FillSnapshotItem(
            'okx',
            $symbol,
            'order-1',
            'client-1',
            'trade-1',
            'buy',
            'long',
            $size,
            '25000',
            '-0.01',
            'USDT',
            new \DateTimeImmutable(self::NOW),
            $instrumentId,
        );
    }
}

final class SnapshotRecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    /** @var list<ExchangeEventInterface> */
    public array $events = [];

    /** @var array<int,array{symbol: string, side: ExchangePositionSide, size: float}> */
    public array $localOpenPositions = [];

    /** @var list<ExchangeOrderDto> */
    public array $localOpenOrders = [];

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return false;
    }

    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array
    {
        if ($exchange !== Exchange::OKX || $marketType !== MarketType::PERPETUAL || $symbol !== null) {
            throw new \LogicException('Unexpected projection query scope.');
        }

        return $this->localOpenPositions;
    }

    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        return $exchange === Exchange::OKX && $marketType === MarketType::PERPETUAL ? $this->localOpenOrders : [];
    }

    public function project(ExchangeEventInterface $event): void
    {
        $this->events[] = $event;
    }
}
