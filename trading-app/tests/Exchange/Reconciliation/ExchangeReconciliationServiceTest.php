<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Reconciliation;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Reconciliation\ExchangeReconciliationService;
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

    private function adapter(FakeExchangeStateStore $state): FakeExchangeAdapter
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());

        return new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
    }

    private function marketRequest(): PlaceOrderRequest
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

final class RecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    /** @var ExchangeEventInterface[] */
    public array $events = [];

    /** @var array<int,array{symbol: string, side: ExchangePositionSide, size: float}> */
    public array $localOpenPositions = [];

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return false;
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
