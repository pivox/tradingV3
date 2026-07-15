<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Okx\OkxAuthSigner;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\PrivateWebSocket\FillSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshotProbe;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshotReconciler;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshotSourceInterface;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketLoginSigner;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityPolicy;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityStatus;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketStatusStoreInterface;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketTransportInterface;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketWorker;
use App\Exchange\Okx\PrivateWebSocket\OrderSnapshotItem;
use App\Exchange\Okx\PrivateWebSocket\PawlOkxPrivateWebSocketTransport;
use App\Exchange\Okx\PrivateWebSocket\PositionSnapshotItem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Clock\MockClock;
use function React\Promise\resolve;

#[CoversClass(\App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketWorker::class)]
#[CoversClass(PawlOkxPrivateWebSocketTransport::class)]
final class OkxPrivateWebSocketWorkerTest extends TestCase
{
    public function testTransportContractExists(): void
    {
        self::assertTrue(interface_exists(
            \App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketTransportInterface::class,
        ));
    }

    public function testPawlTransportSerializesStructuredCommandsAsJson(): void
    {
        $connection = new FakePawlConnection();
        $transport = new PawlOkxPrivateWebSocketTransport(
            connector: static fn (string $uri): PromiseInterface => resolve($connection),
        );

        $opened = false;
        $transport->connect(
            'wss://wspap.okx.com:8443/ws/v5/private',
            static function () use (&$opened): void {
                $opened = true;
            },
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );
        $transport->send(['op' => 'subscribe', 'args' => [['channel' => 'orders']]]);

        self::assertTrue($opened);
        self::assertSame(['message', 'close', 'error'], $connection->listenerNames());
        self::assertSame(
            ['{"op":"subscribe","args":[{"channel":"orders"}]}'],
            $connection->sent,
        );
    }

    public function testPawlTransportSendsPingSentinelAsLiteralTextFrame(): void
    {
        $connection = new FakePawlConnection();
        $transport = new PawlOkxPrivateWebSocketTransport(
            connector: static fn (string $uri): PromiseInterface => resolve($connection),
        );
        $transport->connect(
            'wss://wspap.okx.com:8443/ws/v5/private',
            static function (): void {},
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );

        $transport->send(['op' => 'ping']);

        self::assertSame(['ping'], $connection->sent);
    }

    public function testPawlTransportClosesDelayedConnectionResolvedAfterStop(): void
    {
        $deferred = new Deferred();
        $connection = new FakePawlConnection();
        $opened = false;
        $transport = new PawlOkxPrivateWebSocketTransport(
            connector: static fn (string $uri): PromiseInterface => $deferred->promise(),
        );
        $transport->connect(
            'wss://wspap.okx.com:8443/ws/v5/private',
            static function () use (&$opened): void { $opened = true; },
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );

        $transport->close();
        $deferred->resolve($connection);

        self::assertFalse($opened);
        self::assertSame(1, $connection->closeCount);
    }

    public function testPawlTransportIgnoresOldSocketCallbacksAfterNewConnection(): void
    {
        $old = new FakePawlConnection();
        $active = new FakePawlConnection();
        $connections = [$old, $active];
        $transport = new PawlOkxPrivateWebSocketTransport(
            connector: static function (string $uri) use (&$connections): PromiseInterface {
                return resolve(array_shift($connections));
            },
        );
        $oldCallbacks = ['message' => 0, 'close' => 0, 'error' => 0];
        $transport->connect(
            'wss://wspap.okx.com:8443/ws/v5/private',
            static function (): void {},
            static function () use (&$oldCallbacks): void { ++$oldCallbacks['message']; },
            static function () use (&$oldCallbacks): void { ++$oldCallbacks['close']; },
            static function () use (&$oldCallbacks): void { ++$oldCallbacks['error']; },
        );
        $transport->connect(
            'wss://wspap.okx.com:8443/ws/v5/private',
            static function (): void {},
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );

        $old->emit('message', 'sensitive-payload');
        $old->emit('error', new \RuntimeException('sensitive-secret'));
        $old->emit('close', 4999);
        $transport->send(['op' => 'subscribe', 'args' => [['channel' => 'orders']]]);

        self::assertSame(1, $old->closeCount);
        self::assertSame(['message' => 0, 'close' => 0, 'error' => 0], $oldCallbacks);
        self::assertSame(['{"op":"subscribe","args":[{"channel":"orders"}]}'], $active->sent);
    }

    public function testFakeTransportRetainsCallbacksForEveryAttempt(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $received = [];
        foreach (['old', 'current'] as $label) {
            $transport->connect(
                'wss://wspap.okx.com:8443/ws/v5/private',
                static function (): void {},
                static function (string $message) use (&$received, $label): void {
                    $received[] = $label . ':' . $message;
                },
                static function (): void {},
                static function (): void {},
            );
        }

        $transport->message('first', 0);
        $transport->message('second');

        self::assertSame(['old:first', 'current:second'], $received);
    }

    public function testStartPublishesNonReadyConnectsToGuardedEndpointAndSendsSignedLogin(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, new DeterministicLoop(), $store);

        $worker->start();

        self::assertSame(
            ['wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999'],
            $transport->connections,
        );
        self::assertFalse($store->saved[0]->connected);
        self::assertFalse($store->saved[0]->authenticated);

        $transport->open();

        self::assertSame('login', $transport->sent[0]['op']);
        self::assertSame('demo-key', $transport->sent[0]['args'][0]['apiKey']);
        self::assertNotSame('', $transport->sent[0]['args'][0]['sign']);
    }

    public function testStartConnectsPrivateAndBusinessEndpointsBeforeLogin(): void
    {
        $privateTransport = new FakeOkxPrivateWebSocketTransport();
        $businessTransport = new FakeOkxPrivateWebSocketTransport();
        $worker = $this->worker(
            $privateTransport,
            new DeterministicLoop(),
            new RecordingStatusStore(),
            businessTransport: $businessTransport,
        );

        $worker->start();

        self::assertSame(
            ['wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999'],
            $privateTransport->connections,
        );
        self::assertSame(
            ['wss://wseeapap.okx.com:8443/ws/v5/business'],
            $businessTransport->connections,
        );
    }

    public function testOrdersReadinessRequiresPrivateAndBusinessAcknowledgements(): void
    {
        $privateTransport = new FakeOkxPrivateWebSocketTransport();
        $businessTransport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker(
            $privateTransport,
            $loop,
            $store,
            clock: $clock,
            businessTransport: $businessTransport,
        );

        $worker->start();
        $privateTransport->open();
        $businessTransport->open();
        $privateTransport->message(['event' => 'login', 'code' => '0']);
        $businessTransport->message(['event' => 'login', 'code' => '0']);

        self::assertSame([
            'op' => 'subscribe',
            'args' => [
                ['channel' => 'orders', 'instType' => 'SWAP'],
                ['channel' => 'positions', 'instType' => 'SWAP'],
                ['channel' => 'balance_and_position'],
                ['channel' => 'fills'],
            ],
        ], $privateTransport->sent[1]);
        self::assertSame([
            'op' => 'subscribe',
            'args' => [['channel' => 'orders-algo', 'instType' => 'SWAP']],
        ], $businessTransport->sent[1]);

        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $privateTransport->message(['event' => 'subscribe', 'arg' => $arg]);
        }
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->ordersStreamReady);

        $businessTransport->message([
            'event' => 'subscribe',
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
        ]);
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);

        $status = $store->load();
        self::assertNotNull($status);
        self::assertTrue($status->ordersStreamReady);
    }

    public function testBusinessAlgoOrderUpdatesAreProjected(): void
    {
        $privateTransport = new FakeOkxPrivateWebSocketTransport();
        $businessTransport = new FakeOkxPrivateWebSocketTransport();
        $projectionStore = new RecordingProjectionStore();
        $worker = $this->worker(
            $privateTransport,
            new DeterministicLoop(),
            new RecordingStatusStore(),
            projectionStore: $projectionStore,
            businessTransport: $businessTransport,
        );

        $worker->start();
        $privateTransport->open();
        $businessTransport->open();
        $privateTransport->message(['event' => 'login', 'code' => '0']);
        $businessTransport->message(['event' => 'login', 'code' => '0']);
        $businessTransport->message([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'algoClOrdId' => 'OKXSL',
                'algoId' => '90001',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordType' => 'conditional',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'side' => 'sell',
                'slOrdPx' => '-1',
                'slTriggerPx' => '24800',
                'state' => 'live',
                'sz' => '0.01',
                'tdMode' => 'cross',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $projectionStore->events);
        self::assertSame('exchange.protection_order.created', $projectionStore->events[0]->eventType());
    }

    public function testBusinessDisconnectInvalidatesAndReconnectsThePairOnce(): void
    {
        $privateTransport = new FakeOkxPrivateWebSocketTransport();
        $businessTransport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker(
            $privateTransport,
            $loop,
            $store,
            businessTransport: $businessTransport,
        );

        $worker->start();
        $privateTransport->open();
        $businessTransport->open();
        $businessTransport->disconnect(1006);
        $privateTransport->disconnect(1006);

        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
        self::assertSame(1, $privateTransport->closeCount);
        self::assertSame(1.0, $loop->fireNextTimer());
        self::assertCount(2, $privateTransport->connections);
        self::assertCount(2, $businessTransport->connections);
    }

    public function testPrivatePongCannotMaskMissingBusinessPong(): void
    {
        $privateTransport = new FakeOkxPrivateWebSocketTransport();
        $businessTransport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker(
            $privateTransport,
            $loop,
            $store,
            businessTransport: $businessTransport,
        );

        $worker->start();
        $privateTransport->open();
        $businessTransport->open();
        $loop->firePeriodicInterval(5.0);
        $privateTransport->message('pong');

        self::assertSame(4.0, $loop->fireTimerInterval(4.0));
        self::assertSame(0, $privateTransport->closeCount);
        self::assertSame(4.0, $loop->fireTimerInterval(4.0));

        self::assertFalse($store->load()?->connected);
        self::assertSame(1, $privateTransport->closeCount);
        self::assertSame(1.0, $loop->fireNextTimer());
    }

    public function testBusinessPongDoesNotRefreshPrivateHeartbeatTimestamp(): void
    {
        $privateTransport = new FakeOkxPrivateWebSocketTransport();
        $businessTransport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker(
            $privateTransport,
            $loop,
            $store,
            clock: $clock,
            businessTransport: $businessTransport,
        );

        $worker->start();
        $privateTransport->open();
        $businessTransport->open();
        $clock->sleep(3);
        $businessTransport->message('pong');

        self::assertEquals(
            new \DateTimeImmutable('2026-07-13T10:00:00Z'),
            $store->load()?->lastHeartbeatAt,
        );
    }

    public function testLoginAckSubscribesLoadsSnapshotAndPublishesReadyStatus(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $source = new CountingSnapshotSource();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, $source, $clock);

        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertSame(1, $source->calls);
        self::assertSame([
            'op' => 'subscribe',
            'args' => [
                ['channel' => 'orders', 'instType' => 'SWAP'],
                ['channel' => 'positions', 'instType' => 'SWAP'],
                ['channel' => 'balance_and_position'],
                ['channel' => 'fills'],
            ],
        ], $transport->sent[1]);

        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);

        $status = $store->load();
        self::assertNotNull($status);
        self::assertTrue($status->authenticated);
        self::assertTrue($status->ordersStreamReady);
        self::assertTrue($status->fillsStreamReady);
        self::assertTrue($status->positionsStreamReady);
        self::assertTrue($status->initialSnapshotLoaded);
        self::assertTrue($status->reconciliationFresh);
        self::assertSame([], $status->blockingErrors);
    }

    public function testSnapshotEventsAreProjectedBeforeSnapshotReadinessIsPersisted(): void
    {
        $timeline = new EventTimeline();
        $transport = new FakeOkxPrivateWebSocketTransport($timeline);
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore(timeline: $timeline);
        $projectionStore = new RecordingProjectionStore($timeline);
        $source = new CountingSnapshotSource(
            positions: [new PositionSnapshotItem('BTCUSDT', 'long', '0.25', '25000', '25100', new \DateTimeImmutable('2026-07-13T10:00:00Z'))],
            openOrders: [new OrderSnapshotItem('order-1', 'BTCUSDT', 'buy', 'limit', 'open', '0.25', '0', '0.25', '25000', null, new \DateTimeImmutable('2026-07-13T10:00:00Z'))],
            fills: [new FillSnapshotItem('okx', 'BTCUSDT', 'order-1', null, 'trade-1', 'buy', 'long', '0.25', '25000', null, null, new \DateTimeImmutable('2026-07-13T10:00:00Z'))],
        );
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, $source, $clock, projectionStore: $projectionStore);

        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);

        $readyIndex = array_search('save:snapshot_ready', $timeline->events, true);
        self::assertIsInt($readyIndex);
        foreach (['project:exchange.order.updated', 'project:exchange.position.updated', 'project:exchange.fill.received'] as $projected) {
            $projectionIndex = array_search($projected, $timeline->events, true);
            self::assertIsInt($projectionIndex);
            self::assertLessThan($readyIndex, $projectionIndex);
        }
    }

    public function testSnapshotProjectionFailureClosesReconnectsAndNeverPersistsSnapshotReadiness(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $projectionStore = new RecordingProjectionStore(failProjection: true);
        $source = new CountingSnapshotSource(
            openOrders: [new OrderSnapshotItem('order-1', 'BTCUSDT', 'buy', 'limit', 'open', '1', '0', '1', '25000', null, new \DateTimeImmutable('2026-07-13T10:00:00Z'))],
        );
        $worker = $this->worker($transport, $loop, $store, $source, projectionStore: $projectionStore);

        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertSame(1, $transport->closeCount);
        self::assertContains(1.0, $loop->timerIntervals());
        foreach ($store->saved as $status) {
            self::assertFalse($status->initialSnapshotLoaded);
            self::assertFalse($status->reconciliationFresh);
        }
    }

    public function testProviderStopSnapshotProjectsAndAllowsReadiness(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $projectionStore = new RecordingProjectionStore();
        $source = new CountingSnapshotSource(
            openOrders: [new OrderSnapshotItem('algo:algo-1', 'BTCUSDT', 'sell', 'stop', 'pending', '0.25', '0', '0.25', null, '24000', new \DateTimeImmutable('2026-07-13T10:00:00Z'))],
        );
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, $source, $clock, projectionStore: $projectionStore);

        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);

        self::assertSame(0, $transport->closeCount);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertTrue($status->initialSnapshotLoaded);
        self::assertTrue($status->reconciliationFresh);
        self::assertCount(1, $projectionStore->events);
        $event = $projectionStore->events[0];
        self::assertInstanceOf(\App\Exchange\Event\AbstractExchangeOrderEvent::class, $event);
        self::assertSame(\App\Exchange\Enum\ExchangeOrderType::TRIGGER, $event->order()->orderType);
    }

    public function testExplicitLoginFailureClosesAndSchedulesReconnect(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();
        $transport->open();

        $transport->message(['event' => 'login', 'code' => '60009']);

        self::assertSame(1, $transport->closeCount);
        self::assertContains(1.0, $loop->timerIntervals());
        self::assertFalse($store->load()?->connected);
    }

    public function testRequiredSubscriptionFailureClosesAndSchedulesReconnect(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);

        $transport->message([
            'event' => 'error',
            'code' => '60012',
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
        ]);

        self::assertSame(1, $transport->closeCount);
        self::assertContains(1.0, $loop->timerIntervals());
        self::assertFalse($store->load()?->connected);
    }

    public function testFillsVipFailureKeepsOrdersPlusRestSessionAndDoesNotReconnect(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);
        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }

        $transport->message([
            'event' => 'error',
            'code' => '64003',
            'arg' => ['channel' => 'fills'],
        ]);
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);

        self::assertSame(0, $transport->closeCount);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertSame('orders_plus_rest', $status->fillsSource);
        self::assertSame([], $status->blockingErrors);
    }

    #[DataProvider('failedSnapshotSources')]
    public function testFailedRestSnapshotClosesAndSchedulesReconnect(CountingSnapshotSource $source): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store, $source);
        $worker->start();
        $transport->open();

        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertSame(1, $transport->closeCount);
        self::assertContains(1.0, $loop->timerIntervals());
        self::assertFalse($store->load()?->connected);
    }

    public function testSnapshotExceptionIsCanonicalizedWithoutSensitiveLogData(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $logger = new RecordingLogger();
        $source = new CountingSnapshotSource(throwOnAccount: true);
        $worker = $this->worker($transport, $loop, $store, $source, logger: $logger);
        $worker->start();
        $transport->open();

        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertSame(['okx_private_ws_connection_failed'], $store->load()?->blockingErrors);
        $serializedLogs = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('snapshot source sensitive failure', $serializedLogs);
        foreach ($logger->records as $record) {
            self::assertSame(['endpoint_id', 'channel', 'state', 'code'], array_keys($record['context']));
        }
    }

    /** @return iterable<string, array{CountingSnapshotSource}> */
    public static function failedSnapshotSources(): iterable
    {
        yield 'incomplete snapshot' => [new CountingSnapshotSource(accountReadable: false)];
        yield 'snapshot source exception' => [new CountingSnapshotSource(throwOnAccount: true)];
    }

    public function testMissingLoginAckFailsClosedAfterFiveSeconds(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $worker = $this->worker($transport, $loop, new RecordingStatusStore());
        $worker->start();
        $transport->open();

        self::assertContains(5.0, $loop->timerIntervals());
        self::assertSame(5.0, $loop->fireTimerInterval(5.0));

        self::assertSame(1, $transport->closeCount);
        self::assertSame([1.0], $loop->timerIntervals());
    }

    public function testMissingRequiredReadinessFailsClosedTenSecondsAfterLogin(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $worker = $this->worker($transport, $loop, new RecordingStatusStore());
        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertContains(10.0, $loop->timerIntervals());
        self::assertSame(10.0, $loop->fireTimerInterval(10.0));

        self::assertSame(1, $transport->closeCount);
        self::assertSame([1.0], $loop->timerIntervals());
    }

    public function testSlowSnapshotFailsClosedWhenReadinessBudgetIsExhausted(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $readinessDeadlineArmed = false;
        $source = new CountingSnapshotSource(
            onAccountReadable: static function () use ($clock, $loop, &$readinessDeadlineArmed): void {
                $readinessDeadlineArmed = \in_array(10.0, $loop->timerIntervals(), true);
                $clock->sleep(11);
            },
        );
        $worker = $this->worker($transport, $loop, $store, $source, $clock);
        $worker->start();
        $transport->open();

        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertTrue($readinessDeadlineArmed);
        self::assertSame(1, $source->calls);
        self::assertSame(1, $transport->closeCount);
        self::assertSame([1.0], $loop->timerIntervals());
        self::assertFalse($store->load()?->initialSnapshotLoaded);
    }

    public function testReadySessionIgnoresDeadlines(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $worker = $this->worker($transport, $loop, new RecordingStatusStore());
        $worker->start();
        $transport->open();
        $staleLoginDeadline = $loop->timerCallback(5.0);
        $transport->message(['event' => 'login', 'code' => '0']);
        $staleReadinessDeadline = $loop->timerCallback(10.0);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }

        $staleLoginDeadline();
        $staleReadinessDeadline();

        self::assertSame(0, $transport->closeCount);
        self::assertSame([], $loop->timers);
    }

    public function testStaleLoginDeadlineFromPreviousConnectionIsIgnored(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $worker = $this->worker($transport, $loop, new RecordingStatusStore());
        $worker->start();
        $transport->open(0);
        self::assertContains(5.0, $loop->timerIntervals());
        $staleDeadline = $loop->timerCallback(5.0);
        $transport->disconnect(1006, 0);
        $loop->fireTimerInterval(1.0);
        $transport->open(1);

        $staleDeadline();

        self::assertSame(0, $transport->closeCount);
        self::assertSame([5.0], $loop->timerIntervals());
    }

    public function testStaleReadinessDeadlineFromPreviousConnectionIsIgnored(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $worker = $this->worker($transport, $loop, new RecordingStatusStore());
        $worker->start();
        $transport->open(0);
        $transport->message(['event' => 'login', 'code' => '0'], 0);
        $staleDeadline = $loop->timerCallback(10.0);
        $transport->disconnect(1006, 0);
        $loop->fireTimerInterval(1.0);
        $transport->open(1);
        $transport->message(['event' => 'login', 'code' => '0'], 1);

        $staleDeadline();

        self::assertSame(0, $transport->closeCount);
        self::assertSame([10.0], $loop->timerIntervals());
    }

    public function testHeartbeatSendsStructuredPingAndPongRefreshesStatus(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);

        $worker->start();
        $transport->open();
        $clock->sleep(5);
        $loop->firePeriodicInterval(5.0);

        self::assertSame(['op' => 'ping'], $transport->sent[array_key_last($transport->sent)]);

        $transport->message('pong');

        self::assertSame(
            '2026-07-13T10:00:05+00:00',
            $store->load()?->lastHeartbeatAt->format(DATE_ATOM),
        );
        self::assertSame(4.0, $loop->fireTimerInterval(4.0));
        self::assertSame(0, $transport->closeCount);
        self::assertSame([5.0], $loop->timerIntervals());
    }

    public function testMissingPongFailsClosedBeforeThirtySecondsAndReconnects(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);
        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);
        self::assertTrue($store->load()?->authenticated);

        $clock->sleep(2);
        $loop->firePeriodicInterval(5.0);
        self::assertSame(4.0, $loop->fireTimerInterval(4.0));

        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
        self::assertFalse($status->ordersStreamReady);
        self::assertFalse($status->fillsStreamReady);
        self::assertFalse($status->positionsStreamReady);
        self::assertFalse($status->initialSnapshotLoaded);
        self::assertFalse($status->reconciliationFresh);
        self::assertSame(1, $transport->closeCount);
        self::assertSame(1.0, $loop->timers[0][0]);
    }

    public function testIdlePingPongCyclesRemainFreshUnderTenSecondPolicy(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);
        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);

        self::assertContains(5.0, array_column($loop->periodicTimers, 0));

        foreach ([2, 5, 5, 5] as $seconds) {
            $clock->sleep($seconds);
            $loop->firePeriodicInterval(5.0);
            $transport->message('pong');
            self::assertSame(4.0, $loop->fireNextTimer());

            $rawStatus = $store->load();
            self::assertNotNull($rawStatus);
            $status = (new OkxPrivateWebSocketObservabilityPolicy())->evaluate($rawStatus, $clock->now());
            self::assertTrue($status->privateWsConnected);
            self::assertTrue($status->privateWsAuthenticated);
            self::assertTrue($status->ordersStreamReady);
            self::assertTrue($status->fillsStreamReady);
            self::assertTrue($status->positionsStreamReady);
            self::assertTrue($status->initialSnapshotLoaded);
            self::assertTrue($status->reconciliationFresh);
            self::assertSame([], $status->blockingErrors);
        }
    }

    public function testReconnectUsesExactBackoffAndPublishesNonReadyBeforeEveryRetry(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();

        $actualDelays = [];
        foreach ([1.0, 2.0, 4.0, 8.0, 15.0, 15.0] as $expectedDelay) {
            $transport->fail(new \RuntimeException('sensitive upstream detail'));

            $status = $store->load();
            self::assertNotNull($status);
            self::assertTrue($status->reconnecting);
            self::assertFalse($status->connected);
            self::assertFalse($status->authenticated);
            self::assertFalse($status->ordersStreamReady);
            self::assertFalse($status->fillsStreamReady);
            self::assertFalse($status->positionsStreamReady);
            self::assertFalse($status->initialSnapshotLoaded);
            self::assertFalse($status->reconciliationFresh);

            $actualDelays[] = $loop->fireNextTimer();
        }

        self::assertSame([1.0, 2.0, 4.0, 8.0, 15.0, 15.0], $actualDelays);
        self::assertCount(7, $transport->connections);
    }

    public function testSnapshotIsReloadedAfterEveryAuthenticatedReconnection(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $source = new CountingSnapshotSource();
        $worker = $this->worker($transport, $loop, new RecordingStatusStore(), $source);

        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        self::assertSame(1, $source->calls);

        $transport->disconnect(1006);
        self::assertSame(1.0, $loop->fireNextTimer());
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertSame(2, $source->calls);
    }

    public function testStoreFailureClosesSocketAndNeverPersistsPositiveReadiness(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore(failAfter: 1);
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);

        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }

        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);

        self::assertSame(1, $transport->closeCount);
        self::assertSame(1.0, $loop->timers[0][0]);
        foreach ($store->saved as $status) {
            self::assertFalse($status->authenticated);
            self::assertFalse($status->ordersStreamReady);
            self::assertFalse($status->fillsStreamReady);
            self::assertFalse($status->positionsStreamReady);
            self::assertFalse($status->initialSnapshotLoaded);
            self::assertFalse($status->reconciliationFresh);
        }
    }

    public function testInitialStoreFailureRecoversWithPeriodicStatusAndHeartbeatTimers(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore(failuresBeforeSuccess: 1);
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);

        $worker->start();
        $worker->start();

        self::assertSame([], $transport->connections);
        self::assertSame([1.0, 5.0], array_column($loop->periodicTimers, 0));
        self::assertCount(1, $loop->timers);
        self::assertSame(1.0, $loop->fireNextTimer());
        self::assertCount(1, $transport->connections);
        self::assertSame([5.0], array_column($loop->timers, 0));
        self::assertSame([1.0, 5.0], array_column($loop->periodicTimers, 0));

        $transport->open();
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);
        self::assertCount(2, $store->saved);

        $clock->sleep(2);
        $loop->firePeriodicInterval(5.0);
        self::assertSame(['op' => 'ping'], $transport->sent[array_key_last($transport->sent)]);
        $transport->message('pong');
        $clock->sleep(1);
        $loop->firePeriodicInterval(1.0);

        self::assertCount(3, $store->saved);
        self::assertSame(
            '2026-07-13T10:00:05+00:00',
            $store->load()?->lastHeartbeatAt->format(DATE_ATOM),
        );
        self::assertSame(4.0, $loop->fireTimerInterval(4.0));
        self::assertSame([5.0], $loop->timerIntervals());
        self::assertCount(1, $transport->connections);

        $worker->stop();

        self::assertSame([], $loop->periodicTimers);
        self::assertSame([], $loop->timers);
        self::assertSame([], $loop->signals);
    }

    /** @return iterable<string, array{int}> */
    public static function terminationSignals(): iterable
    {
        yield 'SIGTERM' => [\SIGTERM];
        yield 'SIGINT' => [\SIGINT];
    }

    #[DataProvider('terminationSignals')]
    public function testSignalPublishesWorkerStoppingAndClosesCleanly(int $signal): void
    {
        $timeline = new EventTimeline();
        $transport = new FakeOkxPrivateWebSocketTransport($timeline);
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore(timeline: $timeline);
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();
        $transport->open();

        $loop->signal($signal);

        self::assertSame(1, $transport->closeCount);
        self::assertTrue($loop->stopped);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
        self::assertFalse($status->ordersStreamReady);
        self::assertFalse($status->fillsStreamReady);
        self::assertFalse($status->positionsStreamReady);
        self::assertFalse($status->initialSnapshotLoaded);
        self::assertFalse($status->reconciliationFresh);
        self::assertSame(['okx_private_ws_worker_stopping'], $status->blockingErrors);
        self::assertSame([], $loop->timers);
        self::assertLessThan(
            array_search('close', $timeline->events, true),
            array_search('save:worker_stopping', $timeline->events, true),
        );
    }

    public function testStopCleansLoopAndCapturedCallbacksRemainNeutralized(): void
    {
        $timeline = new EventTimeline();
        $transport = new FakeOkxPrivateWebSocketTransport($timeline);
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore(timeline: $timeline);
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);
        $worker->start();
        $transport->open();
        $clock->sleep(5);
        $loop->firePeriodicInterval(5.0);

        $capturedPeriodicCallbacks = array_column($loop->periodicTimers, 1);
        $capturedOneShotCallbacks = array_column($loop->timers, 1);
        $capturedSignalCallbacks = $loop->signals;
        $worker->stop();

        self::assertSame([], $loop->periodicTimers);
        self::assertSame([], $loop->timers);
        self::assertSame([], $loop->signals);
        $savedAfterStop = count($store->saved);
        $sentAfterStop = count($transport->sent);
        $closedAfterStop = $transport->closeCount;

        foreach ([...$capturedPeriodicCallbacks, ...$capturedOneShotCallbacks, ...$capturedSignalCallbacks] as $callback) {
            $callback();
        }

        self::assertCount($savedAfterStop, $store->saved);
        self::assertCount($sentAfterStop, $transport->sent);
        self::assertSame($closedAfterStop, $transport->closeCount);
        $saveIndex = array_search('save:worker_stopping', $timeline->events, true);
        $closeIndex = array_search('close', $timeline->events, true);
        self::assertIsInt($saveIndex);
        self::assertIsInt($closeIndex);
        self::assertLessThan($closeIndex, $saveIndex);
    }

    public function testStatusRefreshIsThrottledToAtMostOnceEveryThreeSeconds(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);
        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);

        $loop->firePeriodicInterval(1.0);
        $clock->sleep(1);
        $loop->firePeriodicInterval(1.0);
        $clock->sleep(1);
        $loop->firePeriodicInterval(1.0);
        self::assertCount(1, $store->saved);

        $clock->sleep(1);
        $loop->firePeriodicInterval(1.0);
        $loop->firePeriodicInterval(1.0);

        self::assertCount(2, $store->saved);
    }

    public function testInvalidPayloadFailsClosedWithoutEscapingReactCallback(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();
        $transport->open();

        $transport->message('{"apiKey":"must-not-be-logged"');

        self::assertSame(1, $transport->closeCount);
        self::assertSame(1.0, $loop->timers[0][0]);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertTrue($status->reconnecting);
        self::assertFalse($status->authenticated);
    }

    public function testMalformedSwapRowAfterReadinessClosesReconnectsAndProjectsNothing(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $logger = new RecordingLogger();
        $projectionStore = new RecordingProjectionStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker(
            $transport,
            $loop,
            $store,
            clock: $clock,
            logger: $logger,
            projectionStore: $projectionStore,
        );
        $worker->start();
        $transport->open();
        $transport->message(['event' => 'login', 'code' => '0']);
        foreach ([
            ['channel' => 'orders', 'instType' => 'SWAP'],
            ['channel' => 'positions', 'instType' => 'SWAP'],
            ['channel' => 'balance_and_position'],
            ['channel' => 'fills'],
        ] as $arg) {
            $transport->message(['event' => 'subscribe', 'arg' => $arg]);
        }
        $clock->sleep(3);
        $loop->firePeriodicInterval(1.0);
        self::assertTrue($store->load()?->initialSnapshotLoaded);

        $transport->message([
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
            'data' => [[
                'fillPx' => '1e309',
                'fillSz' => '0.1',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'malformed-row-order',
                'side' => 'buy',
                'token' => 'worker-malformed-secret-sentinel',
                'tradeId' => 'malformed-row-fill',
                'ts' => '1767225603123',
            ]],
        ]);

        self::assertSame(1, $transport->closeCount);
        self::assertContains(1.0, $loop->timerIntervals());
        self::assertSame([], $projectionStore->events);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
        self::assertTrue($status->reconnecting);
        self::assertSame(['okx_private_ws_connection_failed'], $status->blockingErrors);
        self::assertStringNotContainsString(
            'worker-malformed-secret-sentinel',
            json_encode($logger->records, \JSON_THROW_ON_ERROR),
        );
    }

    public function testLoginSendExceptionFailsClosedWithOneRetry(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $transport->sendError = new \RuntimeException('demo-secret signature-sensitive');
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();

        $transport->open();

        self::assertSame(1, $transport->closeCount);
        self::assertCount(1, $loop->timers);
        self::assertSame(1.0, $loop->timers[0][0]);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
    }

    public function testHeartbeatSendExceptionFailsClosedWithOneRetryAndNoPongTimeout(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $clock = new MockClock('2026-07-13T10:00:00Z');
        $worker = $this->worker($transport, $loop, $store, clock: $clock);
        $worker->start();
        $transport->open();
        $transport->sendError = new \RuntimeException('heartbeat-demo-secret');

        $clock->sleep(5);
        $loop->firePeriodicInterval(5.0);

        self::assertSame(1, $transport->closeCount);
        self::assertCount(1, $loop->timers);
        self::assertSame(1.0, $loop->timers[0][0]);
        self::assertFalse($store->load()?->connected);
    }

    public function testOnErrorClosesSocketAndLateCloseDoesNotDoubleReconnect(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();
        $transport->open();

        $transport->fail(new \RuntimeException('apiKey=secret signature=secret'), 0);
        $transport->disconnect(4999, 0);

        self::assertSame(1, $transport->closeCount);
        self::assertCount(1, $loop->timers);
        self::assertSame(1.0, $loop->timers[0][0]);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
    }

    public function testSubscriptionSendExceptionDuringMessageHandlingFailsClosed(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();
        $transport->open();
        $transport->sendError = new \RuntimeException('subscription-signature-secret');

        $transport->message(['event' => 'login', 'code' => '0']);

        self::assertSame(1, $transport->closeCount);
        self::assertCount(1, $loop->timers);
        $status = $store->load();
        self::assertNotNull($status);
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
    }

    public function testWorkerIgnoresOldCallbacksAfterNewConnectionAttempt(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);
        $worker->start();
        $transport->fail(new \RuntimeException('first-attempt-secret'), 0);
        $loop->fireNextTimer();
        $transport->open(1);

        $transport->message('{"payload":"old-secret"', 0);
        $transport->fail(new \RuntimeException('old-error-secret'), 0);
        $transport->disconnect(4999, 0);

        self::assertCount(2, $transport->connections);
        self::assertCount(1, $transport->sent);
        self::assertSame('login', $transport->sent[0]['op']);
        self::assertSame([5.0], $loop->timerIntervals());
        self::assertSame(1, $transport->closeCount);
    }

    public function testEveryLogHasExactRedactedContextWithoutThrowableOrPayloadData(): void
    {
        $logger = new RecordingLogger();

        $loginTransport = new FakeOkxPrivateWebSocketTransport();
        $loginTransport->sendError = new \RuntimeException('LOGIN_SECRET signature=SIGNATURE_SECRET');
        $this->worker($loginTransport, new DeterministicLoop(), new RecordingStatusStore(), logger: $logger)->start();
        $loginTransport->open();

        $messageTransport = new FakeOkxPrivateWebSocketTransport();
        $this->worker($messageTransport, new DeterministicLoop(), new RecordingStatusStore(), logger: $logger)->start();
        $messageTransport->open();
        $messageTransport->message('{"payload":"PAYLOAD_SECRET"');

        $errorTransport = new FakeOkxPrivateWebSocketTransport();
        $this->worker($errorTransport, new DeterministicLoop(), new RecordingStatusStore(), logger: $logger)->start();
        $errorTransport->fail(new \RuntimeException('ERROR_SECRET apiKey=API_SECRET'));

        self::assertNotSame([], $logger->records);
        foreach ($logger->records as $record) {
            self::assertSame(['endpoint_id', 'channel', 'state', 'code'], array_keys($record['context']));
        }
        $serialized = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        foreach ([
            'LOGIN_SECRET',
            'SIGNATURE_SECRET',
            'PAYLOAD_SECRET',
            'ERROR_SECRET',
            'API_SECRET',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function testRunRejectsNonPositiveMaxCyclesBeforeConnecting(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $worker = $this->worker($transport, new DeterministicLoop(), new RecordingStatusStore());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_ws_max_cycles_invalid');

        $worker->run(0);
    }

    public function testRunMaxCyclesBoundsConnectionAttemptsAndStopsDeterministically(): void
    {
        $transport = new FakeOkxPrivateWebSocketTransport();
        $loop = new DeterministicLoop();
        $store = new RecordingStatusStore();
        $worker = $this->worker($transport, $loop, $store);

        $worker->run(2);
        self::assertSame(1, $loop->runCount);
        $transport->fail(new \RuntimeException('first-sensitive-failure'), 0);
        self::assertSame(1.0, $loop->fireNextTimer());
        $transport->fail(new \RuntimeException('second-sensitive-failure'), 1);
        self::assertSame(2.0, $loop->fireNextTimer());

        self::assertCount(2, $transport->connections);
        self::assertTrue($loop->stopped);
        self::assertSame(
            ['okx_private_ws_worker_stopping'],
            $store->load()?->blockingErrors,
        );
    }

    private function worker(
        FakeOkxPrivateWebSocketTransport $transport,
        DeterministicLoop $loop,
        RecordingStatusStore $store,
        ?CountingSnapshotSource $snapshotSource = null,
        ?MockClock $clock = null,
        ?LoggerInterface $logger = null,
        ?RecordingProjectionStore $projectionStore = null,
        ?OkxPrivateWebSocketTransportInterface $businessTransport = null,
    ): OkxPrivateWebSocketWorker {
        $clock ??= new MockClock('2026-07-13T10:00:00Z');
        $normalizer = new OkxExchangeEventNormalizer(new OkxInstrumentResolver(), $clock);
        $projectionStore ??= new RecordingProjectionStore();
        $eventBus = new ExchangeEventBus($projectionStore, new NullLogger());

        return new OkxPrivateWebSocketWorker(
            transport: $transport,
            businessTransport: $businessTransport ?? new AutoOkxBusinessWebSocketTransport(),
            config: self::demoConfig(),
            endpointGuard: new OkxPrivateWebSocketEndpointGuard(),
            loginSigner: new OkxPrivateWebSocketLoginSigner(new OkxAuthSigner()),
            snapshotProbe: new OkxPrivateRestSnapshotProbe($snapshotSource ?? new CountingSnapshotSource()),
            snapshotReconciler: new OkxPrivateRestSnapshotReconciler($eventBus, $projectionStore),
            statusStore: $store,
            normalizer: $normalizer,
            eventBus: $eventBus,
            clock: $clock,
            logger: $logger ?? new NullLogger(),
            loop: $loop,
        );
    }

    private static function demoConfig(): OkxConfig
    {
        return new OkxConfig(
            environment: 'demo',
            apiKey: 'demo-key',
            apiSecret: 'demo-secret',
            apiPassphrase: 'demo-passphrase',
            wsPrivateUri: 'wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999',
            simulatedTrading: true,
            liveEnabled: false,
        );
    }
}

final class AutoOkxBusinessWebSocketTransport implements OkxPrivateWebSocketTransportInterface
{
    private ?\Closure $onMessage = null;

    public function connect(
        string $uri,
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        $this->onMessage = \Closure::fromCallable($onMessage);
        $onOpen();
    }

    public function send(array $message): void
    {
        if (null === $this->onMessage) {
            throw new \LogicException('transport_not_connected');
        }
        if (['op' => 'ping'] === $message) {
            ($this->onMessage)('pong');

            return;
        }
        if ('login' === ($message['op'] ?? null)) {
            ($this->onMessage)(json_encode(['event' => 'login', 'code' => '0'], \JSON_THROW_ON_ERROR));

            return;
        }
        if ('subscribe' === ($message['op'] ?? null)) {
            foreach ($message['args'] ?? [] as $arg) {
                ($this->onMessage)(json_encode([
                    'event' => 'subscribe',
                    'code' => '0',
                    'arg' => $arg,
                ], \JSON_THROW_ON_ERROR));
            }
        }
    }

    public function close(): void
    {
        $this->onMessage = null;
    }
}

final class FakePawlConnection
{
    /** @var array<string, callable> */
    private array $listeners = [];

    /** @var list<string> */
    public array $sent = [];

    public int $closeCount = 0;

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event] = $listener;
    }

    /** @return list<string> */
    public function listenerNames(): array
    {
        return array_keys($this->listeners);
    }

    public function emit(string $event, mixed ...$arguments): void
    {
        ($this->listeners[$event])(...$arguments);
    }

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
    }

    public function close(): void
    {
        ++$this->closeCount;
    }
}

final class RecordingStatusStore implements OkxPrivateWebSocketStatusStoreInterface
{
    /** @var list<OkxPrivateWebSocketObservabilityStatus> */
    public array $saved = [];

    public function __construct(
        private readonly ?int $failAfter = null,
        private readonly ?EventTimeline $timeline = null,
        private int $failuresBeforeSuccess = 0,
    )
    {
    }

    public function save(OkxPrivateWebSocketObservabilityStatus $status): void
    {
        if ($this->failuresBeforeSuccess > 0) {
            --$this->failuresBeforeSuccess;

            throw new \RuntimeException('redis password and payload must stay redacted');
        }
        if (null !== $this->failAfter && count($this->saved) >= $this->failAfter) {
            throw new \RuntimeException('redis password and payload must stay redacted');
        }
        $this->saved[] = $status;
        if (null !== $this->timeline) {
            $this->timeline->events[] = $status->initialSnapshotLoaded
                ? 'save:snapshot_ready'
                : (\in_array(
                'okx_private_ws_worker_stopping',
                $status->blockingErrors,
                true,
            ) ? 'save:worker_stopping' : 'save:status');
        }
    }

    public function load(): ?OkxPrivateWebSocketObservabilityStatus
    {
        return $this->saved[array_key_last($this->saved)] ?? null;
    }

    public function clear(): void
    {
        $this->saved = [];
    }
}

final class CountingSnapshotSource implements OkxPrivateRestSnapshotSourceInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly bool $accountReadable = true,
        private readonly bool $throwOnAccount = false,
        private readonly ?\Closure $onAccountReadable = null,
        /** @var list<PositionSnapshotItem> */
        private readonly array $positions = [],
        /** @var list<OrderSnapshotItem> */
        private readonly array $openOrders = [],
        /** @var list<FillSnapshotItem> */
        private readonly array $fills = [],
    ) {
    }

    public function accountReadable(): bool
    {
        ++$this->calls;

        if ($this->throwOnAccount) {
            throw new \RuntimeException('snapshot source sensitive failure');
        }
        if (null !== $this->onAccountReadable) {
            ($this->onAccountReadable)();
        }

        return $this->accountReadable;
    }

    /** @return list<PositionSnapshotItem> */
    public function positions(): array
    {
        return $this->positions;
    }

    /** @return list<OrderSnapshotItem> */
    public function openOrders(): array
    {
        return $this->openOrders;
    }

    /** @return list<FillSnapshotItem> */
    public function fills(): array
    {
        return $this->fills;
    }
}

final class RecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        return [];
    }

    /** @var list<ExchangeEventInterface> */
    public array $events = [];

    public function __construct(
        private readonly ?EventTimeline $timeline = null,
        private readonly bool $failProjection = false,
    ) {
    }

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return false;
    }

    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array
    {
        return [];
    }

    public function project(ExchangeEventInterface $event): void
    {
        if ($this->failProjection) {
            throw new \RuntimeException('projection sensitive failure');
        }
        $this->events[] = $event;
        if (null !== $this->timeline) {
            $this->timeline->events[] = 'project:' . $event->eventType();
        }
    }
}

#[CoversNothing]
final class DeterministicLoop implements LoopInterface
{
    /** @var list<array{float, callable}> */
    public array $timers = [];

    /** @var list<array{float, callable}> */
    public array $periodicTimers = [];

    /** @var array<int, callable> */
    public array $signals = [];

    public bool $stopped = false;
    public int $runCount = 0;

    public function addReadStream($stream, $listener): void {}
    public function addWriteStream($stream, $listener): void {}
    public function removeReadStream($stream): void {}
    public function removeWriteStream($stream): void {}

    public function addTimer($interval, $callback): TimerInterface
    {
        $this->timers[] = [(float) $interval, $callback];

        return new FakeTimer((float) $interval, $callback, false);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $this->periodicTimers[] = [(float) $interval, $callback];

        return new FakeTimer((float) $interval, $callback, true);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $callback = $timer->getCallback();
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (array $entry): bool => $entry[1] !== $callback,
        ));
        $this->periodicTimers = array_values(array_filter(
            $this->periodicTimers,
            static fn (array $entry): bool => $entry[1] !== $callback,
        ));
    }
    public function futureTick($listener): void { $listener(); }
    public function addSignal($signal, $listener): void { $this->signals[(int) $signal] = $listener; }
    public function removeSignal($signal, $listener): void
    {
        if (($this->signals[(int) $signal] ?? null) === $listener) {
            unset($this->signals[(int) $signal]);
        }
    }
    public function run(): void { ++$this->runCount; }
    public function stop(): void { $this->stopped = true; }

    public function fireNextTimer(): float
    {
        [$delay, $callback] = array_shift($this->timers);
        $callback();

        return $delay;
    }

    /** @return list<float> */
    public function timerIntervals(): array
    {
        return array_column($this->timers, 0);
    }

    public function timerCallback(float $interval): callable
    {
        foreach ($this->timers as [$candidate, $callback]) {
            if ($candidate === $interval) {
                return $callback;
            }
        }

        throw new \LogicException('timer_not_found');
    }

    public function fireTimerInterval(float $interval): float
    {
        foreach ($this->timers as $index => [$candidate, $callback]) {
            if ($candidate === $interval) {
                array_splice($this->timers, $index, 1);
                $callback();

                return $candidate;
            }
        }

        throw new \LogicException('timer_not_found');
    }

    public function firePeriodic(int $index = 0): void
    {
        ($this->periodicTimers[$index][1])();
    }

    public function firePeriodicInterval(float $interval): void
    {
        foreach ($this->periodicTimers as [$candidate, $callback]) {
            if ($candidate === $interval) {
                $callback();

                return;
            }
        }

        throw new \LogicException('periodic_timer_not_found');
    }

    public function signal(int $signal): void
    {
        ($this->signals[$signal])($signal);
    }
}

final readonly class FakeTimer implements TimerInterface
{
    public function __construct(
        private float $interval,
        private mixed $callback,
        private bool $periodic,
    ) {}

    public function getInterval(): float { return $this->interval; }
    public function getCallback(): callable { return $this->callback; }
    public function isPeriodic(): bool { return $this->periodic; }
}

final class EventTimeline
{
    /** @var list<string> */
    public array $events = [];
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
