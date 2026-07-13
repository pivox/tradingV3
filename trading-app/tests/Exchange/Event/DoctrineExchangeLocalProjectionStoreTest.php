<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Entity\FuturesOrder;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\DoctrineExchangeLocalProjectionStore;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderUpdated;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\MtfRunner\Service\FuturesOrderSyncService;
use App\Repository\FillCostLedgerEntryRepository;
use App\Repository\FuturesOrderRepository;
use App\Repository\PositionRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Pnl\FillCostLedgerIngestionConflict;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

#[CoversClass(DoctrineExchangeLocalProjectionStore::class)]
final class DoctrineExchangeLocalProjectionStoreTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('numericSideProvider')]
    public function testOrderPayloadUsesCanonicalNumericSide(
        ExchangeOrderSide $side,
        ExchangePositionSide $positionSide,
        int $expected,
    ): void {
        $captured = null;
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::once())
            ->method('syncOrderFromApi')
            ->willReturnCallback(function (array $payload) use (&$captured): FuturesOrder {
                $captured = $payload;

                return $this->createStub(FuturesOrder::class);
            });
        $store = $this->store($orderSync, $this->createStub(FillCostLedgerEntryRepository::class));

        $store->project(new ExchangeOrderUpdated($this->orderDto($side, $positionSide), new \DateTimeImmutable('2026-01-01 UTC')));

        self::assertIsArray($captured);
        self::assertSame($expected, $captured['side']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numericSideProvider')]
    public function testFillPayloadUsesCanonicalNumericSide(
        ExchangeOrderSide $side,
        ExchangePositionSide $positionSide,
        int $expected,
    ): void {
        $fill = new ExchangeFillDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'order-1',
            clientOrderId: null,
            fillId: 'fill-1',
            side: $side,
            positionSide: $positionSide,
            quantity: 1.0,
            price: 100.0,
            fee: null,
            feeCurrency: null,
            filledAt: new \DateTimeImmutable('2026-01-01 UTC'),
        );
        $event = new ExchangeFillReceived($fill);
        $method = new \ReflectionMethod(DoctrineExchangeLocalProjectionStore::class, 'fillPayload');
        /** @var array<string,mixed> $payload */
        $payload = $method->invoke(
            $this->store($this->createStub(FuturesOrderSyncService::class), $this->createStub(FillCostLedgerEntryRepository::class)),
            $fill,
            $event,
        );

        self::assertSame($expected, $payload['side']);
    }

    /** @return iterable<string,array{ExchangeOrderSide,ExchangePositionSide,int}> */
    public static function numericSideProvider(): iterable
    {
        yield 'open long' => [ExchangeOrderSide::BUY, ExchangePositionSide::LONG, 1];
        yield 'close long' => [ExchangeOrderSide::SELL, ExchangePositionSide::LONG, 2];
        yield 'close short' => [ExchangeOrderSide::BUY, ExchangePositionSide::SHORT, 3];
        yield 'open short' => [ExchangeOrderSide::SELL, ExchangePositionSide::SHORT, 4];
    }

    public function testProtectiveOrderProjectionKeepsCompleteAllowlistedPayload(): void
    {
        $captured = null;
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::once())
            ->method('syncOrderFromApi')
            ->willReturnCallback(function (array $payload) use (&$captured): FuturesOrder {
                $captured = $payload;

                return $this->createStub(FuturesOrder::class);
            });
        $store = $this->store($orderSync, $this->createStub(FillCostLedgerEntryRepository::class));
        $updatedAt = new \DateTimeImmutable('2026-01-01 00:01:00.123 UTC');

        $store->project(new ExchangeOrderUpdated(new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'algo:algo-1',
            clientOrderId: 'algo-client-1',
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::STOP_LOSS,
            status: ExchangeOrderStatus::PARTIALLY_FILLED,
            quantity: 1.0,
            filledQuantity: 0.4,
            remainingQuantity: 0.6,
            price: null,
            averagePrice: 24500.0,
            stopPrice: 24000.0,
            reduceOnly: true,
            postOnly: false,
            timeInForce: ExchangeTimeInForce::FOK,
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
            updatedAt: $updatedAt,
            metadata: [
                'source' => 'okx_private_rest_snapshot',
                'open_type' => 'isolated',
                'leverage' => '3',
            ],
        ), $updatedAt, ['source' => 'okx_private_rest_snapshot']));

        self::assertIsArray($captured);
        self::assertSame('algo-client-1', $captured['client_order_id']);
        self::assertSame('24500', $captured['average_price']);
        self::assertSame('24000', $captured['stop_price']);
        self::assertSame(1767225660123, $captured['updated_time']);
        self::assertSame('stop_loss', $captured['raw']['order_type']);
        self::assertSame('24000', $captured['raw']['stop_price']);
        self::assertSame('long', $captured['raw']['position_side']);
        self::assertTrue($captured['raw']['reduce_only']);
        self::assertFalse($captured['raw']['post_only']);
        self::assertSame('fok', $captured['raw']['time_in_force']);
        self::assertSame([
            'source' => 'okx_private_rest_snapshot',
            'open_type' => 'isolated',
            'leverage' => '3',
        ], $captured['raw']['metadata']);
        self::assertStringNotContainsString('secret', serialize($captured));
    }

    public function testOrderPayloadPreservesExactDecimalQuantitiesWithoutFloatReconstruction(): void
    {
        $captured = null;
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::once())
            ->method('syncOrderFromApi')
            ->willReturnCallback(function (array $payload) use (&$captured): FuturesOrder {
                $captured = $payload;

                return $this->createStub(FuturesOrder::class);
            });
        $store = $this->store($orderSync, $this->createStub(FillCostLedgerEntryRepository::class));
        $order = $this->orderDto(ExchangeOrderSide::BUY, ExchangePositionSide::LONG);
        $order = new ExchangeOrderDto(
            exchange: $order->exchange,
            marketType: $order->marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            side: $order->side,
            positionSide: $order->positionSide,
            orderType: $order->orderType,
            status: ExchangeOrderStatus::PARTIALLY_FILLED,
            quantity: 1.1234567890123457,
            filledQuantity: 0.40000000000000002,
            remainingQuantity: 0.72345678901234568,
            price: $order->price,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            timeInForce: null,
            createdAt: $order->createdAt,
            metadata: [
                'source' => 'okx_private_rest_snapshot',
                'quantity_decimal' => '1.123456789012345678',
                'filled_quantity_decimal' => '0.400000000000000001',
                'remaining_quantity_decimal' => '0.723456789012345677',
            ],
        );

        $store->project(new ExchangeOrderUpdated($order, $order->createdAt));

        self::assertIsArray($captured);
        self::assertSame('1.123456789012345678', $captured['quantity_decimal']);
        self::assertSame('0.400000000000000001', $captured['filled_quantity_decimal']);
        self::assertSame('0.723456789012345677', $captured['remaining_quantity_decimal']);
    }

    public function testOkxPrivateOrderAndFillProjectionRawNeverContainsProviderSecrets(): void
    {
        $capturedOrder = null;
        $capturedFill = null;
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::once())
            ->method('syncOrderFromApi')
            ->willReturnCallback(function (array $payload) use (&$capturedOrder): FuturesOrder {
                $capturedOrder = $payload;

                return $this->createStub(FuturesOrder::class);
            });
        $orderSync->expects(self::never())->method('syncTradeFromApi');
        $normalizer = new OkxExchangeEventNormalizer(
            new OkxInstrumentResolver(),
            new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
                }
            },
        );
        $events = $normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0.2',
                'apiKey' => 'doctrine-secret-api-sentinel',
                'Authorization' => 'doctrine-secret-authorization-sentinel',
                'clOrdId' => 'safe-client-order',
                'fillFee' => '-0.02',
                'fillFeeCcy' => 'USDT',
                'fillPx' => '25010',
                'fillSz' => '0.2',
                'fillTime' => '1767225601123',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'nested' => ['passphrase' => 'doctrine-secret-nested-sentinel'],
                'ordId' => 'safe-order',
                'ordType' => 'limit',
                'posSide' => 'long',
                'px' => '25000',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'partially_filled',
                'sz' => '1',
                'tradeId' => 'safe-trade',
                'uTime' => '1767225601123',
            ]],
        ]);

        self::assertCount(2, $events);
        $store = $this->store($orderSync, $this->createStub(FillCostLedgerEntryRepository::class));
        $store->project($events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        $fillPayload = new \ReflectionMethod(DoctrineExchangeLocalProjectionStore::class, 'fillPayload');
        $capturedFill = $fillPayload->invoke($store, $events[1]->fill(), $events[1]);

        self::assertIsArray($capturedOrder);
        self::assertIsArray($capturedFill);
        self::assertSame('okx_ws_orders', $capturedOrder['raw']['metadata']['source'] ?? null);
        self::assertSame('okx_ws_orders', $capturedOrder['raw']['payload']['source'] ?? null);
        self::assertSame('okx_ws_orders', $capturedFill['raw']['metadata']['source'] ?? null);
        self::assertSame('safe-trade', $capturedFill['raw']['payload']['exchange_fill_id'] ?? null);
        self::assertStringNotContainsString('doctrine-secret-', serialize([$capturedOrder, $capturedFill]));
        self::assertArrayNotHasKey('apiKey', $capturedOrder['raw']['metadata']);
        self::assertArrayNotHasKey('nested', $capturedFill['raw']['payload']);
    }

    public function testOrderProjectionFailsWhenLegacySyncReturnsNull(): void
    {
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::once())
            ->method('syncOrderFromApi')
            ->willReturn(null);

        $store = $this->store($orderSync, $this->createStub(FillCostLedgerEntryRepository::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exchange_order_projection_failed');

        $store->project(new ExchangeOrderUpdated(new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'order-1',
            clientOrderId: null,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            status: ExchangeOrderStatus::OPEN,
            quantity: 1.0,
            filledQuantity: 0.0,
            remainingQuantity: 1.0,
            price: 100.0,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            timeInForce: null,
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        ), new \DateTimeImmutable('2026-01-01 00:00:01 UTC')));
    }

    public function testFillProjectionPersistsLedgerBeforeNullLegacySyncAndReplayIsIdempotent(): void
    {
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::exactly(2))
            ->method('syncTradeFromApi')
            ->willReturn(null);

        $savedEntry = null;
        $ledgerRepository = $this->createMock(FillCostLedgerEntryRepository::class);
        $ledgerRepository->expects(self::exactly(2))
            ->method('findOneByIdempotencyKey')
            ->with('fake:perpetual:exchange_fill:fill-replay')
            ->willReturnCallback(static function () use (&$savedEntry): ?FillCostLedgerEntry {
                return $savedEntry;
            });
        $ledgerRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$savedEntry): void {
                $savedEntry = $entry;
            });

        $store = $this->store($orderSync, $ledgerRepository);
        $event = new ExchangeFillReceived(new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: '',
            clientOrderId: null,
            fillId: 'fill-replay',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 100.0,
            fee: 0.01,
            feeCurrency: 'USDT',
            filledAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        ));

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $store->project($event);
                self::fail('A null legacy fill projection must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('exchange_fill_projection_failed', $exception->getMessage());
            }
        }

        self::assertInstanceOf(FillCostLedgerEntry::class, $savedEntry);
    }

    public function testFillLedgerConflictPreventsLegacyTradeSync(): void
    {
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::never())->method('syncTradeFromApi');

        $ledgerRepository = $this->createMock(FillCostLedgerEntryRepository::class);
        $ledgerRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('fake:perpetual:exchange_fill:fill-conflict')
            ->willReturn(new FillCostLedgerEntry(
                idempotencyKey: 'fake:perpetual:exchange_fill:fill-conflict',
                payloadHash: str_repeat('0', 64),
                exchange: 'fake',
                marketType: 'perpetual',
                symbol: 'BTCUSDT',
                fillId: 'fill-conflict',
                fillRole: 'entry',
                occurredAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
                source: 'test',
                sourceVersion: 'test_v1',
            ));

        $registry = $this->createStub(ManagerRegistry::class);
        $lineage = new TradeLineageManager(
            new TradeLineageRepository($registry),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
        $store = new DoctrineExchangeLocalProjectionStore(
            $orderSync,
            new FuturesOrderRepository($registry),
            $this->createStub(PositionRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new FillCostLedgerIngestionService($ledgerRepository, $lineage),
        );

        $this->expectException(FillCostLedgerIngestionConflict::class);

        $store->project(new ExchangeFillReceived(new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: '',
            clientOrderId: null,
            fillId: 'fill-conflict',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 100.0,
            fee: 0.01,
            feeCurrency: 'USDT',
            filledAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        )));
    }

    private function store(
        FuturesOrderSyncService $orderSync,
        FillCostLedgerEntryRepository $ledgerRepository,
    ): DoctrineExchangeLocalProjectionStore {
        $registry = $this->createStub(ManagerRegistry::class);
        $lineage = new TradeLineageManager(
            new TradeLineageRepository($registry),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        return new DoctrineExchangeLocalProjectionStore(
            $orderSync,
            new FuturesOrderRepository($registry),
            $this->createStub(PositionRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new FillCostLedgerIngestionService($ledgerRepository, $lineage),
        );
    }

    private function orderDto(ExchangeOrderSide $side, ExchangePositionSide $positionSide): ExchangeOrderDto
    {
        return new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'order-1',
            clientOrderId: null,
            side: $side,
            positionSide: $positionSide,
            orderType: ExchangeOrderType::LIMIT,
            status: ExchangeOrderStatus::OPEN,
            quantity: 1.0,
            filledQuantity: 0.0,
            remainingQuantity: 1.0,
            price: 100.0,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            timeInForce: null,
            createdAt: new \DateTimeImmutable('2026-01-01 UTC'),
        );
    }
}
