<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Exchange\Okx\PrivateWebSocket\FillSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\OkxGatewayPrivateRestReader;
use App\Exchange\Okx\PrivateWebSocket\OkxGatewayPrivateRestSnapshotSource;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestGatewayReaderInterface;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshot;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshotProbe;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshotSourceInterface;
use App\Exchange\Okx\PrivateWebSocket\OrderSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\PositionSnapshotItem;
use App\Provider\Okx\OkxAccountGateway;
use App\Provider\Okx\OkxOrderGateway;
use App\Provider\Okx\OkxPrivateReadMapper;
use App\Provider\Okx\OkxProviderUnavailableException;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(OkxGatewayPrivateRestSnapshotSource::class)]
#[CoversClass(OkxGatewayPrivateRestReader::class)]
#[CoversClass(OkxPrivateRestSnapshot::class)]
#[CoversClass(OkxPrivateRestSnapshotProbe::class)]
#[CoversClass(PositionSnapshotItem::class)]
#[CoversClass(OrderSnapshotItem::class)]
#[CoversClass(FillSnapshotItem::class)]
final class OkxPrivateRestSnapshotProbeTest extends TestCase
{
    private const NOW = '2026-07-13T10:00:00+00:00';

    public function testAcceptsAnEmptyButCompleteSnapshot(): void
    {
        $source = new FakeOkxPrivateRestSnapshotSource();

        $snapshot = (new OkxPrivateRestSnapshotProbe($source))->probe(new DateTimeImmutable(self::NOW));

        self::assertSame(self::NOW, $snapshot->observedAt->format(DATE_ATOM));
        self::assertTrue($snapshot->accountReadable);
        self::assertSame([], $snapshot->positions);
        self::assertSame([], $snapshot->openOrders);
        self::assertSame([], $snapshot->fills);
        self::assertTrue($snapshot->complete);
        self::assertSame([], $snapshot->blockingErrors);
        self::assertSame(['account', 'positions', 'open_orders', 'fills'], $source->readCalls);
    }

    public function testPreservesSnapshotItemsAndConvertsObservedAtToUtc(): void
    {
        $position = self::positionItem();
        $order = self::orderItem();
        $fill = self::fillItem();
        $source = new FakeOkxPrivateRestSnapshotSource(
            positions: [$position],
            openOrders: [$order],
            fills: [$fill],
        );

        $snapshot = (new OkxPrivateRestSnapshotProbe($source))->probe(
            new DateTimeImmutable('2026-07-13T12:00:00+02:00'),
        );

        self::assertSame('UTC', $snapshot->observedAt->getTimezone()->getName());
        self::assertSame(self::NOW, $snapshot->observedAt->format(DATE_ATOM));
        self::assertSame($position, $snapshot->positions[0]);
        self::assertSame($order, $snapshot->openOrders[0]);
        self::assertSame($fill, $snapshot->fills[0]);
        self::assertTrue($snapshot->complete);
    }

    public function testProjectsOnlyAllowlistedFieldsFromLeakyProviderValues(): void
    {
        $position = PositionSnapshotItem::fromProviderDto(
            self::position(['metadata' => 'demo-secret', 'unknown' => 'demo-secret']),
        );
        $order = OrderSnapshotItem::fromProviderDto(
            self::order(['metadata' => 'demo-secret', 'unknown' => 'demo-secret']),
        );
        $fill = FillSnapshotItem::fromProviderArray([
            'exchange' => 'okx',
            'symbol' => 'BTCUSDT',
            'order_id' => 'order-1',
            'client_order_id' => 'client-1',
            'trade_id' => 'trade-1',
            'side' => 'buy',
            'position_side' => 'long',
            'size' => '0.25',
            'price' => '25000.5',
            'fee' => '-0.01',
            'fee_currency' => 'USDT',
            'create_time' => 1783936800000,
            'raw_reference' => ['api_secret' => 'demo-secret'],
            'unknown' => 'demo-secret',
        ]);
        $snapshot = new OkxPrivateRestSnapshot(
            observedAt: new DateTimeImmutable(self::NOW),
            accountReadable: true,
            positions: [$position],
            openOrders: [$order],
            fills: [$fill],
            complete: true,
            blockingErrors: [],
        );

        self::assertSame('BTCUSDT', $position->symbol);
        self::assertSame('long', $position->side);
        self::assertSame('0.25', $position->size);
        self::assertSame('25000', $position->entryPrice);
        self::assertSame('25100', $position->markPrice);
        self::assertSame('UTC', $position->openedAt->getTimezone()->getName());
        self::assertSame('order-1', $order->orderId);
        self::assertSame('buy', $order->side);
        self::assertSame('limit', $order->type);
        self::assertSame('pending', $order->status);
        self::assertSame('0.25', $order->quantity);
        self::assertSame('0', $order->filledQuantity);
        self::assertSame('0.25', $order->remainingQuantity);
        self::assertSame('25000', $order->price);
        self::assertNull($order->stopPrice);
        self::assertSame('UTC', $order->createdAt->getTimezone()->getName());
        self::assertSame('okx', $fill->exchange);
        self::assertSame('order-1', $fill->orderId);
        self::assertSame('client-1', $fill->clientOrderId);
        self::assertSame('trade-1', $fill->tradeId);
        self::assertSame('buy', $fill->side);
        self::assertSame('long', $fill->positionSide);
        self::assertSame('0.25', $fill->size);
        self::assertSame('25000.5', $fill->price);
        self::assertSame('-0.01', $fill->fee);
        self::assertSame('USDT', $fill->feeCurrency);
        self::assertSame('2026-07-13T10:00:00+00:00', $fill->occurredAt?->format(DATE_ATOM));

        $serialized = serialize($snapshot);
        foreach (['demo-secret', 'metadata', 'raw_reference', 'unknown'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function testOrderSnapshotPreservesOnlyAllowlistedProtectiveOrderFields(): void
    {
        $order = OrderSnapshotItem::fromProviderDto(new OrderDto(
            orderId: 'algo:algo-1',
            symbol: 'BTCUSDT',
            side: OrderSide::SELL,
            type: OrderType::STOP,
            status: OrderStatus::PENDING,
            quantity: BigDecimal::of('1'),
            price: null,
            stopPrice: BigDecimal::of('24000'),
            filledQuantity: BigDecimal::of('0.4'),
            remainingQuantity: BigDecimal::of('0.6'),
            averagePrice: BigDecimal::of('24500'),
            createdAt: new DateTimeImmutable('2026-07-13T09:30:00Z'),
            updatedAt: new DateTimeImmutable('2026-07-13T09:31:00Z'),
            metadata: [
                'client_order_id' => 'algo-client-1',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'ordType' => 'conditional',
                'slTriggerPx' => '24000',
                'tdMode' => 'isolated',
                'lever' => '3',
                'apiSecret' => 'must-not-survive',
                'unknown' => 'must-not-survive',
            ],
        ));

        self::assertSame('algo-client-1', $order->clientOrderId);
        self::assertSame('long', $order->positionSide);
        self::assertTrue($order->reduceOnly);
        self::assertFalse($order->postOnly);
        self::assertSame('stop_loss', $order->type);
        self::assertSame('24500', $order->averagePrice);
        self::assertSame('2026-07-13T09:31:00+00:00', $order->updatedAt?->format(DATE_ATOM));
        self::assertNull($order->timeInForce);
        self::assertSame('isolated', $order->openType);
        self::assertSame('3', $order->leverage);
        self::assertStringNotContainsString('must-not-survive', serialize($order));
    }

    public function testOrderSnapshotDeterminesPostOnlyAndTimeInForceFromKnownOrderType(): void
    {
        $order = OrderSnapshotItem::fromProviderDto(self::order([
            'ordType' => 'post_only',
        ]));

        self::assertTrue($order->postOnly);
        self::assertSame('gtc', $order->timeInForce);
    }

    public function testFillOccurredAtPreservesMillisecondPrecision(): void
    {
        $first = FillSnapshotItem::fromProviderArray(['create_time' => 1783936800123]);
        $second = FillSnapshotItem::fromProviderArray(['create_time' => 1783936800124]);

        self::assertSame('1783936800.123', $first->occurredAt?->format('U.v'));
        self::assertSame('1783936800.124', $second->occurredAt?->format('U.v'));
    }

    public function testLegacyFillPreservesExactOkxInstrumentIdForIdentity(): void
    {
        $fill = (new OkxPrivateReadMapper())->legacyTrade([
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => 'trade-1',
        ]);

        self::assertSame('BTC-USDT-SWAP', $fill['instrument_id'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function isolatedFailures(): iterable
    {
        yield 'account exception' => [
            'account',
            'okx_private_rest_account_snapshot_failed',
            'accountReadable',
        ];
        yield 'positions exception' => [
            'positions',
            'okx_private_rest_positions_snapshot_failed',
            'positions',
        ];
        yield 'orders exception' => [
            'open_orders',
            'okx_private_rest_orders_snapshot_failed',
            'openOrders',
        ];
        yield 'fills exception' => [
            'fills',
            'okx_private_rest_fills_snapshot_failed',
            'fills',
        ];
    }

    #[DataProvider('isolatedFailures')]
    public function testIsolatedFailureUsesOnlyItsAllowlistedCode(
        string $failedRead,
        string $expectedError,
        string $failedProperty,
    ): void {
        $source = new FakeOkxPrivateRestSnapshotSource(
            positions: [self::positionItem()],
            openOrders: [self::orderItem()],
            fills: [self::fillItem()],
            failures: [$failedRead],
        );

        $snapshot = (new OkxPrivateRestSnapshotProbe($source))->probe(new DateTimeImmutable(self::NOW));

        self::assertFalse($snapshot->complete);
        self::assertSame([$expectedError], $snapshot->blockingErrors);
        self::assertSame(
            $failedProperty === 'accountReadable' ? false : [],
            $snapshot->{$failedProperty},
        );
        self::assertSame(['account', 'positions', 'open_orders', 'fills'], $source->readCalls);
    }

    public function testHealthCheckFalseIsAnAccountFailure(): void
    {
        $snapshot = (new OkxPrivateRestSnapshotProbe(
            new FakeOkxPrivateRestSnapshotSource(accountReadable: false),
        ))->probe(new DateTimeImmutable(self::NOW));

        self::assertFalse($snapshot->accountReadable);
        self::assertFalse($snapshot->complete);
        self::assertSame(
            ['okx_private_rest_account_snapshot_failed'],
            $snapshot->blockingErrors,
        );
    }

    public function testAggregatesFailuresAndDeduplicatesBlockingErrors(): void
    {
        $source = new FakeOkxPrivateRestSnapshotSource(
            failures: ['account', 'positions', 'open_orders', 'fills'],
        );

        $snapshot = (new OkxPrivateRestSnapshotProbe($source))->probe(new DateTimeImmutable(self::NOW));

        self::assertSame([
            'okx_private_rest_account_snapshot_failed',
            'okx_private_rest_positions_snapshot_failed',
            'okx_private_rest_orders_snapshot_failed',
            'okx_private_rest_fills_snapshot_failed',
        ], $snapshot->blockingErrors);
        self::assertSame(['account', 'positions', 'open_orders', 'fills'], $source->readCalls);

        $deduplicated = new OkxPrivateRestSnapshot(
            observedAt: new DateTimeImmutable(self::NOW),
            accountReadable: false,
            positions: [],
            openOrders: [],
            fills: [],
            complete: false,
            blockingErrors: [
                'okx_private_rest_account_snapshot_failed',
                'okx_private_rest_account_snapshot_failed',
            ],
        );
        self::assertSame(
            ['okx_private_rest_account_snapshot_failed'],
            $deduplicated->blockingErrors,
        );
    }

    public function testExceptionContainingASecretIsNeverPropagated(): void
    {
        $snapshot = (new OkxPrivateRestSnapshotProbe(
            new FakeOkxPrivateRestSnapshotSource(failures: ['positions']),
        ))->probe(new DateTimeImmutable(self::NOW));

        self::assertStringNotContainsString('demo-secret', serialize($snapshot));
        self::assertSame(
            ['okx_private_rest_positions_snapshot_failed'],
            $snapshot->blockingErrors,
        );
    }

    public function testSnapshotRejectsNonAllowlistedErrorsWithAStableMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_errors_invalid');

        new OkxPrivateRestSnapshot(
            observedAt: new DateTimeImmutable(self::NOW),
            accountReadable: false,
            positions: [],
            openOrders: [],
            fills: [],
            complete: false,
            blockingErrors: ['api_secret=demo-secret'],
        );
    }

    public function testSnapshotRejectsRawOrUntypedItems(): void
    {
        /** @var list<PositionSnapshotItem> $invalidPositions */
        $invalidPositions = [['metadata' => 'demo-secret']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_items_invalid');

        new OkxPrivateRestSnapshot(
            observedAt: new DateTimeImmutable(self::NOW),
            accountReadable: true,
            positions: $invalidPositions,
            openOrders: [],
            fills: [],
            complete: true,
            blockingErrors: [],
        );
    }

    /** @return iterable<string, array{bool, list<string>, bool}> */
    public static function contradictoryCompletionStates(): iterable
    {
        yield 'complete without readable account' => [false, [], true];
        yield 'complete with blocking error' => [
            true,
            ['okx_private_rest_fills_snapshot_failed'],
            true,
        ];
        yield 'incomplete without account failure or blocking error' => [true, [], false];
    }

    /** @param list<string> $blockingErrors */
    #[DataProvider('contradictoryCompletionStates')]
    public function testSnapshotRejectsContradictoryCompletionState(
        bool $accountReadable,
        array $blockingErrors,
        bool $complete,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_complete_invalid');

        new OkxPrivateRestSnapshot(
            observedAt: new DateTimeImmutable(self::NOW),
            accountReadable: $accountReadable,
            positions: [],
            openOrders: [],
            fills: [],
            complete: $complete,
            blockingErrors: $blockingErrors,
        );
    }

    public function testNonArrayFillFailsSourceAndProbeKeepsOtherReads(): void
    {
        $reader = new FakeOkxPrivateRestGatewayReader(
            positions: [self::position()],
            openOrders: [self::order()],
            fills: [
                [
                    'exchange' => 'okx',
                    'symbol' => 'BTCUSDT',
                    'trade_id' => 'trade-1',
                    'create_time' => 1783936800123,
                ],
                'not-an-array',
            ],
        );
        $source = new OkxGatewayPrivateRestSnapshotSource($reader);

        try {
            $source->fills();
            self::fail('A non-array fill must fail the snapshot source.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('okx_private_rest_fill_snapshot_item_invalid', $exception->getMessage());
        }

        $snapshot = (new OkxPrivateRestSnapshotProbe($source))->probe(new DateTimeImmutable(self::NOW));

        self::assertFalse($snapshot->complete);
        self::assertTrue($snapshot->accountReadable);
        self::assertCount(1, $snapshot->positions);
        self::assertCount(1, $snapshot->openOrders);
        self::assertSame([], $snapshot->fills);
        self::assertSame(['okx_private_rest_fills_snapshot_failed'], $snapshot->blockingErrors);
        self::assertSame(
            ['fills', 'account', 'positions', 'open_orders', 'fills'],
            $reader->readCalls,
        );
    }

    public function testGatewayAdapterCallsTheExactFourReadsWithRequiredArguments(): void
    {
        $client = new RecordingOkxPrivateRestClient();
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );
        $source = new OkxGatewayPrivateRestSnapshotSource($reader);

        self::assertTrue($source->accountReadable());
        $positions = $source->positions();
        $orders = $source->openOrders();
        $fills = $source->fills();
        self::assertCount(1, $positions);
        self::assertSame('BTCUSDT', $positions[0]->symbol);
        self::assertCount(2, $orders);
        self::assertSame('order-1', $orders[0]->orderId);
        self::assertSame('algo:algo-1', $orders[1]->orderId);
        self::assertCount(1, $fills);
        self::assertSame('trade-1', $fills[0]->tradeId);
        self::assertSame([
            ['/api/v5/account/balance', []],
            ['/api/v5/account/positions', ['instType' => 'SWAP']],
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP', 'limit' => 100]],
            ['/api/v5/trade/orders-algo-pending', ['instType' => 'SWAP', 'limit' => 100, 'ordType' => 'conditional']],
            ['/api/v5/trade/fills', ['instType' => 'SWAP', 'limit' => 100]],
        ], $client->privateGetCalls);
        self::assertSame(0, $client->privatePostCalls);
    }

    public function testGatewayAdapterPaginatesStandardAndAlgoOrders(): void
    {
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => [
                self::payload(self::standardOrderRows(100, 300)),
                self::payload(self::standardOrderRows(1, 200)),
            ],
            '/api/v5/trade/orders-algo-pending' => [
                self::payload(self::algoOrderRows(100, 300)),
                self::payload(self::algoOrderRows(1, 200)),
            ],
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );

        $orders = $reader->openOrders();

        self::assertCount(202, $orders);
        self::assertSame([
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP', 'limit' => 100]],
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP', 'limit' => 100, 'after' => '201']],
            ['/api/v5/trade/orders-algo-pending', ['instType' => 'SWAP', 'limit' => 100, 'ordType' => 'conditional']],
            ['/api/v5/trade/orders-algo-pending', ['instType' => 'SWAP', 'limit' => 100, 'ordType' => 'conditional', 'after' => '201']],
        ], $client->privateGetCalls);
        self::assertSame(0, $client->privatePostCalls);
    }

    public function testGatewayAdapterPaginatesMoreThanOneHundredFills(): void
    {
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/fills' => [
                self::payload(self::fillRows(100, 300)),
                self::payload(self::fillRows(2, 200)),
            ],
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );

        $fills = $reader->fills();

        self::assertCount(102, $fills);
        self::assertSame([
            ['/api/v5/trade/fills', ['instType' => 'SWAP', 'limit' => 100]],
            ['/api/v5/trade/fills', ['instType' => 'SWAP', 'limit' => 100, 'after' => '201']],
        ], $client->privateGetCalls);
        self::assertSame(0, $client->privatePostCalls);
    }

    public function testPublicOpenOrderReadsRemainSinglePageAndIgnoreSnapshotPaginationPolicy(): void
    {
        $strictClient = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => [
                self::payload(self::standardOrderRows(100, 300)),
                self::payload(self::standardOrderRows(1, 200)),
            ],
            '/api/v5/trade/orders-algo-pending' => [self::payload([])],
        ]);
        $strictGateway = new OkxOrderGateway($strictClient);

        self::assertCount(100, $strictGateway->getOpenOrdersOrFail());
        self::assertSame([
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP']],
            ['/api/v5/trade/orders-algo-pending', ['instType' => 'SWAP', 'ordType' => 'conditional']],
        ], $strictClient->privateGetCalls);

        $tolerantClient = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => [self::payload(self::standardOrderRows(100, 300))],
            '/api/v5/trade/orders-algo-pending' => [self::payload([])],
        ]);
        self::assertCount(100, (new OkxOrderGateway($tolerantClient))->getOpenOrders());
        self::assertSame([
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP']],
            ['/api/v5/trade/orders-algo-pending', ['instType' => 'SWAP', 'ordType' => 'conditional']],
        ], $tolerantClient->privateGetCalls);

        $lookupClient = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => [self::payload(self::standardOrderRows(1, 300))],
            '/api/v5/trade/orders-algo-pending' => [self::payload([])],
        ]);
        self::assertSame('300', (new OkxOrderGateway($lookupClient))->getOrder('BTCUSDT', '300')?->orderId);
        self::assertSame([
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP', 'instId' => 'BTC-USDT-SWAP']],
            ['/api/v5/trade/orders-algo-pending', ['instType' => 'SWAP', 'instId' => 'BTC-USDT-SWAP', 'ordType' => 'conditional']],
        ], $lookupClient->privateGetCalls);
    }

    public function testMalformedSecondStandardOrderPageFailsSnapshotClosed(): void
    {
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/account/balance' => [self::payload([['details' => []]])],
            '/api/v5/account/positions' => [self::payload([])],
            '/api/v5/trade/orders-pending' => [
                self::payload(self::standardOrderRows(100, 300)),
                ['code' => '0', 'data' => 'not-an-array'],
            ],
            '/api/v5/trade/orders-algo-pending' => [self::payload([])],
            '/api/v5/trade/fills' => [self::payload([])],
        ]);
        $snapshot = (new OkxPrivateRestSnapshotProbe(new OkxGatewayPrivateRestSnapshotSource(
            new OkxGatewayPrivateRestReader(new OkxAccountGateway($client), new OkxOrderGateway($client)),
        )))->probe(new DateTimeImmutable(self::NOW));

        self::assertFalse($snapshot->complete);
        self::assertSame(['okx_private_rest_orders_snapshot_failed'], $snapshot->blockingErrors);
        self::assertSame([], $snapshot->openOrders);
    }

    public function testMalformedSecondFillPageFailsSnapshotClosed(): void
    {
        $malformedPage = self::fillRows(1, 200);
        $malformedPage[] = 'not-an-array';
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/account/balance' => [self::payload([['details' => []]])],
            '/api/v5/account/positions' => [self::payload([])],
            '/api/v5/trade/orders-pending' => [self::payload([])],
            '/api/v5/trade/orders-algo-pending' => [self::payload([])],
            '/api/v5/trade/fills' => [
                self::payload(self::fillRows(100, 300)),
                ['code' => '0', 'data' => $malformedPage],
            ],
        ]);
        $snapshot = (new OkxPrivateRestSnapshotProbe(new OkxGatewayPrivateRestSnapshotSource(
            new OkxGatewayPrivateRestReader(new OkxAccountGateway($client), new OkxOrderGateway($client)),
        )))->probe(new DateTimeImmutable(self::NOW));

        self::assertFalse($snapshot->complete);
        self::assertSame(['okx_private_rest_fills_snapshot_failed'], $snapshot->blockingErrors);
        self::assertSame([], $snapshot->fills);
    }

    public function testRepeatedOrderCursorFailsClosed(): void
    {
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => [
                self::payload(self::standardOrderRows(100, 300)),
                self::payload(self::standardOrderRows(100, 300)),
            ],
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );

        try {
            $reader->openOrders();
            self::fail('A repeated pagination cursor must fail closed.');
        } catch (OkxProviderUnavailableException $exception) {
            self::assertSame('okx_private_pagination_cursor_repeated', $exception->reason());
        }
    }

    public function testMissingOrderCursorFailsClosed(): void
    {
        $rows = self::standardOrderRows(100, 300);
        unset($rows[99]['ordId']);
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => [self::payload($rows)],
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );

        try {
            $reader->openOrders();
            self::fail('A missing pagination cursor must fail closed.');
        } catch (OkxProviderUnavailableException $exception) {
            self::assertSame('okx_private_pagination_cursor_invalid', $exception->reason());
        }
    }

    public function testExactlyOneThousandSnapshotOrdersRequireAndAcceptTerminalConfirmationPage(): void
    {
        $pages = [];
        for ($page = 0; $page < 10; ++$page) {
            $pages[] = self::payload(self::standardOrderRows(100, 2_000 - ($page * 100)));
        }
        $pages[] = self::payload([]);
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => $pages,
            '/api/v5/trade/orders-algo-pending' => [self::payload([])],
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );

        self::assertCount(1_000, $reader->openOrders());
        self::assertCount(12, $client->privateGetCalls);
        self::assertSame('1001', $client->privateGetCalls[10][1]['after'] ?? null);
    }

    public function testSnapshotItemCapFailsClosedWhenConfirmationPageContainsAnotherOrder(): void
    {
        $pages = [];
        for ($page = 0; $page < 10; ++$page) {
            $pages[] = self::payload(self::standardOrderRows(100, 2_000 - ($page * 100)));
        }
        $pages[] = self::payload(self::standardOrderRows(1, 1_000));
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/orders-pending' => $pages,
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );

        try {
            $reader->openOrders();
            self::fail('An order beyond the defensive item cap must fail closed.');
        } catch (OkxProviderUnavailableException $exception) {
            self::assertSame('okx_private_pagination_limit_exceeded', $exception->reason());
        }
        self::assertCount(11, $client->privateGetCalls);
    }

    public function testExactlyOneThousandSnapshotFillsRequireAndAcceptTerminalConfirmationPage(): void
    {
        $pages = [];
        for ($page = 0; $page < 10; ++$page) {
            $pages[] = self::payload(self::fillRows(100, 2_000 - ($page * 100)));
        }
        $pages[] = self::payload([]);
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/trade/fills' => $pages,
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );

        self::assertCount(1_000, $reader->fills());
        self::assertCount(11, $client->privateGetCalls);
        self::assertSame('1001', $client->privateGetCalls[10][1]['after'] ?? null);
    }

    public function testLaterPageFailureMakesProbeSnapshotIncomplete(): void
    {
        $client = new ScriptedOkxPrivateRestClient([
            '/api/v5/account/balance' => [self::payload([['details' => []]])],
            '/api/v5/account/positions' => [self::payload([])],
            '/api/v5/trade/orders-pending' => [
                self::payload(self::standardOrderRows(100, 300)),
                new RuntimeException('page failed with api_secret=demo-secret'),
            ],
            '/api/v5/trade/orders-algo-pending' => [self::payload([])],
            '/api/v5/trade/fills' => [self::payload([])],
        ]);
        $reader = new OkxGatewayPrivateRestReader(
            new OkxAccountGateway($client),
            new OkxOrderGateway($client),
        );
        $source = new OkxGatewayPrivateRestSnapshotSource($reader);

        $snapshot = (new OkxPrivateRestSnapshotProbe($source))->probe(new DateTimeImmutable(self::NOW));

        self::assertFalse($snapshot->complete);
        self::assertSame(['okx_private_rest_orders_snapshot_failed'], $snapshot->blockingErrors);
        self::assertSame([], $snapshot->openOrders);
        self::assertSame([
            ['/api/v5/account/balance', []],
            ['/api/v5/account/positions', ['instType' => 'SWAP']],
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP', 'limit' => 100]],
            ['/api/v5/trade/orders-pending', ['instType' => 'SWAP', 'limit' => 100, 'after' => '201']],
            ['/api/v5/trade/fills', ['instType' => 'SWAP', 'limit' => 100]],
        ], $client->privateGetCalls);
    }

    public function testSourceContractAndSnapshotExposeOnlyReadOnlyState(): void
    {
        $methods = get_class_methods(OkxPrivateRestSnapshotSourceInterface::class);
        foreach (['accountReadable', 'fills', 'openOrders', 'positions'] as $readMethod) {
            self::assertContains($readMethod, $methods);
        }
        foreach (['placeOrder', 'cancelOrder', 'amendOrder', 'submitLeverage', 'updateProtection'] as $writeMethod) {
            self::assertNotContains($writeMethod, $methods);
        }

        $reflection = new ReflectionClass(OkxPrivateRestSnapshot::class);
        self::assertTrue($reflection->isReadOnly());
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        foreach (['metadata', 'rawReference', 'raw_reference', 'payload', 'secret'] as $sensitiveProperty) {
            self::assertNotContains($sensitiveProperty, $properties);
        }
    }

    /** @param array<string, mixed> $metadata */
    private static function position(array $metadata = []): PositionDto
    {
        return new PositionDto(
            symbol: 'BTCUSDT',
            side: PositionSide::LONG,
            size: BigDecimal::of('0.25'),
            entryPrice: BigDecimal::of('25000'),
            markPrice: BigDecimal::of('25100'),
            unrealizedPnl: BigDecimal::of('25'),
            realizedPnl: BigDecimal::zero(),
            margin: BigDecimal::of('100'),
            leverage: BigDecimal::of('3'),
            openedAt: new DateTimeImmutable('2026-07-13T09:00:00Z'),
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    private static function order(array $metadata = []): OrderDto
    {
        return new OrderDto(
            orderId: 'order-1',
            symbol: 'BTCUSDT',
            side: OrderSide::BUY,
            type: OrderType::LIMIT,
            status: OrderStatus::PENDING,
            quantity: BigDecimal::of('0.25'),
            price: BigDecimal::of('25000'),
            stopPrice: null,
            filledQuantity: BigDecimal::zero(),
            remainingQuantity: BigDecimal::of('0.25'),
            averagePrice: null,
            createdAt: new DateTimeImmutable('2026-07-13T09:30:00Z'),
            metadata: $metadata,
        );
    }

    private static function positionItem(): PositionSnapshotItem
    {
        return PositionSnapshotItem::fromProviderDto(self::position());
    }

    private static function orderItem(): OrderSnapshotItem
    {
        return OrderSnapshotItem::fromProviderDto(self::order());
    }

    private static function fillItem(): FillSnapshotItem
    {
        return FillSnapshotItem::fromProviderArray([
            'exchange' => 'okx',
            'symbol' => 'BTCUSDT',
            'order_id' => 'order-1',
            'client_order_id' => 'client-1',
            'trade_id' => 'trade-1',
            'side' => 'buy',
            'position_side' => 'long',
            'size' => '0.25',
            'price' => '25000',
            'create_time' => 1783936800000,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{code: string, data: list<array<string, mixed>>}
     */
    private static function payload(array $rows): array
    {
        return ['code' => '0', 'data' => $rows];
    }

    /** @return list<array<string, mixed>> */
    private static function standardOrderRows(int $count, int $firstId): array
    {
        $rows = [];
        for ($offset = 0; $offset < $count; ++$offset) {
            $id = (string) ($firstId - $offset);
            $rows[] = [
                'instId' => 'BTC-USDT-SWAP',
                'ordId' => $id,
                'side' => 'buy',
                'ordType' => 'limit',
                'state' => 'live',
                'sz' => '0.25',
                'accFillSz' => '0',
                'px' => '25000',
                'cTime' => '1783935000000',
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function algoOrderRows(int $count, int $firstId): array
    {
        $rows = [];
        for ($offset = 0; $offset < $count; ++$offset) {
            $id = (string) ($firstId - $offset);
            $rows[] = [
                'instId' => 'BTC-USDT-SWAP',
                'algoId' => $id,
                'side' => 'sell',
                'ordType' => 'conditional',
                'state' => 'live',
                'sz' => '0.25',
                'accFillSz' => '0',
                'slTriggerPx' => '24000',
                'cTime' => '1783935000000',
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function fillRows(int $count, int $firstId): array
    {
        $rows = [];
        for ($offset = 0; $offset < $count; ++$offset) {
            $id = (string) ($firstId - $offset);
            $rows[] = [
                'instId' => 'BTC-USDT-SWAP',
                'ordId' => 'order-'.$id,
                'billId' => $id,
                'tradeId' => 'trade-'.$id,
                'side' => 'buy',
                'posSide' => 'long',
                'fillSz' => '0.25',
                'fillPx' => '25000.5',
                'ts' => '1783936800000',
            ];
        }

        return $rows;
    }
}

final class FakeOkxPrivateRestGatewayReader implements OkxPrivateRestGatewayReaderInterface
{
    /** @var list<string> */
    public array $readCalls = [];

    /**
     * @param list<PositionDto> $positions
     * @param list<OrderDto>    $openOrders
     * @param list<mixed>       $fills
     */
    public function __construct(
        private readonly bool $accountReadable = true,
        private readonly array $positions = [],
        private readonly array $openOrders = [],
        private readonly array $fills = [],
    ) {
    }

    public function accountReadable(): bool
    {
        $this->readCalls[] = 'account';

        return $this->accountReadable;
    }

    /** @return list<PositionDto> */
    public function positions(): array
    {
        $this->readCalls[] = 'positions';

        return $this->positions;
    }

    /** @return list<OrderDto> */
    public function openOrders(): array
    {
        $this->readCalls[] = 'open_orders';

        return $this->openOrders;
    }

    /** @return list<mixed> */
    public function fills(): array
    {
        $this->readCalls[] = 'fills';

        return $this->fills;
    }
}

final class FakeOkxPrivateRestSnapshotSource implements OkxPrivateRestSnapshotSourceInterface
{
    /** @var list<string> */
    public array $readCalls = [];

    /**
     * @param list<PositionSnapshotItem> $positions
     * @param list<OrderSnapshotItem>    $openOrders
     * @param list<FillSnapshotItem>     $fills
     * @param list<string>               $failures
     */
    public function __construct(
        private readonly bool $accountReadable = true,
        private readonly array $positions = [],
        private readonly array $openOrders = [],
        private readonly array $fills = [],
        private readonly array $failures = [],
    ) {
    }

    public function accountReadable(): bool
    {
        $this->record('account');

        return $this->accountReadable;
    }

    /** @return list<PositionSnapshotItem> */
    public function positions(): array
    {
        $this->record('positions');

        return $this->positions;
    }

    /** @return list<OrderSnapshotItem> */
    public function openOrders(): array
    {
        $this->record('open_orders');

        return $this->openOrders;
    }

    /** @return list<FillSnapshotItem> */
    public function fills(): array
    {
        $this->record('fills');

        return $this->fills;
    }

    private function record(string $read): void
    {
        $this->readCalls[] = $read;
        if (in_array($read, $this->failures, true)) {
            throw new RuntimeException('provider failed with api_secret=demo-secret');
        }
    }
}

final class RecordingOkxPrivateRestClient implements OkxRestClientInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $privateGetCalls = [];
    public int $privatePostCalls = 0;

    public function publicGet(string $path, array $query = []): array
    {
        throw new RuntimeException('Public reads are outside the private snapshot scope.');
    }

    public function privateGet(string $path, array $query = []): array
    {
        $this->privateGetCalls[] = [$path, $query];

        return match ($path) {
            '/api/v5/account/balance' => ['code' => '0', 'data' => [['details' => []]]],
            '/api/v5/account/positions' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'posSide' => 'long',
                'pos' => '0.25',
                'avgPx' => '25000',
                'markPx' => '25100',
                'uTime' => '1783933200000',
                'metadata' => 'demo-secret',
            ]]],
            '/api/v5/trade/orders-pending' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'ordId' => 'order-1',
                'side' => 'buy',
                'ordType' => 'limit',
                'state' => 'live',
                'sz' => '0.25',
                'accFillSz' => '0',
                'px' => '25000',
                'cTime' => '1783935000000',
                'metadata' => 'demo-secret',
            ]]],
            '/api/v5/trade/orders-algo-pending' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'algoId' => 'algo-1',
                'side' => 'sell',
                'ordType' => 'conditional',
                'state' => 'live',
                'sz' => '0.25',
                'accFillSz' => '0',
                'slTriggerPx' => '24000',
                'cTime' => '1783935000000',
                'metadata' => 'demo-secret',
            ]]],
            '/api/v5/trade/fills' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'ordId' => 'order-1',
                'clOrdId' => 'client-1',
                'tradeId' => 'trade-1',
                'side' => 'buy',
                'posSide' => 'long',
                'fillSz' => '0.25',
                'fillPx' => '25000.5',
                'fee' => '-0.01',
                'feeCcy' => 'USDT',
                'ts' => '1783936800000',
                'unknown' => 'demo-secret',
            ]]],
            default => ['code' => '0', 'data' => []],
        };
    }

    public function privatePost(string $path, array $body = []): array
    {
        ++$this->privatePostCalls;

        throw new RuntimeException('Writes are forbidden in the private snapshot scope.');
    }
}

final class ScriptedOkxPrivateRestClient implements OkxRestClientInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $privateGetCalls = [];
    public int $privatePostCalls = 0;

    /** @param array<string, list<array<string, mixed>|\Throwable>> $responses */
    public function __construct(private array $responses)
    {
    }

    public function publicGet(string $path, array $query = []): array
    {
        throw new RuntimeException('Public reads are outside the private snapshot scope.');
    }

    public function privateGet(string $path, array $query = []): array
    {
        $this->privateGetCalls[] = [$path, $query];
        $response = array_shift($this->responses[$path]);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (!\is_array($response)) {
            throw new RuntimeException('Unexpected private REST page.');
        }

        return $response;
    }

    public function privatePost(string $path, array $body = []): array
    {
        ++$this->privatePostCalls;

        throw new RuntimeException('Writes are forbidden in the private snapshot scope.');
    }
}
