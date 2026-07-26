<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpoint;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpointStore;
use App\Trading\Paper\Okx\Live\OkxPaperLiveIntegrityException;
use App\Trading\Paper\Okx\Live\OkxPaperPublicFrameQueue;
use App\Trading\Paper\Okx\Live\OkxPaperPublicLiveSource;
use App\Trading\Paper\Okx\Live\OkxPaperPublicSubscriptionSet;
use App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxPaperPublicLiveSource::class)]
final class OkxPaperPublicLiveSourceTest extends TestCase
{
    private const DATASET_ID = 'okx-public-live-source-001';
    private const CONFIGURATION_SHA256 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'okx-public-live-source-test-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create test directory.');
        }
        $resolved = realpath($path);
        self::assertIsString($resolved);
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testInitialSourceImplementsTheAcknowledgedLiveContract(): void
    {
        self::assertTrue(
            class_exists(OkxPaperPublicLiveSource::class),
            'Task 7 public live source must exist.',
        );
        self::assertTrue(is_subclass_of(
            OkxPaperPublicLiveSource::class,
            PaperLiveMarketDataSourceInterface::class,
        ));
    }

    public function testInitialAcquisitionDisabledFailsBeforeRestOrTransport(): void
    {
        $rest = new Task7RestClient();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $source = $this->source($rest, $public, $business, acquisitionEnabled: false);

        try {
            iterator_to_array($source->events());
            self::fail('Disabled acquisition must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_acquisition_disabled', $exception->getMessage());
        }

        self::assertSame([], $rest->calls);
        self::assertSame([], $public->connections);
        self::assertSame([], $business->connections);
    }

    public function testWarmupUsesStrictRestOrderAndRequiresAcknowledgementBetweenYields(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $channels = [];
        $symbols = [];
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertNotNull($event);
            $channels[] = $event->channel;
            $symbols[] = $event->symbol;

            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }

        self::assertSame([], $public->connections);
        self::assertSame([], $business->connections);
        self::assertSame(
            [
                PaperMarketDataChannel::CANDLE_1M,
                PaperMarketDataChannel::CANDLE_5M,
                PaperMarketDataChannel::CANDLE_15M,
                PaperMarketDataChannel::CANDLE_1H,
                PaperMarketDataChannel::PUBLIC_TRADE,
                PaperMarketDataChannel::TOP_OF_BOOK,
                PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
                PaperMarketDataChannel::CANDLE_1M,
                PaperMarketDataChannel::CANDLE_5M,
                PaperMarketDataChannel::CANDLE_15M,
                PaperMarketDataChannel::CANDLE_1H,
                PaperMarketDataChannel::PUBLIC_TRADE,
                PaperMarketDataChannel::TOP_OF_BOOK,
                PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            ],
            $channels,
        );
        self::assertSame(
            [...array_fill(0, 7, 'BTCUSDT'), ...array_fill(0, 7, 'ETHUSDT')],
            $symbols,
        );
        self::assertSame(Task7RestClient::expectedInitialCalls(), $rest->calls);
    }

    public function testWarmupSortsRestTradesByTimestampThenNumericTradeId(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->tradeRows['BTC-USDT-SWAP'] = [
            self::restTrade('10', '1784970100000'),
            self::restTrade('2', '1784970100000'),
        ];
        $source = $this->source($rest, new Task7Transport(), new Task7Transport());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 4; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $tradeIds = [];
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $tradeIds[] = $event->payload['trade_id'] ?? null;
            $source->acknowledge($event->eventId);
            if ($index < 1) {
                $events->next();
            }
        }
        self::assertSame(['2', '10'], $tradeIds);
    }

    #[DataProvider('invalidInitialBookCardinalityProvider')]
    public function testInitialBookRestCardinalityFailsBeforeTransport(int $cardinality): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $row = $rest->bookRows['BTC-USDT-SWAP'][0];
        $rest->bookRows['BTC-USDT-SWAP'] = array_fill(0, $cardinality, $row);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 5; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 4) {
                $events->next();
            }
        }

        try {
            $events->next();
            self::fail('The initial book REST response must contain exactly one row.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_response_invalid', $exception->getMessage());
        }
        self::assertSame([], $public->connections);
        self::assertSame([], $business->connections);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidInitialBookCardinalityProvider(): iterable
    {
        yield 'zero rows' => [0];
        yield 'two rows' => [2];
    }

    public function testWarmupSkipsUnconfirmedRestCandleBeforeFrontierComparison(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '0'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
        ];
        $source = $this->source($rest, new Task7Transport(), new Task7Transport());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame('1784970001000', $event->exchangeTimestamp->format('Uv'));
        self::assertSame('1', $event->sequence);
        $state = $this->checkpointState();
        self::assertSame(
            '1m|1784970001000',
            $state['pending_frontier']['frontier']['source_identity'] ?? null,
        );
    }

    public function testWarmupRejectsEntirelyUnconfirmedRestCandleBatchBeforeNextTransition(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '0'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '0'],
        ];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $source = $this->source($rest, $public, $business);

        try {
            $events = $source->events();
            self::assertInstanceOf(\Generator::class, $events);
            $events->current();
            self::fail('An entirely unconfirmed REST candle batch is unusable.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_response_invalid', $exception->getMessage());
        }
        self::assertSame(
            [['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 300]]],
            $rest->calls,
        );
        self::assertSame([], $public->connections);
        self::assertSame([], $business->connections);
        self::assertNull($this->checkpointState()['pending_event']);
    }

    public function testPendingEventBlocksGeneratorProgressUntilExactAcknowledgement(): void
    {
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            new Task7Transport(),
            new Task7Transport(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        self::assertNotNull($events->current());

        try {
            $events->next();
            self::fail('A second event cannot be yielded before acknowledgement.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_acknowledgement_invalid', $exception->getMessage());
        }
    }

    public function testPendingWrongAcknowledgementHasStableError(): void
    {
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            new Task7Transport(),
            new Task7Transport(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        self::assertNotNull($events->current());

        try {
            $source->acknowledge(str_repeat('f', 64));
            self::fail('A wrong acknowledgement must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_acknowledgement_invalid', $exception->getMessage());
        }
    }

    public function testSubscriptionUsesExactSocketsArgsAndCombinedReadinessWithoutRawLeakage(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements([
                ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'trades', 'instId' => 'ETH-USDT-SWAP'],
                ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'],
            ], 'public'),
            [
                'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    'instId' => 'BTC-USDT-SWAP',
                    'tradeId' => '7001',
                    'px' => '100.5',
                    'sz' => '2',
                    'side' => 'buy',
                    'source' => '0',
                    'ts' => '1784970300000',
                    'count' => '1',
                    'seqId' => '501',
                ]],
            ],
        ];
        $business->responses = Task7Transport::acknowledgements([
            ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle5m', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle15m', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle1H', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle1m', 'instId' => 'ETH-USDT-SWAP'],
            ['channel' => 'candle5m', 'instId' => 'ETH-USDT-SWAP'],
            ['channel' => 'candle15m', 'instId' => 'ETH-USDT-SWAP'],
            ['channel' => 'candle1H', 'instId' => 'ETH-USDT-SWAP'],
        ], 'business');
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertNotNull($event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $event = $events->current();
        self::assertNotNull($event);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $event->channel);
        self::assertSame(
            [OkxPaperPublicConfig::WEB_SOCKET_URI],
            $public->connections,
        );
        self::assertSame(
            [OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI],
            $business->connections,
        );
        self::assertSame([[
            'op' => 'subscribe',
            'args' => [
                ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'trades', 'instId' => 'ETH-USDT-SWAP'],
                ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'],
            ],
        ]], $public->sent);
        self::assertSame([[
            'op' => 'subscribe',
            'args' => [
                ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'candle5m', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'candle15m', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'candle1H', 'instId' => 'BTC-USDT-SWAP'],
                ['channel' => 'candle1m', 'instId' => 'ETH-USDT-SWAP'],
                ['channel' => 'candle5m', 'instId' => 'ETH-USDT-SWAP'],
                ['channel' => 'candle15m', 'instId' => 'ETH-USDT-SWAP'],
                ['channel' => 'candle1H', 'instId' => 'ETH-USDT-SWAP'],
            ],
        ]], $business->sent);
        self::assertArrayNotHasKey('raw', $event->payload);
        self::assertArrayNotHasKey('frame', $event->payload);
        self::assertArrayNotHasKey('connId', $event->payload);
        self::assertArrayNotHasKey('headers', $event->payload);
    }

    public function testSubscriptionKeepsEarlyPublicBookDataQueuedUntilCombinedReadiness(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->connectResponses = [[
            'arg' => ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
            'action' => 'update',
            'data' => [[
                'asks' => [],
                'bids' => [['99', '4', '0', '1']],
                'ts' => '1784970300000',
                'prevSeqId' => '9001',
                'seqId' => '9002',
            ]],
        ]];
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $book = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $book);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $book->channel);
        self::assertSame('9002', $book->payload['source_seq_id'] ?? null);
        self::assertSame('ws_books', $book->payload['origin'] ?? null);
    }

    public function testSubscriptionAsyncCallbacksOnlyEnqueueUntilTheirSocketQueueIsDrained(): void
    {
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $duringCallback = null;
        $loop->scripts = [
            static function () use ($public): void {
                $public->open();
            },
            static function () use ($business): void {
                $business->open();
            },
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'public',
                ) as $acknowledgement) {
                    $public->message($acknowledgement);
                }
            },
            static function () use ($business): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'business',
                ) as $acknowledgement) {
                    $business->message($acknowledgement);
                }
            },
            function () use ($public, &$duringCallback): void {
                $public->message(Task7Transport::tradeFrame(['9600']));
                $duringCallback = $this->checkpointState();
            },
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }
        $beforeCallback = $this->checkpointState();
        $events->next();
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        self::assertSame('9600', $trade->payload['trade_id'] ?? null);
        self::assertIsArray($duringCallback);
        self::assertNull($duringCallback['pending_event']);
        self::assertNull($duringCallback['pending_frontier']);
        self::assertNull($duringCallback['stream_frontiers']['BTCUSDT/ws/public_trade'] ?? null);
        self::assertSame($beforeCallback['ordinal_state'], $duringCallback['ordinal_state']);
        self::assertNotSame($duringCallback['ordinal_state'], $this->checkpointState()['ordinal_state']);
        self::assertSame(5, $deterministic->runCount);
    }

    public function testSubscriptionReadinessCrashKeepsDurableBusinessSubscriptionAndRestartsExactTwelveAcks(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $loop = new Task7ScriptedLoop(new DeterministicLoop());
        $loop->scripts = [
            static fn (): never => throw new \RuntimeException(
                'review_crash_before_readiness',
            ),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            new Task7Transport('public'),
            new Task7Transport('business'),
            checkpointStore: $store,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }

        try {
            $events->next();
            self::fail('The first readiness run must simulate the reviewer crash.');
        } catch (\RuntimeException $exception) {
            self::assertSame('review_crash_before_readiness', $exception->getMessage());
        }
        $crashed = $this->checkpointState();
        self::assertSame('subscribing', $crashed['phase']);
        self::assertSame(
            [
                'kind' => 'subscription_send',
                'stage' => 'subscribe',
                'stream' => 'business',
                'symbol' => null,
            ],
            $crashed['pending_transition'],
        );
        self::assertNull($crashed['pending_event']);
        self::assertNull($crashed['pending_frontier']);

        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $restartLog = new Task7ActionLog();
        $public = new Task7Transport('public', $restartLog);
        $business = new Task7Transport('business', $restartLog);
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9900']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $resumed = $this->source(
            new Task7RestClient(),
            $public,
            $business,
            checkpointStore: $store,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $firstData = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstData);
        self::assertSame('9900', $firstData->payload['trade_id'] ?? null);
        self::assertSame(
            ['connect:public', 'send:public', 'connect:business', 'send:business'],
            $restartLog->actions,
        );
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $public->connections);
        self::assertSame([OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI], $business->connections);
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::publicArguments()]],
            $public->sent,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::businessArguments()]],
            $business->sent,
        );
        self::assertSame('streaming', $this->checkpointState()['phase']);
    }

    #[DataProvider('duplicateReadinessAcknowledgementProvider')]
    public function testSubscriptionRejectsAnyAcknowledgementBeyondExactReadiness(
        string $socket,
        bool $beforeReady,
    ): void {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $publicAcks = Task7Transport::acknowledgements(self::publicArguments(), 'public');
        $businessAcks = Task7Transport::acknowledgements(self::businessArguments(), 'business');
        if ($socket === 'public' && $beforeReady) {
            array_splice($publicAcks, 1, 0, [$publicAcks[0]]);
        }
        if ($socket === 'business' && $beforeReady) {
            array_splice($businessAcks, 1, 0, [$businessAcks[0]]);
        }
        $public->responses = [
            ...$publicAcks,
            ...($socket === 'public' ? [
                ...(!$beforeReady ? [$publicAcks[0]] : []),
                Task7Transport::tradeFrame(['9700']),
            ] : []),
        ];
        $business->responses = [
            ...$businessAcks,
            ...($socket === 'business' ? [
                ...(!$beforeReady ? [$businessAcks[0]] : []),
                [
                    'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                    'data' => [[
                        '1784970520000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
                    ]],
                ],
            ] : []),
        ];
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }
        $before = $this->checkpointState();
        try {
            $events->next();
            self::fail('No subscription acknowledgement is accepted after exact readiness.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_subscription_invalid', $exception->getMessage());
        }
        $after = $this->checkpointState();
        self::assertSame($before['ordinal_state'], $after['ordinal_state']);
        self::assertSame($before['stream_frontiers'], $after['stream_frontiers']);
        self::assertNull($after['pending_event']);
        self::assertNull($after['pending_frontier']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function duplicateReadinessAcknowledgementProvider(): iterable
    {
        yield 'Public duplicate before 4/4' => ['public', true];
        yield 'Business duplicate before 8/8' => ['business', true];
        yield 'Public fifth ACK' => ['public', false];
        yield 'Business ninth ACK' => ['business', false];
    }

    public function testPendingRestartYieldsExactEventBeforeAnyRestContinuation(): void
    {
        $initialRest = Task7RestClient::withInitialDataset();
        $source = $this->source($initialRest, new Task7Transport(), new Task7Transport());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pending = $events->current();
        self::assertNotNull($pending);

        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumed = $this->source($resumedRest, new Task7Transport(), new Task7Transport());
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertNotNull($replayed);
        self::assertEquals($pending->toArray(), $replayed->toArray());
        self::assertSame([], $resumedRest->calls);

        $resumed->acknowledge($replayed->eventId);
        $resumedEvents->next();
        self::assertSame(PaperMarketDataChannel::CANDLE_5M, $resumedEvents->current()?->channel);
    }

    public function testPendingMultiRowRestRestartRecoversSuffixWithAckGatedFrontiers(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
        ];
        $source = $this->source(
            $initialRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame('1784970000000', $pending->exchangeTimestamp->format('Uv'));
        self::assertSame(
            [['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 300]]],
            $initialRest->calls,
        );
        $crashed = $this->checkpointState();
        self::assertNull($crashed['stream_frontiers']['BTCUSDT/rest/candle_1m'] ?? null);
        self::assertSame(
            '1m|1784970000000',
            $crashed['pending_frontier']['frontier']['source_identity'] ?? null,
        );

        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumedRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
        ];
        $resumed = $this->source(
            $resumedRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayed);
        self::assertEquals($pending->toArray(), $replayed->toArray());
        self::assertSame([], $resumedRest->calls);
        self::assertNull(
            $this->checkpointState()['stream_frontiers']['BTCUSDT/rest/candle_1m'] ?? null,
        );

        $resumed->acknowledge($replayed->eventId);
        $acknowledgedT0 = $this->checkpointState();
        self::assertNull($acknowledgedT0['pending_frontier']);
        self::assertSame(
            '1m|1784970000000',
            $acknowledgedT0['stream_frontiers']['BTCUSDT/rest/candle_1m']['source_identity']
                ?? null,
        );
        self::assertSame(
            [
                'kind' => 'rest_fetch',
                'stage' => 'current_candles',
                'stream' => 'BTCUSDT/rest/candle_1m',
                'symbol' => 'BTCUSDT',
            ],
            $acknowledgedT0['pending_transition'],
        );

        unset($resumedEvents, $resumed);
        gc_collect_cycles();

        $postAcknowledgementRest = Task7RestClient::withInitialDataset();
        $postAcknowledgementRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
        ];
        $postAcknowledgementSource = $this->source(
            $postAcknowledgementRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $postAcknowledgementEvents = $postAcknowledgementSource->events();
        self::assertInstanceOf(\Generator::class, $postAcknowledgementEvents);
        $t1 = $postAcknowledgementEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $t1);
        self::assertSame('1784970001000', $t1->exchangeTimestamp->format('Uv'));
        self::assertSame(
            [['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 300]]],
            $postAcknowledgementRest->calls,
        );
        $pendingT1 = $this->checkpointState();
        self::assertSame(
            '1m|1784970000000',
            $pendingT1['stream_frontiers']['BTCUSDT/rest/candle_1m']['source_identity']
                ?? null,
        );
        self::assertSame(
            '1m|1784970001000',
            $pendingT1['pending_frontier']['frontier']['source_identity'] ?? null,
        );

        $postAcknowledgementSource->acknowledge($t1->eventId);
        self::assertSame(
            '1m|1784970001000',
            $this->checkpointState()['stream_frontiers']
                ['BTCUSDT/rest/candle_1m']['source_identity'] ?? null,
        );
        $postAcknowledgementEvents->next();
        $t2 = $postAcknowledgementEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $t2);
        self::assertSame('1784970002000', $t2->exchangeTimestamp->format('Uv'));
        self::assertSame(
            [['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 300]]],
            $postAcknowledgementRest->calls,
        );
        $pendingT2 = $this->checkpointState();
        self::assertSame(
            '1m|1784970001000',
            $pendingT2['stream_frontiers']['BTCUSDT/rest/candle_1m']['source_identity']
                ?? null,
        );
        self::assertSame(
            '1m|1784970002000',
            $pendingT2['pending_frontier']['frontier']['source_identity'] ?? null,
        );

        $postAcknowledgementSource->acknowledge($t2->eventId);
        $postAcknowledgementEvents->next();
        self::assertSame(
            PaperMarketDataChannel::CANDLE_5M,
            $postAcknowledgementEvents->current()?->channel,
        );
        self::assertSame(
            [
                ['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 300]],
                ['currentCandles', ['BTC-USDT-SWAP', '5m', null, null, 300]],
            ],
            $postAcknowledgementRest->calls,
        );
    }

    public function testPendingMultiRowRestRestartRejectsConflictingReplayOverlap(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
        ];
        $source = $this->source(
            $initialRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);

        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumedRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.6', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
        ];
        $resumed = $this->source(
            $resumedRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayed);
        self::assertEquals($pending->toArray(), $replayed->toArray());
        self::assertSame([], $resumedRest->calls);
        $resumed->acknowledge($replayed->eventId);
        $beforeConflict = $this->checkpointState();

        try {
            $resumedEvents->next();
            self::fail('The reconstructed REST overlap must retain the acknowledged digest.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame(
            [['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 300]]],
            $resumedRest->calls,
        );
        $afterConflict = $this->checkpointState();
        self::assertNull($afterConflict['pending_event']);
        self::assertNull($afterConflict['pending_frontier']);
        self::assertSame($beforeConflict['ordinal_state'], $afterConflict['ordinal_state']);
        self::assertSame($beforeConflict['stream_frontiers'], $afterConflict['stream_frontiers']);
        self::assertSame(
            $beforeConflict['pending_transition'],
            $afterConflict['pending_transition'],
        );
    }

    public function testPendingInitialRestBookRestartReplaysBeforeRestAndContinuesBoundary(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 5; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }
        $book = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $book);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $book->channel);
        self::assertSame('rest_initial_snapshot', $book->payload['origin'] ?? null);
        $pending = $this->checkpointState();
        self::assertNull($pending['stream_frontiers']['BTCUSDT/rest/top_of_book'] ?? null);
        self::assertSame(
            'BTCUSDT/rest/top_of_book',
            $pending['pending_frontier']['stream'] ?? null,
        );

        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumed = $this->source(
            $resumedRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayed);
        self::assertEquals($book->toArray(), $replayed->toArray());
        self::assertSame([], $resumedRest->calls);
        self::assertNull(
            $this->checkpointState()['stream_frontiers']['BTCUSDT/rest/top_of_book'] ?? null,
        );

        $resumed->acknowledge($replayed->eventId);
        $acknowledged = $this->checkpointState();
        self::assertNull($acknowledged['pending_event']);
        self::assertNull($acknowledged['pending_frontier']);
        self::assertSame(
            '9001',
            $acknowledged['stream_frontiers']['BTCUSDT/rest/top_of_book']['source_identity']
                ?? null,
        );
        $resumedEvents->next();
        $boundary = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $boundary->channel);
        self::assertSame('BTCUSDT', $boundary->symbol);
        self::assertSame('initial', $boundary->payload['reason'] ?? null);
        self::assertSame([], $resumedRest->calls);
    }

    /** @param array<string, mixed> $expectedTransition */
    #[DataProvider('transportContinuationProvider')]
    public function testContinuationRestartsWithExactSavedTransportAction(
        string $failureSocket,
        string $failureOperation,
        array $expectedTransition,
    ): void {
        $firstLog = new Task7ActionLog();
        $public = new Task7Transport(
            'public',
            $firstLog,
            $failureSocket === 'public' ? $failureOperation : null,
        );
        $business = new Task7Transport(
            'business',
            $firstLog,
            $failureSocket === 'business' ? $failureOperation : null,
        );
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }
        try {
            $events->next();
            self::fail('The configured transition interruption must stop acquisition.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'task7_transport_interrupt',
                $exception->getMessage(),
                $exception->getTraceAsString(),
            );
        }
        $beforeRestart = $this->checkpointState();
        self::assertSame($expectedTransition, $beforeRestart['pending_transition']);

        $source->stop();
        unset($events, $source, $public, $business, $exception);
        gc_collect_cycles();

        $restartLog = new Task7ActionLog();
        $resumedRest = new Task7RestClient();
        $resumedPublic = new Task7Transport('public', $restartLog);
        $resumedBusiness = new Task7Transport('business', $restartLog);
        $resumedPublic->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9950']),
        ];
        $resumedBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $resumed = $this->source(
            $resumedRest,
            $resumedPublic,
            $resumedBusiness,
            checkpointStore: $store,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $firstData = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstData);
        self::assertSame('9950', $firstData->payload['trade_id'] ?? null);
        self::assertSame(
            ['connect:public', 'send:public', 'connect:business', 'send:business'],
            $restartLog->actions,
        );
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $resumedPublic->connections);
        self::assertSame(
            [OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI],
            $resumedBusiness->connections,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::publicArguments()]],
            $resumedPublic->sent,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::businessArguments()]],
            $resumedBusiness->sent,
        );
        self::assertSame([], $resumedRest->calls);
        $afterRestart = $this->checkpointState();
        self::assertSame('streaming', $afterRestart['phase']);
        self::assertNotNull($afterRestart['pending_event']);
        foreach ([
            'connection_epoch',
            'remaining_symbols',
            'remaining_boundaries',
            'source_epochs',
            'reconnect',
            'resync_by_symbol',
            'overlap_pagination_by_stream',
        ] as $field) {
            self::assertSame($beforeRestart[$field], $afterRestart[$field], $field);
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function transportContinuationProvider(): iterable
    {
        yield 'public connect' => [
            'public',
            'connect',
            ['kind' => 'transport_connect', 'stage' => 'connect', 'stream' => 'public', 'symbol' => null],
        ];
        yield 'public subscribe' => [
            'public',
            'send',
            ['kind' => 'subscription_send', 'stage' => 'subscribe', 'stream' => 'public', 'symbol' => null],
        ];
        yield 'business connect' => [
            'business',
            'connect',
            ['kind' => 'transport_connect', 'stage' => 'connect', 'stream' => 'business', 'symbol' => null],
        ];
        yield 'business subscribe' => [
            'business',
            'send',
            ['kind' => 'subscription_send', 'stage' => 'subscribe', 'stream' => 'business', 'symbol' => null],
        ];
    }

    #[DataProvider('boundaryContinuationProvider')]
    public function testContinuationRestartsWithExactSavedBoundaryAction(
        int $eventsBeforeBoundary,
        string $symbol,
    ): void {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $clock = new Task7InterruptingClock();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < $eventsBeforeBoundary; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < $eventsBeforeBoundary - 1) {
                $events->next();
            }
        }
        $clock->interrupt = true;
        try {
            $events->next();
            self::fail('Boundary normalization must be interrupted after its transition is durable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('task7_clock_interrupt', $exception->getMessage());
        }
        $beforeRestart = $this->checkpointState();
        self::assertSame(
            [
                'kind' => 'emit_boundary',
                'stage' => 'initial',
                'stream' => $symbol . '/control/snapshot_boundary',
                'symbol' => $symbol,
            ],
            $beforeRestart['pending_transition'],
        );

        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $rest = new Task7RestClient();
        $resumed = $this->source(
            $rest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $boundary = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $boundary->channel);
        self::assertSame($symbol, $boundary->symbol);
        self::assertSame('initial', $boundary->payload['reason'] ?? null);
        self::assertSame([], $rest->calls);
        $afterRestart = $this->checkpointState();
        foreach ([
            'connection_epoch',
            'remaining_symbols',
            'remaining_boundaries',
            'source_epochs',
            'reconnect',
            'resync_by_symbol',
            'overlap_pagination_by_stream',
        ] as $field) {
            self::assertSame($beforeRestart[$field], $afterRestart[$field], $field);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function boundaryContinuationProvider(): iterable
    {
        yield 'BTC boundary' => [6, 'BTCUSDT'];
        yield 'ETH boundary' => [13, 'ETHUSDT'];
    }

    public function testMultiRowContinuationSortsAndRestartSkipsExactFrontierOnce(): void
    {
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
        ];
        $source = $this->source($initialRest, new Task7Transport(), new Task7Transport());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $first = $events->current();
        self::assertNotNull($first);
        self::assertSame('1784970000000', $first->exchangeTimestamp->format('Uv'));
        $source->acknowledge($first->eventId);
        $events->next();
        $durableMiddle = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $durableMiddle);
        self::assertSame('1784970001000', $durableMiddle->exchangeTimestamp->format('Uv'));
        $source->acknowledge($durableMiddle->eventId);

        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumedRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
        ];
        $resumed = $this->source($resumedRest, new Task7Transport(), new Task7Transport());
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $second = $resumedEvents->current();
        self::assertNotNull($second);
        self::assertSame('1784970002000', $second->exchangeTimestamp->format('Uv'));
        self::assertNotSame($first->eventId, $second->eventId);
        self::assertNotSame($durableMiddle->eventId, $second->eventId);
        self::assertSame(
            [['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 300]]],
            $resumedRest->calls,
        );

        $resumed->acknowledge($second->eventId);
        $resumedEvents->next();
        self::assertSame(PaperMarketDataChannel::CANDLE_5M, $resumedEvents->current()?->channel);
    }

    public function testFrontierMiddleOverlapRejectsChangedDurableIdentityDigest(): void
    {
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
        ];
        $source = $this->source($initialRest, new Task7Transport(), new Task7Transport());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index === 0) {
                $events->next();
            }
        }

        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumedRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.6', '11', '1', '1100', '1'],
            ['1784970002000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
        ];
        $resumed = $this->source(
            $resumedRest,
            new Task7Transport(),
            new Task7Transport(),
        );

        try {
            $resumedEvents = $resumed->events();
            self::assertInstanceOf(\Generator::class, $resumedEvents);
            $resumedEvents->current();
            self::fail('The durable middle identity must retain its exact digest.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
    }

    public function testMultiRowWebSocketReplayYieldsEveryTradeExactlyOnceInFrameOrder(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $frame = Task7Transport::tradeFrame(['9101', '9102', '9103']);
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $frame,
            $frame,
            Task7Transport::tradeFrame(['9200']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $tradeIds = [];
        for ($index = 0; $index < 4; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $tradeIds[] = $event->payload['trade_id'] ?? null;
            $source->acknowledge($event->eventId);
            if ($index < 3) {
                $events->next();
            }
        }

        self::assertSame(['9101', '9102', '9103', '9200'], $tradeIds);
    }

    public function testMultiRowWebSocketExactDuplicateInSameFrameIsSilentAndUsesOneOrdinal(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9101', '9101']),
            Task7Transport::tradeFrame(['9200']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }
        $before = $this->checkpointState();
        $beforeSequence = $before['ordinal_state']['scopes']
            ['okx/BTCUSDT/public_trade']['last_sequence'] ?? null;
        self::assertIsString($beforeSequence);

        $events->next();
        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('9101', $first->payload['trade_id'] ?? null);
        $pending = $this->checkpointState();
        self::assertNull($pending['stream_frontiers']['BTCUSDT/ws/public_trade'] ?? null);
        self::assertSame(
            '9101',
            $pending['pending_frontier']['frontier']['source_identity'] ?? null,
        );
        $pendingSequence = $pending['ordinal_state']['scopes']
            ['okx/BTCUSDT/public_trade']['last_sequence'] ?? null;
        self::assertSame((string) ((int) $beforeSequence + 1), $pendingSequence);

        $expectedFrontier = $pending['pending_frontier']['frontier'] ?? null;
        self::assertIsArray($expectedFrontier);
        $source->acknowledge($first->eventId);
        $acknowledged = $this->checkpointState();
        self::assertNull($acknowledged['pending_frontier']);
        self::assertSame(
            $expectedFrontier,
            $acknowledged['stream_frontiers']['BTCUSDT/ws/public_trade'] ?? null,
        );
        self::assertSame($pending['ordinal_state'], $acknowledged['ordinal_state']);

        $events->next();
        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame('9200', $sentinel->payload['trade_id'] ?? null);
        self::assertSame((string) ((int) $first->sequence + 1), $sentinel->sequence);
        self::assertSame(
            $expectedFrontier,
            $this->checkpointState()['stream_frontiers']['BTCUSDT/ws/public_trade'] ?? null,
        );
    }

    public function testMultiRowWebSocketChangedDuplicateInSameFrameFailsBeforeNormalization(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $conflict = Task7Transport::tradeFrame(['9101', '9101']);
        $conflict['data'][1]['px'] = '100.6';
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $conflict,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }
        $before = $this->checkpointState();

        try {
            $events->next();
            self::fail('A changed duplicate in one raw frame must fail before normalization.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        $after = $this->checkpointState();
        self::assertNull($after['pending_event']);
        self::assertNull($after['pending_frontier']);
        self::assertSame($before['ordinal_state'], $after['ordinal_state']);
        self::assertSame($before['stream_frontiers'], $after['stream_frontiers']);
    }

    public function testMultiRowWebSocketReplayRejectsConflictingIdentityPayload(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $conflict = Task7Transport::tradeFrame(['9101']);
        $conflict['data'][0]['px'] = '100.6';
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9101']),
            $conflict,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $first = $events->current();
        self::assertSame('9101', $first?->payload['trade_id'] ?? null);
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        $source->acknowledge($first->eventId);

        try {
            $events->next();
            self::fail('The same raw identity with a changed payload must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
    }

    public function testPendingMultiRowCrashReplaysExactPendingThenOnlyFrameRemainder(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $frame = Task7Transport::tradeFrame(['9101', '9102', '9103']);
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $frame,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame('9101', $pending->payload['trade_id'] ?? null);
        $pendingState = $this->checkpointState();

        $source->stop();
        unset($events, $source, $public, $business);
        gc_collect_cycles();

        $subscriptions = new OkxPaperPublicSubscriptionSet(new OkxPaperInstrumentMap());
        foreach (self::publicArguments() as $argument) {
            $subscriptions->acknowledgePublic($argument);
        }
        foreach (self::businessArguments() as $argument) {
            $subscriptions->acknowledgeBusiness($argument);
        }
        $publicQueue = new OkxPaperPublicFrameQueue();
        $publicQueue->enqueue(json_encode($frame, \JSON_THROW_ON_ERROR));
        $resumed = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            subscriptions: $subscriptions,
            publicQueue: $publicQueue,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayed);
        self::assertEquals($pending->toArray(), $replayed->toArray());
        self::assertSame($pending->eventId, $replayed->eventId);
        self::assertSame($pending->payloadHash, $replayed->payloadHash);
        self::assertSame($pendingState['pending_frontier'], $this->checkpointState()['pending_frontier']);
        $resumed->acknowledge($replayed->eventId);

        $remaining = [];
        for ($index = 0; $index < 2; ++$index) {
            $resumedEvents->next();
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $remaining[] = $event->payload['trade_id'] ?? null;
            $resumed->acknowledge($event->eventId);
        }
        self::assertSame(['9102', '9103'], $remaining);
    }

    public function testFrontierRestartFailsClosedWhenCurrentResponseHasNoExactOverlap(): void
    {
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
        ];
        $source = $this->source($initialRest, new Task7Transport(), new Task7Transport());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $first = $events->current();
        self::assertNotNull($first);
        $source->acknowledge($first->eventId);
        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumedRest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970001000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
        ];
        $resumed = $this->source($resumedRest, new Task7Transport(), new Task7Transport());

        try {
            $resumedEvents = $resumed->events();
            self::assertInstanceOf(\Generator::class, $resumedEvents);
            $resumedEvents->current();
            self::fail('A non-overlapping response must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }
    }

    public function testFrontierIsBuiltRawWithOneOrdinalAndMovesOnlyOnAcknowledgement(): void
    {
        $sourceCode = file_get_contents(
            dirname(__DIR__, 5) . '/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php',
        );
        self::assertIsString($sourceCode);
        self::assertStringNotContainsString('new OkxPaperSourceOrdinal()', $sourceCode);

        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            new Task7Transport(),
            new Task7Transport(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $pending = $this->checkpointState();
        self::assertSame('BTCUSDT/rest/candle_1m', $pending['pending_frontier']['stream'] ?? null);
        self::assertNull($pending['stream_frontiers']['BTCUSDT/rest/candle_1m'] ?? null);
        $expected = $pending['pending_frontier']['frontier'] ?? null;
        self::assertIsArray($expected);

        $source->acknowledge($event->eventId);
        $acknowledged = $this->checkpointState();
        self::assertNull($acknowledged['pending_frontier']);
        self::assertSame(
            $expected,
            $acknowledged['stream_frontiers']['BTCUSDT/rest/candle_1m'] ?? null,
        );
    }

    public function testFrontierBookDeepLevelConflictFailsWithStableIdentityError(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->bookRows['BTC-USDT-SWAP'][0]['bids'][] = ['99', '4', '0', '1'];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $applied = Task7Transport::bookFrame('9002', '9001', '5');
        $conflict = Task7Transport::bookFrame('9002', '9001', '6');
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $applied,
            $conflict,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $book = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $book);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $book->channel);
        self::assertSame('9002', $book->payload['source_seq_id'] ?? null);
        $pending = $this->checkpointState();
        self::assertSame('BTCUSDT/ws/top_of_book', $pending['pending_frontier']['stream'] ?? null);
        self::assertNull($pending['stream_frontiers']['BTCUSDT/ws/top_of_book'] ?? null);
        $expected = $pending['pending_frontier']['frontier'] ?? null;
        self::assertIsArray($expected);
        $source->acknowledge($book->eventId);
        self::assertSame(
            $expected,
            $this->checkpointState()['stream_frontiers']['BTCUSDT/ws/top_of_book'] ?? null,
        );

        try {
            $events->next();
            self::fail('A deep-level mutation under the same book sequence must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
    }

    public function testFrontierBookAppliedThenExactReplayIsSilentBeforeTradeSentinel(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->bookRows['BTC-USDT-SWAP'][0]['bids'][] = ['99', '4', '0', '1'];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $applied = Task7Transport::bookFrame('9002', '9001', '5');
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $applied,
            $applied,
            Task7Transport::tradeFrame(['9300']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $book = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $book);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $book->channel);
        $source->acknowledge($book->eventId);
        $events->next();
        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $sentinel->channel);
        self::assertSame('9300', $sentinel->payload['trade_id'] ?? null);
    }

    public function testSubscriptionRoutingRejectsBusinessCandleOnPublicSocket(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            [
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    '1784970400000', '100', '101', '99', '100.5', '10', '1', '1000', '1',
                ]],
            ],
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertNotNull($event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }

        try {
            $events->next();
            self::fail('Public socket candles must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_message_invalid', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $invalidMessage */
    #[DataProvider('wrongSocketRoutingProvider')]
    public function testSubscriptionRoutingFailsClosedBeforeNormalization(
        string $socket,
        array $invalidMessage,
    ): void {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            ...($socket === 'public' ? [$invalidMessage] : []),
        ];
        $business->responses = [
            ...Task7Transport::acknowledgements(self::businessArguments(), 'business'),
            ...($socket === 'business' ? [$invalidMessage] : []),
        ];
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }
        $before = $this->checkpointState();
        self::assertNull($before['pending_event']);
        self::assertNull($before['pending_frontier']);

        try {
            $events->next();
            self::fail('Wrong-socket controls and data must fail before normalization.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_message_invalid', $exception->getMessage());
        }
        $after = $this->checkpointState();
        self::assertNull($after['pending_event']);
        self::assertNull($after['pending_frontier']);
        self::assertSame($before['ordinal_state'], $after['ordinal_state']);
        self::assertSame($before['stream_frontiers'], $after['stream_frontiers']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function wrongSocketRoutingProvider(): iterable
    {
        yield 'public candle control' => ['public', [
            'event' => 'subscribe',
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'connId' => 'wrong-public',
        ]];
        yield 'public candle data' => ['public', [
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [['1784970400000', '100', '101', '99', '100.5', '10', '1', '1000', '1']],
        ]];
        yield 'business trades control' => ['business', [
            'event' => 'subscribe',
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'connId' => 'wrong-business',
        ]];
        yield 'business books control' => ['business', [
            'event' => 'subscribe',
            'arg' => ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
            'connId' => 'wrong-business',
        ]];
        yield 'business trades data' => ['business', Task7Transport::tradeFrame(['9400'])];
        yield 'business books data' => ['business', Task7Transport::bookFrame('9002', '9001', '4')];
    }

    public function testSubscriptionPayloadsNeverExposeRawFramesHeadersOrConnectionIds(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9500']),
            Task7Transport::bookFrame('9002', '9001', '4'),
        ];
        $business->responses = [
            ...Task7Transport::acknowledgements(self::businessArguments(), 'business'),
            [
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
                ]],
            ],
        ];
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $channels = [];
        for ($index = 0; $index < 3; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $channels[] = $event->channel;
            $this->assertPayloadHasNoTransportMetadata($event->payload);
            $source->acknowledge($event->eventId);
            if ($index < 2) {
                $events->next();
            }
        }
        self::assertSame(
            [
                PaperMarketDataChannel::PUBLIC_TRADE,
                PaperMarketDataChannel::TOP_OF_BOOK,
                PaperMarketDataChannel::CANDLE_1M,
            ],
            $channels,
        );
    }

    public function testSubscriptionRoutingRejectsTradeRowForDifferentArgumentInstrument(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $mismatched = Task7Transport::tradeFrame(['9800']);
        $mismatched['data'][0]['instId'] = 'ETH-USDT-SWAP';
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $mismatched,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 13) {
                $events->next();
            }
        }
        $before = $this->checkpointState();
        try {
            $events->next();
            self::fail('A trade row cannot escape its decoded argument instrument.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_message_invalid', $exception->getMessage());
        }
        $after = $this->checkpointState();
        self::assertSame($before['ordinal_state'], $after['ordinal_state']);
        self::assertSame($before['stream_frontiers'], $after['stream_frontiers']);
        self::assertNull($after['pending_event']);
        self::assertNull($after['pending_frontier']);
    }

    public function testWarmupSubscriptionSkipsUnconfirmedBusinessCandle(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = [
            ...Task7Transport::acknowledgements(self::businessArguments(), 'business'),
            [
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    '1784970400000', '100', '101', '99', '100.5', '10', '1', '1000', '0',
                ]],
            ],
            [
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
                ]],
            ],
        ];
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertNotNull($event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $candle = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $candle);
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $candle->channel);
        self::assertSame('1784970460000', $candle->exchangeTimestamp->format('Uv'));
        self::assertTrue($candle->payload['confirmed'] ?? false);
    }

    /** @return list<array{channel: string, instId: string}> */
    private static function publicArguments(): array
    {
        return [
            ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'trades', 'instId' => 'ETH-USDT-SWAP'],
            ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'],
        ];
    }

    /** @return list<array{channel: string, instId: string}> */
    private static function businessArguments(): array
    {
        return [
            ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle5m', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle15m', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle1H', 'instId' => 'BTC-USDT-SWAP'],
            ['channel' => 'candle1m', 'instId' => 'ETH-USDT-SWAP'],
            ['channel' => 'candle5m', 'instId' => 'ETH-USDT-SWAP'],
            ['channel' => 'candle15m', 'instId' => 'ETH-USDT-SWAP'],
            ['channel' => 'candle1H', 'instId' => 'ETH-USDT-SWAP'],
        ];
    }

    /** @return array<string, string> */
    private static function restTrade(string $tradeId, string $timestamp): array
    {
        return [
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => $tradeId,
            'px' => '100.5',
            'sz' => '2',
            'side' => 'buy',
            'source' => '0',
            'ts' => $timestamp,
        ];
    }

    private function source(
        Task7RestClient $rest,
        OkxPaperPublicWebSocketTransportInterface $public,
        OkxPaperPublicWebSocketTransportInterface $business,
        bool $acquisitionEnabled = true,
        ?OkxPaperLiveCheckpointStore $checkpointStore = null,
        ?ClockInterface $clock = null,
        ?LoopInterface $loop = null,
        ?OkxPaperPublicSubscriptionSet $subscriptions = null,
        ?OkxPaperPublicFrameQueue $publicQueue = null,
        ?OkxPaperPublicFrameQueue $businessQueue = null,
    ): OkxPaperPublicLiveSource {
        $store = $checkpointStore ?? new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $config = new OkxPaperPublicConfig(
            acquisitionEnabled: $acquisitionEnabled,
            restBaseUri: OkxPaperPublicConfig::REST_BASE_URI,
            webSocketUri: OkxPaperPublicConfig::WEB_SOCKET_URI,
            dataRoot: $this->testRoot,
        );

        return new OkxPaperPublicLiveSource(
            $rest,
            $public,
            $business,
            $config,
            $clock ?? new MockClock('2026-07-25T10:00:00.000000Z'),
            $store,
            $checkpoint,
            $loop ?? new StreamSelectLoop(),
            subscriptions: $subscriptions,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
        );
    }

    private function acknowledgeWarmup(
        OkxPaperPublicLiveSource $source,
        \Generator $events,
    ): void {
        for ($index = 0; $index < 14; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadHasNoTransportMetadata(array $payload): void
    {
        foreach (array_keys($payload) as $key) {
            self::assertIsString($key);
            self::assertNotContains(
                strtolower($key),
                ['raw', 'frame', 'header', 'headers', 'connid', 'connection_id'],
            );
        }
        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('raw-secret', $encoded);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }

    /** @return array<string, mixed> */
    private function checkpointState(): array
    {
        $contents = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($contents);
        $state = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        return $state;
    }
}

final class Task7RestClient implements OkxPaperPublicRestClientInterface
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    public array $candleRows = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    public array $tradeRows = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    public array $bookRows = [];

    public static function withInitialDataset(): self
    {
        $client = new self();
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentPosition => $instrumentId) {
            foreach (['1m', '5m', '15m', '1H'] as $barPosition => $bar) {
                $timestamp = (string) (1784970000000 + $instrumentPosition * 100000 + $barPosition * 1000);
                $client->candleRows[$instrumentId . '/' . $bar] = [
                    [$timestamp, '100', '101', '99', '100.5', '10', '1', '1000', '1'],
                ];
            }
            $client->tradeRows[$instrumentId] = [[
                'instId' => $instrumentId,
                'tradeId' => (string) (100 + $instrumentPosition),
                'px' => '100.5',
                'sz' => '2',
                'side' => 'buy',
                'source' => '0',
                'ts' => (string) (1784970100000 + $instrumentPosition * 1000),
            ]];
            $client->bookRows[$instrumentId] = [[
                'asks' => [['101', '2', '0', '1']],
                'bids' => [['100', '3', '0', '2']],
                'ts' => (string) (1784970200000 + $instrumentPosition * 1000),
                'seqId' => (string) (9001 + $instrumentPosition),
            ]];
        }

        return $client;
    }

    /** @return list<array{string, list<mixed>}> */
    public static function expectedInitialCalls(): array
    {
        $calls = [];
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentId) {
            foreach (['1m', '5m', '15m', '1H'] as $bar) {
                $calls[] = ['currentCandles', [$instrumentId, $bar, null, null, 300]];
            }
            $calls[] = ['recentTrades', [$instrumentId, 500]];
            $calls[] = ['orderBook', [$instrumentId, 400]];
        }

        return $calls;
    }

    public function historyCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        int $limit = 300,
    ): array {
        $this->calls[] = ['historyCandles', func_get_args()];

        return [];
    }

    public function currentCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        ?string $before = null,
        int $limit = 300,
    ): array {
        $this->calls[] = ['currentCandles', func_get_args()];

        return $this->candleRows[$instrumentId . '/' . $bar] ?? [];
    }

    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array {
        $this->calls[] = ['historyTrades', func_get_args()];

        return [];
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        $this->calls[] = ['recentTrades', func_get_args()];

        return $this->tradeRows[$instrumentId] ?? [];
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        $this->calls[] = ['orderBook', func_get_args()];

        return $this->bookRows[$instrumentId] ?? [];
    }
}

final class Task7Transport implements OkxPaperPublicWebSocketTransportInterface
{
    /** @var list<string> */
    public array $connections = [];

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var list<array<string, mixed>> */
    public array $responses = [];

    /** @var list<array<string, mixed>> */
    public array $connectResponses = [];

    public int $closeCount = 0;

    private ?\Closure $onMessage = null;
    private bool $connected = false;

    public function __construct(
        private readonly ?string $name = null,
        private readonly ?Task7ActionLog $actionLog = null,
        private readonly ?string $failOn = null,
    ) {
    }

    public function connect(
        string $uri,
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        $this->recordAndMaybeFail('connect');
        $this->connections[] = $uri;
        $this->onMessage = \Closure::fromCallable($onMessage);
        $this->connected = true;
        $onOpen();
        foreach ($this->connectResponses as $response) {
            ($this->onMessage)(
                json_encode($response, \JSON_THROW_ON_ERROR),
            );
        }
        $this->connectResponses = [];
    }

    public function send(array $message): void
    {
        if (!$this->connected) {
            throw new \RuntimeException('task7_send_without_connect');
        }
        $this->recordAndMaybeFail('send');
        $this->sent[] = $message;
        foreach ($this->responses as $response) {
            ($this->onMessage ?? throw new \LogicException('transport_not_connected'))(
                json_encode($response, \JSON_THROW_ON_ERROR),
            );
        }
        $this->responses = [];
    }

    public function close(): void
    {
        ++$this->closeCount;
        $this->connected = false;
        $this->onMessage = null;
    }

    /**
     * @param list<array{channel: string, instId: string}> $arguments
     * @return list<array<string, mixed>>
     */
    public static function acknowledgements(array $arguments, string $connectionId): array
    {
        return array_map(
            static fn (array $argument): array => [
                'event' => 'subscribe',
                'arg' => $argument,
                'connId' => $connectionId,
            ],
            $arguments,
        );
    }

    /**
     * @param list<string> $tradeIds
     * @return array<string, mixed>
     */
    public static function tradeFrame(array $tradeIds): array
    {
        return [
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'data' => array_map(
                static fn (string $tradeId): array => [
                    'instId' => 'BTC-USDT-SWAP',
                    'tradeId' => $tradeId,
                    'px' => '100.5',
                    'sz' => '2',
                    'side' => 'buy',
                    'source' => '0',
                    'ts' => (string) (1784970300000 + (int) $tradeId),
                    'count' => '1',
                    'seqId' => $tradeId,
                ],
                $tradeIds,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function bookFrame(
        string $sequence,
        string $previousSequence,
        string $deepBidSize,
    ): array {
        return [
            'arg' => ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
            'action' => 'update',
            'data' => [[
                'asks' => [],
                'bids' => [['99', $deepBidSize, '0', '1']],
                'ts' => '1784970300000',
                'prevSeqId' => $previousSequence,
                'seqId' => $sequence,
            ]],
        ];
    }

    private function recordAndMaybeFail(string $operation): void
    {
        if ($this->name !== null) {
            if ($this->actionLog !== null) {
                $this->actionLog->actions[] = $operation . ':' . $this->name;
            }
        }
        if ($this->failOn === '*' || $this->failOn === $operation) {
            throw new \RuntimeException('task7_transport_interrupt');
        }
    }
}

final class Task7ActionLog
{
    /** @var list<string> */
    public array $actions = [];
}

final class Task7ScriptedLoop implements LoopInterface
{
    /** @var list<callable> */
    public array $scripts = [];

    public function __construct(private readonly DeterministicLoop $delegate)
    {
    }

    public function addReadStream($stream, $listener): void
    {
        $this->delegate->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->delegate->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream): void
    {
        $this->delegate->removeReadStream($stream);
    }

    public function removeWriteStream($stream): void
    {
        $this->delegate->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback): \React\EventLoop\TimerInterface
    {
        return $this->delegate->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): \React\EventLoop\TimerInterface
    {
        return $this->delegate->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(\React\EventLoop\TimerInterface $timer): void
    {
        $this->delegate->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        $this->delegate->futureTick($listener);
    }

    public function addSignal($signal, $listener): void
    {
        $this->delegate->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener): void
    {
        $this->delegate->removeSignal($signal, $listener);
    }

    public function run(): void
    {
        $script = array_shift($this->scripts);
        if ($script !== null) {
            $script();
        }
        $this->delegate->run();
    }

    public function stop(): void
    {
        $this->delegate->stop();
    }
}

final class Task7InterruptingClock implements ClockInterface
{
    public bool $interrupt = false;

    public function now(): \DateTimeImmutable
    {
        if ($this->interrupt) {
            throw new \RuntimeException('task7_clock_interrupt');
        }

        return new \DateTimeImmutable('2026-07-25T10:00:00.000000Z');
    }

    public function sleep(float|int $seconds): void
    {
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
