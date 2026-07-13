<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\AbstractExchangeOrderEvent;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Okx\PrivateWebSocket\FillSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshot;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshotReconciler;
use App\Exchange\Okx\PrivateWebSocket\OrderSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\PositionSnapshotItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
        self::assertSame(['source' => 'okx_private_rest_snapshot'], $orderEvent->order()->metadata);
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

    public function testExactDuplicatesAreProjectedOnlyOnce(): void
    {
        $store = new SnapshotRecordingProjectionStore();
        $position = $this->position();
        $order = $this->order();
        $fill = $this->fill();

        $count = $this->reconciler($store)->reconcile($this->snapshot(
            positions: [$position, $position],
            orders: [$order, $order],
            fills: [$fill, $fill],
        ));

        self::assertSame(3, $count);
        self::assertCount(3, $store->events);
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

    private function order(string $quantity = '1'): OrderSnapshotItem
    {
        return new OrderSnapshotItem(
            'order-1',
            'BTCUSDT',
            'buy',
            'limit',
            'open',
            $quantity,
            '0',
            $quantity,
            '25000',
            null,
            new \DateTimeImmutable(self::NOW),
        );
    }

    private function fill(string $size = '0.25'): FillSnapshotItem
    {
        return new FillSnapshotItem(
            'okx',
            'BTCUSDT',
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
        );
    }
}

final class SnapshotRecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    /** @var list<ExchangeEventInterface> */
    public array $events = [];

    /** @var array<int,array{symbol: string, side: ExchangePositionSide, size: float}> */
    public array $localOpenPositions = [];

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

    public function project(ExchangeEventInterface $event): void
    {
        $this->events[] = $event;
    }
}
