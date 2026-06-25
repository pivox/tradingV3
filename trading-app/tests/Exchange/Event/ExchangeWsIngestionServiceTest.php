<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Event;

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
use App\Exchange\Event\ExchangeEventNormalizerRegistry;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangePositionOpened;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeExchangeWsClient;
use App\Exchange\Ws\ExchangeWsIngestionResult;
use App\Exchange\Ws\ExchangeWsIngestionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

#[CoversClass(ExchangeWsIngestionService::class)]
#[CoversClass(ExchangeWsIngestionResult::class)]
#[CoversClass(ExchangeEventNormalizerRegistry::class)]
#[CoversClass(ExchangeEventBus::class)]
#[CoversClass(FakeExchangeWsClient::class)]
#[CoversClass(FakeExchangeEventNormalizer::class)]
#[CoversClass(ExchangeOrderFilled::class)]
#[CoversClass(ExchangePositionOpened::class)]
#[CoversClass(ExchangePositionUpdated::class)]
final class ExchangeWsIngestionServiceTest extends TestCase
{
    public function testDrainsFakePrivateEventsThroughNormalizerAndBus(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest(symbol: 'BTCUSDT', clientOrderId: 'btc-cid'));
        $adapter->placeOrder($this->marketRequest(symbol: 'ETHUSDT', clientOrderId: 'eth-cid'));

        $store = new RecordingProjectionStore();
        $service = new ExchangeWsIngestionService(
            new ExchangeEventNormalizerRegistry([new FakeExchangeEventNormalizer()]),
            new ExchangeEventBus($store, new NullLogger()),
            new NullLogger(),
        );

        $client = new FakeExchangeWsClient($state);
        $result = $service->drain($client, 'BTCUSDT');
        $secondDrain = $service->drain($client, 'BTCUSDT');
        $globalDrain = $service->drain($client);

        self::assertGreaterThanOrEqual(3, $result->rawEventsRead);
        self::assertGreaterThanOrEqual(3, $result->eventsProjected);
        self::assertSame(0, $secondDrain->rawEventsRead);
        self::assertSame(0, $secondDrain->eventsProjected);
        self::assertGreaterThanOrEqual(3, $globalDrain->rawEventsRead);
        self::assertTrue($store->contains(ExchangeOrderFilled::class));
        self::assertTrue($store->contains(ExchangePositionOpened::class));
    }

    public function testDrainsPositionUpdateEventsThroughNormalizerAndBus(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest(symbol: 'BTCUSDT', clientOrderId: 'entry-cid'));
        $adapter->placeOrder($this->marketRequest(
            symbol: 'BTCUSDT',
            clientOrderId: 'reduce-cid',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            quantity: 4.0,
        ));

        $store = new RecordingProjectionStore();
        $service = new ExchangeWsIngestionService(
            new ExchangeEventNormalizerRegistry([new FakeExchangeEventNormalizer()]),
            new ExchangeEventBus($store, new NullLogger()),
            new NullLogger(),
        );

        $result = $service->drain(new FakeExchangeWsClient($state), 'BTCUSDT');
        $positionUpdates = $store->eventsOf(ExchangePositionUpdated::class);

        self::assertGreaterThanOrEqual(1, $result->eventsProjected);
        self::assertCount(1, $positionUpdates);
        self::assertSame(ExchangePositionSide::LONG, $positionUpdates[0]->side());
        self::assertEqualsWithDelta(6.0, $positionUpdates[0]->size(), 0.000001);
    }

    private function adapter(FakeExchangeStateStore $state): FakeExchangeAdapter
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());

        return new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
    }

    private function marketRequest(
        string $symbol,
        string $clientOrderId,
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        bool $reduceOnly = false,
        float $quantity = 10.0,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: $side,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: null,
            stopPrice: null,
            reduceOnly: $reduceOnly,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
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

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return false;
    }

    public function openPositions(\App\Common\Enum\Exchange $exchange, \App\Common\Enum\MarketType $marketType, ?string $symbol = null): array
    {
        return [];
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

    /**
     * @template T of ExchangeEventInterface
     * @param class-string<T> $class
     * @return T[]
     */
    public function eventsOf(string $class): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (ExchangeEventInterface $event): bool => $event instanceof $class,
        ));
    }
}
