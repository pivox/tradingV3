<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Reconciliation;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Adapter\FakeExchangeAdapter;
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
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\AbstractExchangeOrderEvent;
use App\Exchange\Event\AbstractExchangePositionEvent;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Event\ExchangeProtectionOrderCreated;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeExchangeWsClient;
use App\Exchange\Fake\FakePrivateWsException;
use App\Exchange\Fake\FakePrivateWsScenario;
use App\Exchange\Reconciliation\ExchangeReconciliationService;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use App\Exchange\Event\ExchangeEventNormalizerRegistry;
use App\Exchange\Ws\ExchangeWsIngestionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

#[CoversClass(ExchangeReconciliationService::class)]
#[CoversClass(ExchangeEventBus::class)]
#[CoversClass(FakeExchangeAdapter::class)]
#[CoversClass(ExchangeOrderFilled::class)]
#[CoversClass(ExchangeFillReceived::class)]
#[CoversClass(ExchangePositionUpdated::class)]
final class ExchangeReconciliationServiceTest extends TestCase
{
    public function testRestReconciliationProjectsMissedFillAndFlagsUnprotectedPosition(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest());
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame(1, $result->ordersChecked);
        self::assertSame(1, $result->positionsChecked);
        self::assertSame(1, $result->fillsImported);
        self::assertSame(1, $result->unknownOrdersDetected);
        self::assertCount(1, $result->metadata['unprotected_positions'] ?? []);
        self::assertTrue($store->contains(ExchangeOrderFilled::class));
        self::assertTrue($store->contains(ExchangeFillReceived::class));
        self::assertTrue($store->contains(ExchangePositionUpdated::class));
    }

    public function testRestReconciliationClosesLocalPositionMissingFromSnapshot(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $store = new RecordingProjectionStore();
        $store->localOpenPositions = [[
            'symbol' => 'BTCUSDT',
            'side' => ExchangePositionSide::LONG,
            'size' => 10.0,
        ]];
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame(0, $result->positionsChecked);
        self::assertTrue($store->contains(ExchangePositionClosed::class));
    }

    public function testRestReconciliationDoesNotFlagPositionCoveredByStopLoss(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest(attachedStopLossPrice: 24800.0));
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame([], $result->metadata['unprotected_positions'] ?? null);
        self::assertTrue($store->contains(ExchangeProtectionOrderCreated::class));
    }

    public function testRestReconciliationIgnoresTakeProfitWrongSideAndUndersizedProtectionForSlCoverage(): void
    {
        $position = $this->position(size: 2.0);
        $adapter = new SnapshotReconciliationAdapter(
            positions: [$position],
            orders: [
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TAKE_PROFIT,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 2.0,
                    stopPrice: 26000.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TRIGGER,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 2.0,
                    stopPrice: 26000.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 2.0,
                    stopPrice: 26000.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::BUY,
                    remainingQuantity: 2.0,
                    stopPrice: 24800.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 1.0,
                    stopPrice: 24800.0,
                ),
            ],
        );
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertCount(1, $result->metadata['unprotected_positions'] ?? []);
        self::assertSame('BTCUSDT', $result->metadata['unprotected_positions'][0]['symbol'] ?? null);
    }

    public function testRestReconciliationAcceptsSplitStopLossCoverage(): void
    {
        $position = $this->position(size: 2.0);
        $adapter = new SnapshotReconciliationAdapter(
            positions: [$position],
            orders: [
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 1.0,
                    stopPrice: 24800.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TRIGGER,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 1.0,
                    stopPrice: 24790.0,
                ),
            ],
        );
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame([], $result->metadata['unprotected_positions'] ?? null);
    }

    public function testRestReconciliationDoesNotTrustGenericTriggerWhenEntryPriceIsMissing(): void
    {
        $position = $this->position(size: 1.0, side: ExchangePositionSide::SHORT, entryPrice: 0.0);
        $adapter = new SnapshotReconciliationAdapter(
            positions: [$position],
            orders: [
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TRIGGER,
                    side: ExchangeOrderSide::BUY,
                    positionSide: ExchangePositionSide::SHORT,
                    remainingQuantity: 1.0,
                    stopPrice: 20000.0,
                ),
            ],
        );
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertCount(1, $result->metadata['unprotected_positions'] ?? []);
    }

    public function testPrivateWsGapReconcilesSnapshotBeforeCompletionAndResumesContiguously(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_private_ws_reconcile_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $adapter = $this->adapter($state);
            $adapter->placeOrder($this->marketRequest());
            $events = $state->events();
            $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
                'reconcile-v1',
                [$events[0], $events[2], $events[1]],
            ));

            $store = new RecordingProjectionStore();
            $bus = new ExchangeEventBus($store, new NullLogger());
            $ingestion = new ExchangeWsIngestionService(
                new ExchangeEventNormalizerRegistry([new FakeExchangeEventNormalizer()]),
                $bus,
                new NullLogger(),
            );

            try {
                $ingestion->drain(new FakeExchangeWsClient($state));
                self::fail('The out-of-order fixture must stop on its gap.');
            } catch (FakePrivateWsException $exception) {
                self::assertSame('fake_private_ws_sequence_gap', $exception->errorCode);
            }

            $restored = new FakeExchangeStateStore($stateFile);
            $restoredClient = new FakeExchangeWsClient($restored);
            self::assertTrue($restoredClient->requiresResync());
            self::assertSame('fake_private_ws_sequence_gap', $restoredClient->audit()['resync_reason']);

            $restoredAdapter = $this->adapter($restored);
            $reconciliation = new ExchangeReconciliationService(
                $bus,
                $store,
                $this->fixedClock(),
                new NullLogger(),
            );
            $reconciliation->reconcile($restoredAdapter, 'BTCUSDT');

            self::assertSame([], $store->openOrders(Exchange::FAKE, MarketType::PERPETUAL));
            self::assertSame([[
                'symbol' => 'BTCUSDT',
                'side' => ExchangePositionSide::LONG,
                'size' => 10.0,
            ]], $store->openPositions(Exchange::FAKE, MarketType::PERPETUAL, 'BTCUSDT'));
            self::assertTrue($restoredClient->requiresResync());

            $restoredClient->completeSnapshotResync();
            self::assertFalse($restoredClient->requiresResync());
            self::assertSame(1, $restoredClient->audit()['resync_total']);
            self::assertSame(3, $restoredClient->audit()['next_delivery_index']);

            $restored->appendEvent(new FakeExchangeEvent(
                'order.created',
                'BTCUSDT',
                new \DateTimeImmutable('2026-01-01T00:00:04+00:00'),
            ));
            $resumed = $ingestion->drain($restoredClient);

            self::assertSame(1, $resumed->rawEventsRead);
            self::assertSame(2, $restoredClient->audit()['acknowledged_total']);
            self::assertSame('4', $restoredClient->audit()['last_acknowledged_sequence']);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    private function adapter(FakeExchangeStateStore $state): FakeExchangeAdapter
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());

        return new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
    }

    private function marketRequest(?float $attachedStopLossPrice = null): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 10.0,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-1',
            attachedStopLossPrice: $attachedStopLossPrice,
        );
    }

    private function position(
        float $size,
        ExchangePositionSide $side = ExchangePositionSide::LONG,
        float $entryPrice = 25000.0,
    ): ExchangePositionDto {
        return new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            size: $size,
            entryPrice: $entryPrice,
            markPrice: 25000.0,
            unrealizedPnl: 0.0,
            realizedPnl: 0.0,
            margin: 1000.0,
            leverage: 3.0,
            openedAt: $this->fixedClock()->now(),
            updatedAt: $this->fixedClock()->now(),
        );
    }

    private function protectionOrder(
        ExchangeOrderType $orderType,
        ExchangeOrderSide $side,
        float $remainingQuantity,
        float $stopPrice,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
    ): ExchangeOrderDto {
        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'protection-' . $orderType->value . '-' . $side->value . '-' . (string)$remainingQuantity,
            clientOrderId: null,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            status: ExchangeOrderStatus::OPEN,
            quantity: $remainingQuantity,
            filledQuantity: 0.0,
            remainingQuantity: $remainingQuantity,
            price: null,
            averagePrice: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            timeInForce: null,
            createdAt: $this->fixedClock()->now(),
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

final readonly class SnapshotReconciliationAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    /**
     * @param ExchangePositionDto[] $positions
     * @param ExchangeOrderDto[] $orders
     * @param ExchangeFillDto[] $fills
     */
    public function __construct(
        private array $positions,
        private array $orders,
        private array $fills = [],
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
            supportsClientOrderId: true,
            supportsReduceOnly: true,
            supportsTriggerOrders: true,
        );
    }

    public function getBalances(): array
    {
        return [
            new ExchangeBalanceDto(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                currency: 'USDT',
                available: 1000.0,
            ),
        ];
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->positions,
            static fn (ExchangePositionDto $position): bool => $normalizedSymbol === null || $position->symbol === $normalizedSymbol,
        ));
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->orders,
            static fn (ExchangeOrderDto $order): bool => $normalizedSymbol === null || $order->symbol === $normalizedSymbol,
        ));
    }

    public function getOrdersSnapshot(?string $symbol = null): array
    {
        return $this->getOpenOrders($symbol);
    }

    public function getFillsSnapshot(?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->fills,
            static fn (ExchangeFillDto $fill): bool => $normalizedSymbol === null || $fill->symbol === $normalizedSymbol,
        ));
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return true;
    }

    public function placeOrder(PlaceOrderRequest $request): \App\Exchange\Dto\PlaceOrderResult
    {
        throw new \BadMethodCallException('Snapshot adapter is read-only.');
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        throw new \BadMethodCallException('Snapshot adapter is read-only.');
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        foreach ($this->getOpenOrders($symbol) as $order) {
            if ($order->exchangeOrderId === $exchangeOrderId) {
                return $order;
            }
        }

        return null;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return new SymbolBidAskDto(
            symbol: strtoupper($symbol),
            bid: 24999.5,
            ask: 25000.5,
            timestamp: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        );
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return true;
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');

        return new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol !== null ? strtoupper($symbol) : null,
            startedAt: $now,
            completedAt: $now,
        );
    }
}

final class RecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    /** @var array<string,ExchangeOrderDto> */
    private array $orders = [];

    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        return array_values(array_filter(
            $this->orders,
            static fn (ExchangeOrderDto $order): bool => \in_array($order->status, [
                ExchangeOrderStatus::PENDING,
                ExchangeOrderStatus::OPEN,
                ExchangeOrderStatus::PARTIALLY_FILLED,
            ], true),
        ));
    }

    /** @var ExchangeEventInterface[] */
    public array $events = [];

    /** @var array<int,array{symbol: string, side: ExchangePositionSide, size: float}> */
    public array $localOpenPositions = [];

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return isset($this->orders[$order->exchangeOrderId]);
    }

    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->localOpenPositions,
            static fn (array $position): bool => $normalizedSymbol === null || $position['symbol'] === $normalizedSymbol,
        ));
    }

    public function project(ExchangeEventInterface $event): void
    {
        $this->events[] = $event;
        if ($event instanceof AbstractExchangeOrderEvent) {
            $this->orders[$event->order()->exchangeOrderId] = $event->order();
        }
        if ($event instanceof AbstractExchangePositionEvent) {
            $key = $event->symbol() . ':' . $event->side()->value;
            $this->localOpenPositions = array_values(array_filter(
                $this->localOpenPositions,
                static fn (array $position): bool => $position['symbol'] . ':' . $position['side']->value !== $key,
            ));
            if (!$event instanceof ExchangePositionClosed) {
                $this->localOpenPositions[] = [
                    'symbol' => $event->symbol(),
                    'side' => $event->side(),
                    'size' => $event->size(),
                ];
            }
        }
    }

    public function projectAtomically(array $events): void
    {
        foreach ($events as $event) {
            $this->project($event);
        }
    }

    /**
     * @param class-string $class
     */
    public function contains(string $class): bool
    {
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
