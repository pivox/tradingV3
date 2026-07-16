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
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeExchangeWsClient;
use App\Exchange\Fake\FakePrivateWsException;
use App\Exchange\Fake\FakePrivateWsScenario;
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
#[CoversClass(FakePrivateWsException::class)]
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

    public function testReconnectResumesAfterDeterministicDisconnectWithoutLossOrDuplicate(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest(symbol: 'BTCUSDT', clientOrderId: 'btc-cid'));
        $adapter->placeOrder($this->marketRequest(symbol: 'ETHUSDT', clientOrderId: 'eth-cid'));

        $expectedStore = new RecordingProjectionStore();
        $this->service($expectedStore)->drain(new FakeExchangeWsClient($state));

        $store = new RecordingProjectionStore();
        $service = $this->service($store);
        $client = new FakeExchangeWsClient($state, disconnectAfterAcknowledgedEvents: 2);

        try {
            $service->drain($client);
            self::fail('The deterministic private WS disconnect must interrupt ingestion.');
        } catch (FakePrivateWsException $exception) {
            self::assertSame('fake_private_ws_disconnected', $exception->errorCode);
            self::assertSame('resync_required', $exception->state);
            self::assertSame('2', $exception->lastAcknowledgedSequence);
        }

        self::assertTrue($client->requiresResync());
        self::assertSame('resync_required', $client->connectionState());

        $client->reconnect();
        $resumed = $service->drain($client);
        $empty = $service->drain($client);

        self::assertFalse($client->requiresResync());
        self::assertGreaterThan(0, $resumed->rawEventsRead);
        self::assertSame(0, $empty->rawEventsRead);
        self::assertSame($expectedStore->eventSignatures(), $store->eventSignatures());
    }

    public function testProjectionFailureDoesNotAcknowledgeRawEvent(): void
    {
        $state = new FakeExchangeStateStore();
        $this->adapter($state)->placeOrder($this->marketRequest(
            symbol: 'BTCUSDT',
            clientOrderId: 'projection-retry-cid',
        ));

        $expectedStore = new RecordingProjectionStore();
        $this->service($expectedStore)->drain(new FakeExchangeWsClient($state));

        $store = new RecordingProjectionStore(failOnProjectionNumber: 2);
        $service = $this->service($store);
        $client = new FakeExchangeWsClient($state);

        try {
            $service->drain($client);
            self::fail('The projection failure must escape ingestion.');
        } catch (\RuntimeException $exception) {
            self::assertSame('test_projection_failed', $exception->getMessage());
        }

        $retried = $service->drain($client);
        $empty = $service->drain($client);

        self::assertGreaterThan(0, $retried->rawEventsRead);
        self::assertSame(0, $empty->rawEventsRead);
        self::assertSame($expectedStore->eventSignatures(), $store->eventSignatures());
    }

    public function testSequenceGapRequiresResync(): void
    {
        $state = new FakeExchangeStateStore();
        $state->appendEvent(new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
            ['event_sequence' => 1],
        ));
        $state->appendEvent(new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01 00:00:01 UTC'),
            ['event_sequence' => 3],
        ));

        $client = new FakeExchangeWsClient($state);

        try {
            iterator_to_array($client->drainPrivateEvents());
            self::fail('A private WS sequence gap must interrupt ingestion.');
        } catch (FakePrivateWsException $exception) {
            self::assertSame('fake_private_ws_sequence_gap', $exception->errorCode);
            self::assertSame('resync_required', $exception->state);
            self::assertSame('1', $exception->lastAcknowledgedSequence);
            self::assertSame('2', $exception->expectedSequence);
            self::assertSame('3', $exception->actualSequence);
        }

        self::assertTrue($client->requiresResync());

        try {
            $client->reconnect();
            self::fail('A sequence gap requires a snapshot resync, not a plain reconnect.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_resync_required', $exception->getMessage());
        }

        $client->completeSnapshotResync();
        self::assertFalse($client->requiresResync());
        self::assertSame([], iterator_to_array($client->drainPrivateEvents()));

        $state->appendEvent(new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01 00:00:02 UTC'),
            [],
        ));

        /** @var list<FakeExchangeEvent> $resumed */
        $resumed = array_values(iterator_to_array($client->drainPrivateEvents()));
        self::assertCount(1, $resumed);
        self::assertSame(4, $resumed[0]->payload['event_sequence'] ?? null);
    }

    public function testExactDuplicateIsAuditedWithoutProjection(): void
    {
        $state = new FakeExchangeStateStore();
        $this->adapter($state)->placeOrder($this->marketRequest(
            symbol: 'BTCUSDT',
            clientOrderId: 'duplicate-cid',
        ));
        $event = $state->events()[0];
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            'duplicate-v1',
            [$event, $event],
        ));

        $store = new RecordingProjectionStore();
        $result = $this->service($store)->drain(new FakeExchangeWsClient($state));
        $audit = $state->privateWsAudit();

        self::assertSame(1, $result->rawEventsRead);
        self::assertCount(1, $store->events);
        self::assertSame(1, $audit['acknowledged_total']);
        self::assertSame(1, $audit['duplicate_total']);
        self::assertSame(2, $audit['next_delivery_index']);
    }

    public function testOutOfOrderOneThreeTwoRequiresSnapshotBeforeFurtherProjection(): void
    {
        $state = new FakeExchangeStateStore();
        $this->adapter($state)->placeOrder($this->marketRequest(
            symbol: 'BTCUSDT',
            clientOrderId: 'out-of-order-cid',
        ));
        $events = $state->events();
        self::assertSame([1, 2, 3], array_map(
            static fn (FakeExchangeEvent $event): mixed => $event->payload['event_sequence'] ?? null,
            $events,
        ));
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            'one-three-two-v1',
            [$events[0], $events[2], $events[1]],
        ));

        $store = new RecordingProjectionStore();
        $service = $this->service($store);
        $client = new FakeExchangeWsClient($state);

        try {
            $service->drain($client);
            self::fail('Sequence 3 must create a gap before projection.');
        } catch (FakePrivateWsException $exception) {
            self::assertSame('fake_private_ws_sequence_gap', $exception->errorCode);
            self::assertSame('2', $exception->expectedSequence);
            self::assertSame('3', $exception->actualSequence);
        }

        self::assertCount(1, $store->events);
        self::assertSame(1, $state->privateWsAudit()['gap_total']);

        try {
            $service->drain($client);
            self::fail('No fixture delivery may project while resync is required.');
        } catch (FakePrivateWsException $exception) {
            self::assertSame('fake_private_ws_snapshot_resync_required', $exception->errorCode);
        }

        self::assertCount(1, $store->events);
        self::assertSame(1, $state->privateWsAudit()['next_delivery_index']);
    }

    public function testSameSequenceWithConflictingPayloadFailsClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $this->adapter($state)->placeOrder($this->marketRequest(
            symbol: 'BTCUSDT',
            clientOrderId: 'conflict-cid',
        ));
        $first = $state->events()[0];
        $conflict = new FakeExchangeEvent(
            $first->type,
            $first->symbol,
            $first->occurredAt,
            $first->payload + ['conflict_marker' => 'changed'],
        );
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            'conflict-v1',
            [$first, $conflict],
        ));

        $store = new RecordingProjectionStore();

        try {
            $this->service($store)->drain(new FakeExchangeWsClient($state));
            self::fail('A reused sequence with a changed envelope must fail closed.');
        } catch (FakePrivateWsException $exception) {
            self::assertSame('fake_private_ws_sequence_conflict', $exception->errorCode);
            self::assertSame('resync_required', $exception->state);
            self::assertSame('1', $exception->actualSequence);
        }

        self::assertCount(1, $store->events);
        self::assertSame(1, $state->privateWsAudit()['conflict_total']);
        self::assertSame('resync_required', $state->privateWsAudit()['connection_state']);
    }

    public function testClientCrashBeforeAcknowledgementRetriesSameDeliveryAfterRestart(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_private_ws_crash_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $event = new FakeExchangeEvent(
                'order.created',
                'BTCUSDT',
                new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ['event_sequence' => 1],
            );
            $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents('crash-v1', [$event]));

            $generator = (new FakeExchangeWsClient($state))->drainPrivateEvents();
            self::assertInstanceOf(\Generator::class, $generator);
            $firstAttempt = $generator->current();
            self::assertSame($event->toArray(), $firstAttempt->toArray());
            unset($generator);

            $restored = new FakeExchangeStateStore($stateFile);
            $retry = (new FakeExchangeWsClient($restored))->drainPrivateEvents();
            self::assertInstanceOf(\Generator::class, $retry);
            self::assertSame($event->toArray(), $retry->current()->toArray());
            self::assertSame(0, $restored->privateWsAudit()['acknowledged_total']);
            self::assertSame(0, $restored->privateWsAudit()['next_delivery_index']);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testProjectionRollbackRetriesScenarioDeliveryWithoutDuplicate(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_private_ws_rollback_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $this->adapter($state)->placeOrder($this->marketRequest(
                symbol: 'BTCUSDT',
                clientOrderId: 'rollback-cid',
            ));
            $created = $state->events()[0];
            $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents('rollback-v1', [$created]));

            $store = new RecordingProjectionStore(failOnProjectionNumber: 1);
            $service = $this->service($store);

            try {
                $service->drain(new FakeExchangeWsClient($state));
                self::fail('The first projection attempt must roll back.');
            } catch (\RuntimeException $exception) {
                self::assertSame('test_projection_failed', $exception->getMessage());
            }

            self::assertCount(0, $store->events);
            self::assertSame(0, $state->privateWsAudit()['acknowledged_total']);

            $restored = new FakeExchangeStateStore($stateFile);
            $retried = $service->drain(new FakeExchangeWsClient($restored));

            self::assertSame(1, $retried->rawEventsRead);
            self::assertCount(1, $store->events);
            self::assertSame(1, $restored->privateWsAudit()['acknowledged_total']);
            self::assertSame(0, $restored->privateWsAudit()['duplicate_total']);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testScenarioRawEventStillProjectsNormalizedBatchAtomically(): void
    {
        $state = new FakeExchangeStateStore();
        $this->adapter($state)->placeOrder($this->marketRequest(
            symbol: 'BTCUSDT',
            clientOrderId: 'atomic-batch-cid',
        ));
        $filled = $state->events('order.filled')[0];
        $filled = new FakeExchangeEvent(
            $filled->type,
            $filled->symbol,
            $filled->occurredAt,
            ['event_sequence' => 1] + $filled->payload,
        );
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents('atomic-v1', [$filled]));

        $store = new RecordingProjectionStore(failOnProjectionNumber: 2);

        try {
            $this->service($store)->drain(new FakeExchangeWsClient($state));
            self::fail('The normalized order/fill batch must fail atomically.');
        } catch (\RuntimeException $exception) {
            self::assertSame('test_projection_failed', $exception->getMessage());
        }

        self::assertCount(0, $store->events);
        self::assertSame(0, $state->privateWsAudit()['acknowledged_total']);
        self::assertSame(0, $state->privateWsAudit()['next_delivery_index']);
    }

    public function testScenarioSymbolFilterDoesNotAdvanceAnotherSymbolsDelivery(): void
    {
        $state = new FakeExchangeStateStore();
        $event = new FakeExchangeEvent(
            'order.created',
            'ETHUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ['event_sequence' => 1],
        );
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents('symbol-filter-v1', [$event]));
        $client = new FakeExchangeWsClient($state);

        self::assertSame([], iterator_to_array($client->drainPrivateEvents('BTCUSDT')));
        self::assertSame(0, $state->privateWsAudit()['acknowledged_total']);
        self::assertSame(0, $state->privateWsAudit()['next_delivery_index']);
        self::assertCount(1, iterator_to_array($client->drainPrivateEvents('ETHUSDT')));
    }

    private function service(RecordingProjectionStore $store): ExchangeWsIngestionService
    {
        return new ExchangeWsIngestionService(
            new ExchangeEventNormalizerRegistry([new FakeExchangeEventNormalizer()]),
            new ExchangeEventBus($store, new NullLogger()),
            new NullLogger(),
        );
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
    private int $projectionAttempts = 0;

    public function __construct(private ?int $failOnProjectionNumber = null)
    {
    }

    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        return [];
    }

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
        ++$this->projectionAttempts;
        if ($this->failOnProjectionNumber === $this->projectionAttempts) {
            $this->failOnProjectionNumber = null;

            throw new \RuntimeException('test_projection_failed');
        }

        $this->events[] = $event;
    }

    public function projectAtomically(array $events): void
    {
        $before = $this->events;
        try {
            foreach ($events as $event) {
                $this->project($event);
            }
        } catch (\Throwable $exception) {
            $this->events = $before;

            throw $exception;
        }
    }

    /**
     * @return string[]
     */
    public function eventSignatures(): array
    {
        return array_map(
            static fn (ExchangeEventInterface $event): string => implode(':', [
                $event::class,
                $event->eventType(),
                $event->symbol(),
                $event->occurredAt()->format('U.u'),
            ]),
            $this->events,
        );
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
