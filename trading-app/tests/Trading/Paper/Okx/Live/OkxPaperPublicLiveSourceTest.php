<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperDurableBatchSourceInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperLiveDatasetCapture;
use App\Trading\Paper\Dataset\PaperLiveEventConsumerInterface;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperFundingRateClientInterface;
use App\Trading\Paper\Okx\Live\OkxPaperAcknowledgedIdentityEntry;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpoint;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpointStore;
use App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier;
use App\Trading\Paper\Okx\Live\OkxPaperLiveIntegrityException;
use App\Trading\Paper\Okx\Live\OkxPaperLivePolicy;
use App\Trading\Paper\Okx\Live\OkxPaperLoopPumpInterface;
use App\Trading\Paper\Okx\Live\OkxPaperOrderBookMaterializer;
use App\Trading\Paper\Okx\Live\OkxPaperPublicFrameQueue;
use App\Trading\Paper\Okx\Live\OkxPaperPausableWebSocketTransportInterface;
use App\Trading\Paper\Okx\Live\OkxPaperPublicLiveSource;
use App\Trading\Paper\Okx\Live\ReactOkxPaperLoopPump;
use App\Trading\Paper\Okx\Live\OkxPaperPublicSubscriptionSet;
use App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\Okx\Live\OkxPaperRetainedTradeRow;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(OkxPaperPublicLiveSource::class)]
#[CoversClass(OkxPaperAcknowledgedIdentityEntry::class)]
final class OkxPaperPublicLiveSourceTest extends TestCase
{
    public function testAuthenticatedReferenceDataPrecedesWarmupMarketEvents(): void
    {
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            new Task7Transport(),
            new Task7Transport(),
            metadataClient: new StaticOkxPaperMetadataClient(),
            fundingClient: new StaticOkxPaperFundingClient(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $events->rewind();
        $metadata = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $metadata);
        self::assertSame(PaperMarketDataChannel::INSTRUMENT_METADATA, $metadata->channel);
        self::assertSame('BTCUSDT', $metadata->symbol);
        self::assertSame(1, $metadata->payload['source_epoch']);
        $source->acknowledge($metadata->eventId);
        $events->next();
        $funding = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $funding);
        self::assertSame(PaperMarketDataChannel::FUNDING_RATE, $funding->channel);
        self::assertSame('BTCUSDT', $funding->symbol);
        self::assertSame(28800, $funding->payload['funding_interval_seconds']);
        $source->acknowledge($funding->eventId);
        $events->next();
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $events->current()?->channel);
        $source->stop();
    }

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

    public function testInitialCandleBridgeSubscribesBeforeClosingTheWarmupGap(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $initial = $rest->candleRows['BTC-USDT-SWAP/1m'];
        $next = $initial[0];
        $next[0] = (string) ((int) $next[0] + 60_000);
        $rest->candleResponsePages['BTC-USDT-SWAP/1m'] = [
            $initial,
            [$next, $initial[0]],
        ];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['101']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $rest->beforeCurrentCandles = static function (
            string $instrumentId,
            string $bar,
            int $call,
        ) use ($public, $business): void {
            if ($instrumentId === 'BTC-USDT-SWAP' && $bar === '1m' && $call === 2) {
                self::assertNotSame([], $public->connections);
                self::assertNotSame([], $business->connections);
            }
        };
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $this->acknowledgeWarmupEvents($source, $events, 14);
        $bridged = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $bridged);
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $bridged->channel);
        self::assertSame('BTCUSDT', $bridged->symbol);
        self::assertSame((string) $next[0], $bridged->exchangeTimestamp->format('Uv'));
        $source->stop();
    }

    public function testInitialCandleBridgePaginatesUntilTheAcknowledgedFrontier(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $template = $rest->candleRows['BTC-USDT-SWAP/1m'][0];
        $rows = [];
        for ($index = 0; $index < 602; ++$index) {
            $row = $template;
            $row[0] = (string) ((int) $template[0] + ($index * 60_000));
            $rows[] = $row;
        }
        $rest->candleResponsePages['BTC-USDT-SWAP/1m'] = [
            [$rows[0]],
            array_reverse(array_slice($rows, 302, 300)),
        ];
        $rest->historyCandlePages = [
            array_reverse(array_slice($rows, 2, 300)),
            [$rows[1], $rows[0]],
        ];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(self::publicArguments(), 'public');
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $this->acknowledgeWarmupEvents($source, $events, 14);
        $bridged = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $bridged);
        self::assertSame((string) $rows[1][0], $bridged->exchangeTimestamp->format('Uv'));
        self::assertSame(
            [
                ['historyCandles', ['BTC-USDT-SWAP', '1m', $rows[302][0], 300]],
                ['historyCandles', ['BTC-USDT-SWAP', '1m', $rows[2][0], 300]],
            ],
            array_values(array_filter(
                $rest->calls,
                static fn (array $call): bool => $call[0] === 'historyCandles',
            )),
        );
        $source->stop();
    }

    public function testInitialCandleBridgeStopsPaginationWhenNetworkPumpStartsReconnect(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $pump = new Task7CountingLoopPump();
        $source = $this->source($rest, $public, $business, loopPump: $pump);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        $source->acknowledge($sentinel->eventId);

        $initial = $rest->candleRows['BTC-USDT-SWAP/1m'][0];
        $middle = $initial;
        $middle[0] = (string) ((int) $initial[0] + 60_000);
        $newest = $middle;
        $newest[0] = (string) ((int) $middle[0] + 60_000);
        $rest->candleResponsePages['BTC-USDT-SWAP/1m'] = [[$newest]];
        $rest->historyCandlePages = [[$middle, $initial]];
        $pumpCount = 0;
        $pump->onPump(static function () use (&$pumpCount, $public): void {
            ++$pumpCount;
            if ($pumpCount === 2) {
                $public->disconnect();
            }
        });

        $method = new \ReflectionMethod($source, 'initialCandleBridgeRows');
        $rows = $method->invoke(
            $source,
            'BTCUSDT/rest/candle_1m',
            'BTC-USDT-SWAP',
            '1m',
        );

        self::assertNull($rows);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
    }

    public function testInitialCandleBridgeSkipsUnconfirmedRowsWhileLocatingTheFrontier(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $initial = $rest->candleRows['BTC-USDT-SWAP/1m'];
        $next = $initial[0];
        $next[0] = (string) ((int) $next[0] + 60_000);
        $unconfirmed = $next;
        $unconfirmed[0] = (string) ((int) $unconfirmed[0] + 60_000);
        $unconfirmed[8] = '0';
        $rest->candleResponsePages['BTC-USDT-SWAP/1m'] = [
            $initial,
            [$unconfirmed, $next, $initial[0]],
        ];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(self::publicArguments(), 'public');
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source($rest, $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $this->acknowledgeWarmupEvents($source, $events, 14);
        $bridged = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $bridged);
        self::assertSame((string) $next[0], $bridged->exchangeTimestamp->format('Uv'));
        $source->stop();
    }

    public function testInitialCandleBridgePumpsBetweenDurableBatches(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $initial = $rest->candleRows['BTC-USDT-SWAP/1m'];
        $rows = $initial;
        for ($index = 1; $index <= 101; ++$index) {
            $row = $initial[0];
            $row[0] = (string) ((int) $initial[0][0] + ($index * 60_000));
            $rows[] = $row;
        }
        $rest->candleResponsePages['BTC-USDT-SWAP/1m'] = [$initial, array_reverse($rows)];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(self::publicArguments(), 'public');
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $pump = new Task7CountingLoopPump();
        $source = $this->source($rest, $public, $business, loopPump: $pump);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 14);
        $afterFetch = $pump->count;

        for ($index = 0; $index < 100; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        self::assertGreaterThan($afterFetch, $pump->count);
        self::assertSame((string) $rows[101][0], $events->current()?->exchangeTimestamp->format('Uv'));
        $source->stop();
    }

    public function testWarmupPaginatesExactlyOneThousandConfirmedHourlyCandles(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $hourlyRows = self::contiguousHourlyRows(1000);
        $rest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($hourlyRows, 700, 300));
        $rest->historyCandlePages = [
            array_reverse(array_slice($hourlyRows, 400, 300)),
            array_reverse(array_slice($hourlyRows, 100, 300)),
            array_reverse(array_slice($hourlyRows, 0, 100)),
        ];
        $source = $this->source(
            $rest,
            new Task7Transport(),
            new Task7Transport(),
            initialHourlyCandleTarget: 1000,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        for ($index = 0; $index < 3; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }
        $hourlyTimestamps = [];
        for ($index = 0; $index < 1000; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame(PaperMarketDataChannel::CANDLE_1H, $event->channel);
            $hourlyTimestamps[] = $event->exchangeTimestamp->format('Uv');
            $source->acknowledge($event->eventId);
            if ($index < 999) {
                $events->next();
            }
        }

        self::assertSame(array_column($hourlyRows, 0), $hourlyTimestamps);
        self::assertSame([
            ['historyCandles', ['BTC-USDT-SWAP', '1H', $hourlyRows[700][0], 300]],
            ['historyCandles', ['BTC-USDT-SWAP', '1H', $hourlyRows[400][0], 300]],
            ['historyCandles', ['BTC-USDT-SWAP', '1H', $hourlyRows[100][0], 300]],
        ], array_values(array_filter(
            $rest->calls,
            static fn (array $call): bool => $call[0] === 'historyCandles',
        )));
    }

    public function testWarmupRowsAreExposedInBoundedHundredRowBatches(): void
    {
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
        );
        $rows = array_map(
            static fn (int $index): array => [$index],
            range(0, 250),
        );

        $batches = new \ReflectionMethod($source, 'warmupRowBatches');
        $sizes = array_map(
            count(...),
            iterator_to_array($batches->invoke($source, $rows), false),
        );

        self::assertSame([100, 100, 51], $sizes);
    }

    public function testWarmupAlignsTheThousandHourWindowToTheFourHourGrid(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $hourlyRows = self::contiguousHourlyRows(1002);
        $rest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($hourlyRows, 702, 300));
        $rest->historyCandlePages = [
            array_reverse(array_slice($hourlyRows, 402, 300)),
            array_reverse(array_slice($hourlyRows, 102, 300)),
            array_reverse(array_slice($hourlyRows, 0, 102)),
        ];
        $source = $this->source(
            $rest,
            new Task7Transport(),
            new Task7Transport(),
            initialHourlyCandleTarget: 1000,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 3);

        $hourlyTimestamps = [];
        for ($index = 0; $index < 1002; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $hourlyTimestamps[] = $event->exchangeTimestamp->format('Uv');
            $source->acknowledge($event->eventId);
            if ($index < 1001) {
                $events->next();
            }
        }

        self::assertSame(array_column($hourlyRows, 0), $hourlyTimestamps);
        self::assertSame(
            '00',
            (new \DateTimeImmutable('@' . ((int) $hourlyTimestamps[0] / 1000)))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('H'),
        );
    }

    #[DataProvider('invalidInitialHourlyPaginationProvider')]
    public function testWarmupHourlyPaginationFailsClosedOnInvalidHistory(
        array $currentRows,
        array $historyPages,
        int $target,
    ): void {
        $rest = Task7RestClient::withInitialDataset();
        $rest->candleRows['BTC-USDT-SWAP/1H'] = $currentRows;
        $rest->historyCandlePages = $historyPages;
        $source = $this->source(
            $rest,
            new Task7Transport(),
            new Task7Transport(),
            initialHourlyCandleTarget: $target,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 3, false);

        try {
            $events->next();
            $events->current();
            self::fail('Invalid hourly history must fail before emitting an hourly candle.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_response_invalid', $exception->getMessage());
        }
    }

    public static function invalidInitialHourlyPaginationProvider(): iterable
    {
        $rows = self::contiguousHourlyRows(8);

        yield 'empty history page' => [
            array_reverse(array_slice($rows, 6, 2)),
            [[]],
            4,
        ];
        yield 'non progressing history page' => [
            array_reverse(array_slice($rows, 6, 2)),
            [array_reverse(array_slice($rows, 6, 2))],
            4,
        ];
        $conflict = $rows[6];
        $conflict[4] = '999';
        yield 'conflicting duplicate' => [
            array_reverse(array_slice($rows, 6, 2)),
            [[$conflict, $rows[5], $rows[4]]],
            4,
        ];
        yield 'hourly grid gap' => [
            array_reverse(array_slice($rows, 6, 2)),
            [[$rows[4], $rows[3]]],
            4,
        ];
        yield 'history page budget exhausted' => [
            [$rows[7]],
            [[$rows[6]], [$rows[5]], [$rows[4]], [$rows[3]]],
            6,
        ];
    }

    public function testWarmupHourlyPaginationAcceptsExactDuplicateOnlyWithOlderProgress(): void
    {
        $rows = self::contiguousHourlyRows(5);
        $rest = Task7RestClient::withInitialDataset();
        $rest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($rows, 2, 3));
        $rest->historyCandlePages = [[$rows[2], $rows[1], $rows[0]]];
        $source = $this->source(
            $rest,
            new Task7Transport(),
            new Task7Transport(),
            initialHourlyCandleTarget: 5,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 3);

        $actual = [];
        for ($index = 0; $index < 5; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $actual[] = $event->exchangeTimestamp->format('Uv');
            $source->acknowledge($event->eventId);
            if ($index < 4) {
                $events->next();
            }
        }
        self::assertSame(array_column($rows, 0), $actual);
    }

    public function testWarmupHourlyPaginationRestartsFromPendingEventWithoutDuplicate(): void
    {
        $rows = self::contiguousHourlyRows(6);
        $originalWindow = array_slice($rows, 0, 5);
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($originalWindow, 2, 3));
        $initialRest->historyCandlePages = [[$rows[2], $rows[1], $rows[0]]];
        $source = $this->source(
            $initialRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            initialHourlyCandleTarget: 5,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 3);

        $actual = [];
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $actual[] = $event->exchangeTimestamp->format('Uv');
            $source->acknowledge($event->eventId);
            $events->next();
        }
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);

        unset($events, $source);
        gc_collect_cycles();

        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($rows, 3, 3));
        $restartRest->historyCandlePages = [
            array_reverse(array_slice($originalWindow, 2, 3)),
            [$rows[2], $rows[1], $rows[0]],
        ];
        $resumed = $this->source(
            $restartRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            initialHourlyCandleTarget: 5,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayed);
        self::assertEquals($pending->toArray(), $replayed->toArray());
        self::assertSame([], $restartRest->calls);

        for ($index = 2; $index < 6; ++$index) {
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $actual[] = $event->exchangeTimestamp->format('Uv');
            $resumed->acknowledge($event->eventId);
            if ($index < 5) {
                $resumedEvents->next();
            }
        }
        self::assertSame(array_column($rows, 0), $actual);
        self::assertCount(6, array_unique($actual));
        self::assertContains(
            ['currentCandles', ['BTC-USDT-SWAP', '1H', null, null, 300]],
            $restartRest->calls,
        );
    }

    public function testWarmupResumeRejectsChangedAcknowledgedRowBeforeOverlap(): void
    {
        $rows = self::contiguousHourlyRows(6);
        $originalWindow = array_slice($rows, 0, 5);
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($originalWindow, 2, 3));
        $initialRest->historyCandlePages = [[$rows[2], $rows[1], $rows[0]]];
        $source = $this->source(
            $initialRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            initialHourlyCandleTarget: 5,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 3);
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        unset($events, $source);
        gc_collect_cycles();

        $changed = $rows[0];
        $changed[4] = '999';
        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($rows, 3, 3));
        $restartRest->historyCandlePages = [
            array_reverse(array_slice($originalWindow, 2, 3)),
            [$rows[2], $rows[1], $changed],
        ];
        $resumed = $this->source(
            $restartRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            initialHourlyCandleTarget: 5,
        );

        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $pending = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        $resumed->acknowledge($pending->eventId);

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('market_event_identity_conflict');
        $resumedEvents->next();
    }

    public function testProductionHourlyWarmupResumesBeyondAcknowledgedIdentityWindow(): void
    {
        $rows = self::contiguousHourlyRows(1002);
        $initialWindow = array_slice($rows, 0, 1000);
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $initialRest = Task7RestClient::withInitialDataset();
        $initialRest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($initialWindow, 700, 300));
        $initialRest->historyCandlePages = [
            array_reverse(array_slice($initialWindow, 400, 300)),
            array_reverse(array_slice($initialWindow, 100, 300)),
            array_reverse(array_slice($initialWindow, 0, 100)),
        ];
        $source = $this->source(
            $initialRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            initialHourlyCandleTarget: 1000,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 3);
        for ($index = 0; $index < 351; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame($rows[$index][0], $event->exchangeTimestamp->format('Uv'));
            $source->acknowledge($event->eventId);
            if ($index < 350) {
                $events->next();
            }
        }

        unset($events, $source);
        gc_collect_cycles();

        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->candleRows['BTC-USDT-SWAP/1H'] = array_reverse(array_slice($rows, 702, 300));
        $restartRest->historyCandlePages = [
            array_reverse(array_slice($initialWindow, 700, 300)),
            array_reverse(array_slice($initialWindow, 400, 300)),
            array_reverse(array_slice($initialWindow, 100, 300)),
            array_reverse(array_slice($initialWindow, 0, 100)),
        ];
        $resumed = $this->source(
            $restartRest,
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            initialHourlyCandleTarget: 1000,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $next = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $next);
        self::assertSame($rows[351][0], $next->exchangeTimestamp->format('Uv'));
        for ($index = 351; $index < 1002; ++$index) {
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame($rows[$index][0], $event->exchangeTimestamp->format('Uv'));
            $resumed->acknowledge($event->eventId);
            if ($index < 1001) {
                $resumedEvents->next();
            }
        }
        self::assertContains(
            ['currentCandles', ['BTC-USDT-SWAP', '1H', null, null, 300]],
            $restartRest->calls,
        );
    }

    public function testWarmupHourlyPaginationAppliesTheTargetToBothSymbols(): void
    {
        $rows = self::contiguousHourlyRows(5);
        $rest = Task7RestClient::withInitialDataset();
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentId) {
            $rest->candleRows[$instrumentId . '/1H'] = array_reverse(array_slice($rows, 2, 3));
        }
        $older = [[$rows[2], $rows[1], $rows[0]]];
        $rest->historyCandlePages = [...$older, ...$older];
        $source = $this->source(
            $rest,
            new Task7Transport(),
            new Task7Transport(),
            initialHourlyCandleTarget: 5,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $hourlyBySymbol = ['BTCUSDT' => [], 'ETHUSDT' => []];
        $boundaries = [];

        for ($index = 0; $index < 22; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel === PaperMarketDataChannel::CANDLE_1H) {
                $hourlyBySymbol[$event->symbol][] = $event->exchangeTimestamp->format('Uv');
            }
            if ($event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY) {
                $boundaries[] = $event->symbol;
            }
            $source->acknowledge($event->eventId);
            if ($index < 21) {
                $events->next();
            }
        }

        self::assertSame(array_column($rows, 0), $hourlyBySymbol['BTCUSDT']);
        self::assertSame(array_column($rows, 0), $hourlyBySymbol['ETHUSDT']);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $boundaries);
        self::assertSame(2, count(array_filter(
            $rest->calls,
            static fn (array $call): bool => $call[0] === 'historyCandles',
        )));
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

    public function testWarmupRejectsEmptyRecentTradeBatch(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->tradeRows['BTC-USDT-SWAP'] = [];
        $source = $this->source($rest, new Task7Transport(), new Task7Transport());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        for ($index = 0; $index < 4; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 3) {
                $events->next();
            }
        }

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('okx_paper_public_response_invalid');
        $events->next();
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
            static function (): void {
                // The real connector can wake the shared loop once before an ACK arrives.
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
        self::assertSame(6, $deterministic->runCount);
    }

    public function testBusinessConnectSurvivesPublicSocketProgressOnSharedLoop(): void
    {
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $publicAcknowledgements = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $public->message($publicAcknowledgements[0]),
            static fn () => $business->open(),
            static function () use ($public, $publicAcknowledgements): void {
                foreach (array_slice($publicAcknowledgements, 1) as $acknowledgement) {
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9601'])),
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
            $events->next();
        }

        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        self::assertSame('9601', $trade->payload['trade_id'] ?? null);
        self::assertSame(6, $deterministic->runCount);
    }

    public function testBusinessConnectHandsOffWhenPublicCloseStartsANewGeneration(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(attempt: 0),
            static fn () => $public->disconnect(attempt: 0),
            static function () use ($clock, $deterministic, $public): void {
                $clock->sleep(1);
                $deterministic->fireTimerInterval(1.0);
                $public->open(attempt: 1);
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'publicRetry',
                ) as $acknowledgement) {
                    $public->message($acknowledgement, attempt: 1);
                }
            },
            static function () use ($business): void {
                $business->open(attempt: 1);
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'businessRetry',
                ) as $acknowledgement) {
                    $business->message($acknowledgement, attempt: 1);
                }
            },
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $this->acknowledgeWarmup($source, $events);

        self::assertInstanceOf(PaperMarketEvent::class, $events->current());
        self::assertSame('reconnecting', $events->current()?->payload['state'] ?? null);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertNull($source->failureReason());
        self::assertCount(2, $public->connections);
        self::assertCount(2, $business->connections);
    }

    public function testInitialConnectKeepsItsInternalCloseCauseBehindPublicFailure(): void
    {
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $loop = new Task7ScriptedLoop(new DeterministicLoop());
        $loop->scripts = [static fn () => $public->disconnect()];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            new FakeOkxPaperPublicWebSocketTransport(),
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
            self::fail('The unopened public socket must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_reconnect_exhausted', $exception->getMessage());
            self::assertInstanceOf(
                OkxPaperLiveIntegrityException::class,
                $exception->getPrevious(),
            );
            self::assertInstanceOf(
                \LogicException::class,
                $exception->getPrevious()->getPrevious(),
            );
            self::assertSame(
                'okx_paper_public_connect_closed_before_open_public',
                $exception->getPrevious()->getPrevious()->getMessage(),
            );
        }
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
        $resumedRest = Task7RestClient::withInitialDataset();
        $resumed = $this->source(
            $resumedRest,
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
            ['connect:public', 'connect:business', 'send:public', 'send:business'],
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
        self::assertSame(Task7RestClient::expectedInitialBridgeCalls(), $resumedRest->calls);
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
        $source = $this->source(
            $initialRest,
            new Task7Transport(),
            new Task7Transport(),
            metadataClient: new StaticOkxPaperMetadataClient(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame(PaperMarketDataChannel::INSTRUMENT_METADATA, $pending->channel);

        unset($events, $source);
        gc_collect_cycles();

        $resumedRest = Task7RestClient::withInitialDataset();
        $resumed = $this->source(
            $resumedRest,
            new Task7Transport(),
            new Task7Transport(),
            metadataClient: new StaticOkxPaperMetadataClient(),
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertNotNull($replayed);
        self::assertSame(
            $pending->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'),
            $replayed->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'),
        );
        self::assertSame($pending->payloadHash, $replayed->payloadHash);
        self::assertSame($pending->eventId, $replayed->eventId);
        self::assertSame($pending->toArray(), $replayed->toArray());
        self::assertSame([], $resumedRest->calls);

        $resumed->acknowledge($replayed->eventId);
        $resumedEvents->next();
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $resumedEvents->current()?->channel);
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
        self::assertSame('failed', $afterConflict['phase']);
        self::assertSame('market_event_identity_conflict', $afterConflict['failure_reason']);
        self::assertNull($afterConflict['pending_transition']);
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
        $resumedRest = Task7RestClient::withInitialDataset();
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
            ['connect:public', 'connect:business', 'send:public', 'send:business'],
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
        self::assertSame(Task7RestClient::expectedInitialBridgeCalls(), $resumedRest->calls);
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

    public function testMultiRowWebSocketFrameCommitsAsOneDurableBatch(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9101', '9102', '9103']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        self::assertInstanceOf(PaperDurableBatchSourceInterface::class, $source);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('9101', $first->payload['trade_id'] ?? null);
        self::assertSame(3, $source->pendingDurableBatchSize());
        $durableFirst = $this->checkpointState()['pending_event'] ?? null;
        self::assertIsArray($durableFirst);
        self::assertSame($first->eventId, $durableFirst['event_id'] ?? null);

        $source->acknowledge($first->eventId);
        $events->next();
        $second = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $second);
        self::assertSame('9102', $second->payload['trade_id'] ?? null);
        self::assertSame(2, $source->pendingDurableBatchSize());
        self::assertSame($durableFirst, $this->checkpointState()['pending_event']);

        $source->acknowledge($second->eventId);
        $events->next();
        $third = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $third);
        self::assertSame('9103', $third->payload['trade_id'] ?? null);
        self::assertSame(1, $source->pendingDurableBatchSize());
        self::assertSame($durableFirst, $this->checkpointState()['pending_event']);

        $source->acknowledge($third->eventId);
        self::assertNull($this->checkpointState()['pending_event']);
        self::assertSame(
            $third->eventId,
            $this->checkpointState()['last_acknowledged_event_id'] ?? null,
        );
    }

    public function testQueuedWebSocketFramesShareOneBoundedDurableBatch(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
            ...array_map(
                static fn (int $offset): array => Task7Transport::tradeFrame([
                    (string) (9100 + $offset),
                ]),
                range(1, 129),
            ),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame('9000', $sentinel->payload['trade_id'] ?? null);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($sentinel->eventId);
        $events->next();

        $tradeIds = [];
        for ($remaining = 128; $remaining > 0; --$remaining) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $tradeIds[] = $event->payload['trade_id'] ?? null;
            self::assertSame($remaining, $source->pendingDurableBatchSize());
            $source->acknowledge($event->eventId);
            if ($remaining > 1) {
                $events->next();
            }
        }

        $events->next();
        $last = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $last);
        self::assertSame('9229', $last->payload['trade_id'] ?? null);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($last->eventId);

        self::assertSame(
            array_map(static fn (int $offset): string => (string) (9100 + $offset), range(1, 128)),
            $tradeIds,
        );
        self::assertNull($this->checkpointState()['pending_event']);
    }

    public function testDurableFrameCompletionPumpsTheNetworkLoopBetweenBatches(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
            Task7Transport::tradeFrame(['9101']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $pump = new Task7CountingLoopPump();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            loopPump: $pump,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $bridgePumpCount = $pump->count;

        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($first->eventId);
        self::assertSame($bridgePumpCount + 1, $pump->count);

        $events->next();
        $second = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $second);
        $source->acknowledge($second->eventId);
        self::assertSame($bridgePumpCount + 2, $pump->count);
    }

    public function testDurableRestBatchCompletionPumpsTheNetworkLoop(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->tradeRows['BTC-USDT-SWAP'] = [
            ...$rest->tradeRows['BTC-USDT-SWAP'],
            [
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => '101',
                'px' => '100.6',
                'sz' => '3',
                'side' => 'sell',
                'source' => '0',
                'ts' => '1784970100001',
            ],
            [
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => '102',
                'px' => '100.7',
                'sz' => '4',
                'side' => 'buy',
                'source' => '0',
                'ts' => '1784970100002',
            ],
        ];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $pump = new Task7CountingLoopPump();
        $source = $this->source($rest, $public, $business, loopPump: $pump);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        for ($index = 0; $index < 4; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertStringStartsWith('candle_', $event->channel->value);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $pumpCountBeforeBatch = $pump->count;
        for ($remaining = 3; $remaining > 0; --$remaining) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $event->channel);
            self::assertSame($remaining, $source->pendingDurableBatchSize());
            $source->acknowledge($event->eventId);
            if ($remaining > 1) {
                $events->next();
            }
        }

        self::assertSame($pumpCountBeforeBatch + 1, $pump->count);
        self::assertNull($this->checkpointState()['pending_event']);
    }

    /**
     * While streaming, the frames admitted by a pump are persisted with it only
     * once STREAMING_QUEUE_SAVE_INTERVAL_SECONDS have passed since the previous
     * queue save; until then they are in the position of frames still in the
     * socket buffer (a restart reconnects and recovers them).
     */
    #[DataProvider('streamingQueueSaveIntervalProvider')]
    public function testOneNetworkPumpCoalescesItsAdmittedQueueCheckpoint(
        float $secondsDuringPump,
        int $expectedQueueSyncs,
        int $expectedPersistedFrames,
    ): void {
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $pump = new Task7CountingLoopPump();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loopPump: $pump,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);

        $pump->onPump(static function () use ($public, $clock, $secondsDuringPump): void {
            $clock->sleep($secondsDuringPump);
            foreach (range(1, 10) as $offset) {
                $public->message(Task7Transport::tradeFrame([
                    (string) (9100 + $offset),
                ]));
            }
        });
        $before = count(array_filter(
            $filesystem->operations,
            static fn (string $operation): bool => $operation
                === 'sync:okx_paper_live_queue_sync',
        ));

        $source->acknowledge($sentinel->eventId);

        $after = count(array_filter(
            $filesystem->operations,
            static fn (string $operation): bool => $operation
                === 'sync:okx_paper_live_queue_sync',
        ));
        self::assertSame($expectedQueueSyncs, $after - $before);
        self::assertSame(
            $expectedPersistedFrames,
            $this->checkpointState()['streaming_queue_ref']['public']['frames'] ?? 0,
        );
    }

    /** @return iterable<string, array{float, int, int}> */
    public static function streamingQueueSaveIntervalProvider(): iterable
    {
        // The completed batch's queue save, then the pump's once the interval passed.
        yield 'interval passed during the pump' => [0.25, 2, 10];
        // The pump's frames wait for the next save (or the batch that consumes them).
        yield 'within the interval' => [0.1, 1, 0];
    }

    public function testReactLoopPumpRunsExactlyOneNonBlockingTick(): void
    {
        $loop = new DeterministicLoop();

        (new ReactOkxPaperLoopPump($loop))->pump();

        self::assertSame(1, $loop->runCount);
        self::assertTrue($loop->stopped);
    }

    public function testReactLoopPumpPollsAReadableStreamBeforeReturning(): void
    {
        $loop = new StreamSelectLoop();
        $streams = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        self::assertIsArray($streams);
        self::assertTrue(stream_set_blocking($streams[0], false));
        $received = null;
        $loop->addReadStream($streams[0], static function ($stream) use (
            &$received,
            $loop,
        ): void {
            $received = fread($stream, 8192);
            $loop->removeReadStream($stream);
        });

        try {
            self::assertSame(6, fwrite($streams[1], 'polled'));

            (new ReactOkxPaperLoopPump($loop))->pump();

            self::assertSame('polled', $received);
        } finally {
            fclose($streams[0]);
            fclose($streams[1]);
        }
    }

    public function testNetworkPumpCannotStarveTheBusinessQueue(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $pump = new Task7CountingLoopPump();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
            loopPump: $pump,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $pump->onPump(static function () use ($publicQueue): void {
            $publicQueue->enqueue(json_encode(
                Task7Transport::tradeFrame(['9200']),
                \JSON_THROW_ON_ERROR,
            ));
        });

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $businessQueue->enqueue(json_encode([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970520000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ], \JSON_THROW_ON_ERROR));
        $source->acknowledge($sentinel->eventId);

        $events->next();
        $businessEvent = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $businessEvent);
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $businessEvent->channel);
        self::assertSame(1, $publicQueue->count());
    }

    public function testLiveQueueOverflowsIntoTheInboundBufferWithoutPausingTheSocket(): void
    {
        $logger = new OkxRecordingLogger();
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        [$source, $events, $public, $publicQueue] = $this->bufferedStreamingFixture($logger, clock: $clock);
        // A burst: the durable queue fills up, the rest waits in memory, and the
        // socket is never paused (OKX closes consumers that stop reading).
        for ($index = 0; $index < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 299; ++$index) {
            $public->message(Task7Transport::tradeFrame([(string) (10000 + $index)]));
        }
        self::assertSame(0, $public->pauseCount);
        self::assertSame(OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES, $publicQueue->count());
        // A brief overflow is not logged; a backlog lasting 10 s is.
        self::assertNotContains('okx_paper_public_inbound_buffer', $logger->messages());
        $clock->sleep(10);
        $public->message(Task7Transport::tradeFrame([(string) (10000 + OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 299)]));
        $occupancy = $logger->first('okx_paper_public_inbound_buffer');
        self::assertSame(300, $occupancy['public_frames']);
        self::assertSame(10.0, $occupancy['oldest_age_s']);

        $tradeIds = [];
        while (\count($tradeIds) < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 300) {
            $events->next();
            for ($remaining = $source->pendingDurableBatchSize(); $remaining > 0; --$remaining) {
                $event = $events->current();
                self::assertInstanceOf(PaperMarketEvent::class, $event);
                $tradeIds[] = $event->payload['trade_id'] ?? null;
                $source->acknowledge($event->eventId);
                if ($remaining > 1) {
                    $events->next();
                }
            }
        }
        self::assertSame(
            array_map('strval', range(10000, 10000 + OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 299)),
            $tradeIds,
        );
        self::assertSame(0, $public->pauseCount);
        self::assertContains('okx_paper_public_inbound_buffer_drained', $logger->messages());
    }

    public function testInboundBufferPausesTheSocketAboveItsBoundAndResumesHalfEmpty(): void
    {
        $logger = new OkxRecordingLogger();
        [$source, $events, $public, $publicQueue] = $this->bufferedStreamingFixture($logger, 16_384);
        for ($index = 0; $index < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 60; ++$index) {
            $public->message(Task7Transport::tradeFrame([(string) (10000 + $index)]));
        }
        // Above the bound the socket pauses, as the durable queue did.
        self::assertSame(1, $public->pauseCount);
        self::assertSame(0, $public->resumeCount);
        self::assertSame(['public' => true, 'business' => false], $logger->first('okx_paper_public_inbound_buffer_full')['paused']);

        $events->next();
        for ($remaining = $source->pendingDurableBatchSize(); $remaining > 0; --$remaining) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($remaining > 1) {
                $events->next();
            }
        }
        // The batch left the queue, the buffer refilled it and is half empty: read again.
        self::assertSame(1, $public->resumeCount);
        self::assertSame(60, $publicQueue->count());
    }

    public function testPongIsReadDuringABufferedBacklog(): void
    {
        $logger = new OkxRecordingLogger();
        [$source, , $public, $business, $deterministic] = $this->pingedBusinessFixture($logger);
        for ($index = 0; $index < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 200; ++$index) {
            $public->message(Task7Transport::bookFrame((string) (9002 + $index), (string) (9001 + $index), '1'));
            $business->message([
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [['1784970460000', '101', '102', '100', (string) (101 + $index / 1000), '11', '1', '1100', '0']],
            ]);
        }
        // The backlog waits in memory, the sockets are still read: the pong arrives.
        self::assertSame(0, $public->pauseCount + $business->pauseCount);
        $business->message('pong');
        self::assertNotContains(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS, $deterministic->timerIntervals());
        self::assertNotContains('okx_paper_public_liveness_reconnect', $logger->messages());
        self::assertSame('streaming', $this->checkpointState()['phase']);
        self::assertFalse($source->isComplete());
    }

    public function testInboundBufferHardCrashRecoversItsFramesFromRest(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the hard-crash test.');
        }
        $trade = static fn (int $tradeId): array => self::restTrade(
            (string) $tradeId,
            (string) (1784970100000 + $tradeId - 100),
        );
        $websocketFrame = static fn (int $tradeId): array => [
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [$trade($tradeId) + ['count' => '1', 'seqId' => (string) $tradeId]],
        ];
        $last = 101 + OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 100;
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Forked child: see ignoreInheritedShutdownErrorsInForkedChild().
            self::ignoreInheritedShutdownErrorsInForkedChild();
            $public = new Task7Transport();
            $business = new Task7Transport();
            $public->responses = [
                ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
                $websocketFrame(101),
            ];
            $business->responses = Task7Transport::acknowledgements(self::businessArguments(), 'business');
            $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business, anchoredTradeJunctions: true);
            $events = $source->events();
            self::assertInstanceOf(\Generator::class, $events);
            $this->acknowledgeWarmup($source, $events);
            $first = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $first);
            $source->acknowledge($first->eventId);
            // The durable queue full, 100 more frames in the inbound buffer.
            foreach (range(102, $last) as $tradeId) {
                $public->message($websocketFrame($tradeId));
            }
            posix_kill(posix_getpid(), \SIGKILL);
            exit(93);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status), 'The child must die with its inbound buffer full.');

        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->tradeRows['BTC-USDT-SWAP'] = array_map($trade, range(100, $last));
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(self::publicArguments(), 'restartPublic');
        $restartBusiness->responses = Task7Transport::acknowledgements(self::businessArguments(), 'restartBusiness');
        $restartClock = new MockClock('2026-07-25T10:00:00.000000Z');
        $restartDeterministic = new DeterministicLoop();
        $restartLoop = new Task7ScriptedLoop($restartDeterministic);
        $restartLoop->scripts = [
            static function () use ($restartClock, $restartDeterministic): void {
                $restartClock->sleep(1);
                $restartDeterministic->fireTimerInterval(1.0);
            },
        ];
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $restartClock,
            loop: $restartLoop,
            anchoredTradeJunctions: true,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $tradeIds = [];
        for ($index = 0; $index < 1000 && end($tradeIds) !== (string) $last; ++$index) {
            if ($index > 0) {
                $resumedEvents->next();
            }
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE && $event->symbol === 'BTCUSDT') {
                $tradeIds[] = $event->payload['trade_id'] ?? null;
            }
            $resumed->acknowledge($event->eventId);
        }
        // Every trade read before the crash, once, whether it was in the durable
        // queue or only in memory.
        self::assertSame(array_map('strval', range(102, $last)), $tradeIds);
        $resumed->stop();
    }

    /**
     * Streaming with durable batching (as the capture does), after one trade.
     *
     * @return array{OkxPaperPublicLiveSource, \Generator, Task7Transport, OkxPaperPublicFrameQueue}
     */
    private function bufferedStreamingFixture(
        OkxRecordingLogger $logger,
        ?int $inboundBufferMaxBytes = null,
        ?MockClock $clock = null,
    ): array {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
        ];
        $business->responses = Task7Transport::acknowledgements(self::businessArguments(), 'business');
        $publicQueue = new OkxPaperPublicFrameQueue();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            publicQueue: $publicQueue,
            loopPump: new Task7CountingLoopPump(),
            logger: $logger,
            inboundBufferMaxBytes: $inboundBufferMaxBytes,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($sentinel->eventId);

        return [$source, $events, $public, $publicQueue];
    }

    public function testFilteredFrameBatchStillPumpsAndUpdatesSocketFairness(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $pump = new Task7CountingLoopPump();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
            loopPump: $pump,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $bridgePumpCount = $pump->count;

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $businessQueue->enqueue(json_encode([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970520000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ], \JSON_THROW_ON_ERROR));
        $source->acknowledge($sentinel->eventId);

        $events->next();
        $firstBusiness = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstBusiness);
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $firstBusiness->channel);
        $source->acknowledge($firstBusiness->eventId);
        self::assertSame($bridgePumpCount + 2, $pump->count);

        $publicQueue->enqueue('pong');
        $businessQueue->enqueue(json_encode([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970580000', '101.5', '102', '101', '101.8', '12', '1', '1200', '1',
            ]],
        ], \JSON_THROW_ON_ERROR));
        $events->next();

        $secondBusiness = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $secondBusiness);
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $secondBusiness->channel);
        self::assertSame($bridgePumpCount + 3, $pump->count);
    }

    public function testDurableFrameBatchPreservesEachEventStream(): void
    {
        $eth = Task7Transport::tradeFrame(['9201']);
        $eth['arg']['instId'] = 'ETH-USDT-SWAP';
        $eth['data'][0]['instId'] = 'ETH-USDT-SWAP';
        $public = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
            Task7Transport::tradeFrame(['9101']),
            $eth,
        ];
        $business = new Task7Transport();
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($sentinel->eventId);
        $events->next();

        foreach ([['BTCUSDT', 2], ['ETHUSDT', 1]] as [$symbol, $remaining]) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame($symbol, $event->symbol);
            self::assertSame($remaining, $source->pendingDurableBatchSize());
            $source->acknowledge($event->eventId);
            if ($remaining > 1) {
                $events->next();
            }
        }

        $frontiers = $this->checkpointState()['stream_frontiers'];
        self::assertIsArray($frontiers['BTCUSDT/ws/public_trade']);
        self::assertIsArray($frontiers['ETHUSDT/ws/public_trade']);
    }

    public function testDurableFrameBatchCommitsValidPrefixBeforeLaterConflict(): void
    {
        $conflict = Task7Transport::tradeFrame(['9101']);
        $conflict['data'][0]['px'] = '999';
        $public = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
            Task7Transport::tradeFrame(['9101']),
            $conflict,
        ];
        $business = new Task7Transport();
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($sentinel->eventId);
        $events->next();

        $valid = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $valid);
        self::assertSame('9101', $valid->payload['trade_id'] ?? null);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($valid->eventId);
        self::assertSame(
            $valid->eventId,
            $this->checkpointState()['last_acknowledged_event_id'] ?? null,
        );

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('market_event_identity_conflict');
        $events->next();
    }

    public function testDurableFrameBatchCommitsValidPrefixBeforeLaterValidationFailure(): void
    {
        $public = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
        ];
        $business = new Task7Transport();
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            publicQueue: $publicQueue,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($sentinel->eventId);
        foreach ([
            Task7Transport::tradeFrame(['9101']),
            Task7Transport::bookFrame('9001', '9001', '5'),
        ] as $frame) {
            $publicQueue->enqueue(json_encode($frame, \JSON_THROW_ON_ERROR));
        }
        $events->next();

        $valid = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $valid);
        self::assertSame('9101', $valid->payload['trade_id'] ?? null);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($valid->eventId);
        self::assertSame(
            $valid->eventId,
            $this->checkpointState()['last_acknowledged_event_id'] ?? null,
        );

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('okx_paper_book_sequence_invalid');
        $events->next();
    }

    public function testDurableFrameBatchSilencesExactBookSnapshotReplay(): void
    {
        $snapshot = Task7Transport::bookFrame('9002', '-1', '5');
        $snapshot['action'] = 'snapshot';
        $snapshot['data'][0]['asks'] = [['101', '2', '0', '1']];
        $snapshot['data'][0]['bids'][] = ['100', '3', '0', '2'];
        $public = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
            $snapshot,
            $snapshot,
            Task7Transport::tradeFrame(['9300']),
        ];
        $business = new Task7Transport();
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($sentinel->eventId);
        $events->next();

        $book = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $book);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $book->channel);
        self::assertSame(2, $source->pendingDurableBatchSize());
        $source->acknowledge($book->eventId);
        $events->next();

        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $trade->channel);
        self::assertSame('9300', $trade->payload['trade_id'] ?? null);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($trade->eventId);
    }

    public function testDurableFrameBatchCapsAdvertisedRowsBeforeFiltering(): void
    {
        $public = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9000']),
            Task7Transport::tradeFrame(array_fill(0, 100, '9101')),
            Task7Transport::tradeFrame(array_map(
                static fn (int $offset): string => (string) (9200 + $offset),
                range(0, 99),
            )),
        ];
        $business = new Task7Transport();
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        $sentinel = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $sentinel);
        self::assertSame(1, $source->pendingDurableBatchSize());
        $source->acknowledge($sentinel->eventId);
        $events->next();

        $deduplicated = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $deduplicated);
        self::assertSame('9101', $deduplicated->payload['trade_id'] ?? null);
        self::assertSame(101, $source->pendingDurableBatchSize());
        $source->acknowledge($deduplicated->eventId);
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

    public function testSourceOrdinalConflictIsNormalizedAndFailsTerminally(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $conflict = Task7Transport::tradeFrame(['100']);
        $conflict['data'][0]['px'] = '100.6';
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

        try {
            $events->next();
            self::fail('The REST/WS natural identity conflict must terminate at the source boundary.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame('market_event_identity_conflict', $source->failureReason());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('opaqueUnsequencedWebSocketFramesProvider')]
    public function testTradeAndCandleStreamingNeverInferContinuityFromNumericIdentity(
        string $socket,
        array $first,
        array $second,
        PaperMarketDataChannel $channel,
    ): void {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            ...($socket === 'public' ? [$first, $second] : []),
        ];
        $business->responses = [
            ...Task7Transport::acknowledgements(self::businessArguments(), 'business'),
            ...($socket === 'business' ? [$first, $second] : []),
        ];
        $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);

        foreach ([$first, $second] as $position => $_frame) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame($channel, $event->channel);
            if ($channel === PaperMarketDataChannel::PUBLIC_TRADE) {
                self::assertSame(
                    $position === 0 ? '9500' : '12',
                    $event->payload['source_seq_id'] ?? null,
                );
            } else {
                self::assertNull($event->payload['source_seq_id'] ?? null);
            }
            $source->acknowledge($event->eventId);
            if ($position === 0) {
                $events->next();
            }
        }

        self::assertSame('streaming', $this->checkpointState()['phase']);
        self::assertSame(
            ['BTCUSDT' => null, 'ETHUSDT' => null],
            $this->checkpointState()['resync_by_symbol'],
        );
    }

    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>, PaperMarketDataChannel}> */
    public static function opaqueUnsequencedWebSocketFramesProvider(): iterable
    {
        yield 'trade ids are opaque' => [
            'public',
            Task7Transport::tradeFrame(['9500']),
            Task7Transport::tradeFrame(['12']),
            PaperMarketDataChannel::PUBLIC_TRADE,
        ];
        yield 'candle timestamps are opaque' => [
            'business',
            [
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
                ]],
            ],
            [
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    '1784970400000', '100', '101', '99', '100.5', '10', '1', '1000', '1',
                ]],
            ],
            PaperMarketDataChannel::CANDLE_1M,
        ];
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
        self::assertArrayHasKey('streaming_queue_ref', $pendingState);
        self::assertArrayNotHasKey('streaming_queues', $pendingState);
        $pendingQueues = $store->streamingQueues($store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        ));
        self::assertCount(
            1,
            $pendingQueues['public'],
            'The complete raw frame must be durable before its first row is acknowledged.',
        );

        $source->stop();
        unset($events, $source, $public, $business);
        gc_collect_cycles();

        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->tradeRows['BTC-USDT-SWAP'][] = $frame['data'][0];
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $restartClock = new MockClock('2026-07-25T10:00:00.000000Z');
        $restartDeterministic = new DeterministicLoop();
        $restartLoop = new Task7ScriptedLoop($restartDeterministic);
        $restartLoop->scripts = [
            static function () use ($restartClock, $restartDeterministic): void {
                $restartClock->sleep(1);
                $restartDeterministic->fireTimerInterval(1.0);
            },
        ];
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $restartClock,
            loop: $restartLoop,
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
        for ($index = 0; $index < 20 && \count($remaining) < 2; ++$index) {
            $resumedEvents->next();
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE
                && \in_array($event->payload['trade_id'] ?? null, ['9102', '9103'], true)
            ) {
                $remaining[] = $event->payload['trade_id'];
            }
            $resumed->acknowledge($event->eventId);
        }
        self::assertSame(['9102', '9103'], $remaining);
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $restartPublic->connections);
        self::assertSame(
            [OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI],
            $restartBusiness->connections,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::publicArguments()]],
            $restartPublic->sent,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::businessArguments()]],
            $restartBusiness->sent,
        );
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

    public function testGapStartsDurableBookResyncBeforeRestAndLeavesDeltaQueued(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9005', '9004', '5'),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $loop = new DeterministicLoop();
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            publicQueue: $publicQueue,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];

        $applied = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $applied->channel);
        self::assertSame('9002', $applied->payload['source_seq_id'] ?? null);
        $source->acknowledge($applied->eventId);

        $events->next();
        $replacement = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replacement);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $replacement->channel);
        self::assertSame('rest_resync_snapshot', $replacement->payload['origin'] ?? null);
        self::assertSame('9004', $replacement->payload['source_seq_id'] ?? null);
        self::assertSame((string) ((int) $applied->sequence + 2), $replacement->sequence);
        self::assertSame(1, $publicQueue->count());
        self::assertSame([
            20.0,
            20.0,
            OkxPaperLivePolicy::RESYNC_ATTEMPT_TIMEOUT_SECONDS,
        ], $loop->timerIntervals());
        self::assertSame(
            ['orderBook', ['BTC-USDT-SWAP', 400]],
            $rest->calls[array_key_last($rest->calls)] ?? null,
        );

        $state = $this->checkpointState();
        self::assertSame('resyncing', $state['phase']);
        self::assertSame(2, $state['source_epochs']['BTCUSDT'] ?? null);
        self::assertSame(
            [['reason' => 'sequence_gap', 'symbol' => 'BTCUSDT']],
            $state['remaining_boundaries'],
        );
        self::assertSame(['BTCUSDT'], $state['remaining_symbols']);
        self::assertSame(1, $state['resync_by_symbol']['BTCUSDT']['attempt'] ?? null);
        self::assertSame(
            $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] ?? null,
            $state['resync_by_symbol']['BTCUSDT']['frontier'] ?? null,
        );
        self::assertSame(
            '9002',
            $state['resync_by_symbol']['BTCUSDT']['source_sequence'] ?? null,
        );
        self::assertSame(
            '2026-07-25T10:15:00.000000Z',
            $state['resync_by_symbol']['BTCUSDT']['deadline_at'] ?? null,
        );
        self::assertSame(
            'book_seq_overlap_v1',
            $state['resync_by_symbol']['BTCUSDT']['policy'] ?? null,
        );
        self::assertSame(
            'BTCUSDT/rest/top_of_book',
            $state['pending_frontier']['stream'] ?? null,
        );
    }

    public function testGapResyncAcknowledgesBoundaryBeforeResumingRetainedDelta(): void
    {
        [
            'source' => $source,
            'events' => $events,
            'replacement' => $replacement,
            'loop' => $loop,
            'public_queue' => $publicQueue,
        ] = $this->sourceAtGapReplacement();

        $source->acknowledge($replacement->eventId);
        $events->next();
        $boundary = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $boundary->channel);
        self::assertSame('sequence_gap', $boundary->payload['reason'] ?? null);
        self::assertSame(2, $boundary->payload['source_epoch'] ?? null);
        self::assertSame('9004', $boundary->payload['source_seq_id'] ?? null);
        self::assertSame([20.0, 20.0], $loop->timerIntervals());
        self::assertSame(1, $publicQueue->count());

        $source->acknowledge($boundary->eventId);
        $afterBoundary = $this->checkpointState();
        self::assertSame('streaming', $afterBoundary['phase']);
        self::assertNull($afterBoundary['resync_by_symbol']['BTCUSDT']);
        self::assertSame([], $afterBoundary['remaining_symbols']);
        self::assertSame([], $afterBoundary['remaining_boundaries']);

        $events->next();
        $retained = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $retained);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $retained->channel);
        self::assertSame('ws_books', $retained->payload['origin'] ?? null);
        self::assertSame('9005', $retained->payload['source_seq_id'] ?? null);
        self::assertSame('9004', $retained->payload['source_prev_seq_id'] ?? null);
        self::assertSame(1, $publicQueue->count());
        $source->acknowledge($retained->eventId);
        self::assertSame(0, $publicQueue->count());
    }

    public function testGapResyncRejectsQueuedBooksSnapshotBeforeRestReplacement(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $queuedSnapshot = Task7Transport::bookFrame('9006', '9005', '6');
        $queuedSnapshot['action'] = 'snapshot';
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9005', '9004', '5'),
            $queuedSnapshot,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $loop = new DeterministicLoop();
        $source = $this->source(
            $rest,
            $public,
            $business,
            clock: $clock,
            loop: $loop,
            publicQueue: $publicQueue,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $applied = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        self::assertSame('9002', $applied->payload['source_seq_id'] ?? null);
        $source->acknowledge($applied->eventId);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];

        try {
            $events->next();
            self::fail('A queued books snapshot cannot prove update-chain overlap.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }

        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame(0, $publicQueue->count());
        self::assertSame([], $loop->timerIntervals());
        self::assertTrue($loop->stopped);
    }

    public function testResyncExpiresAtExactDeadlineAndIgnoresLateAttemptCallback(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9005', '9004', '5'),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $loop = new DeterministicLoop();
        $source = $this->source(
            $rest,
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $applied = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        $source->acknowledge($applied->eventId);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];
        $attempt = 0;
        $lateAttemptOne = null;
        $rest->beforeOrderBook = static function () use (
            &$attempt,
            &$lateAttemptOne,
            $clock,
            $loop,
        ): void {
            ++$attempt;
            if ($attempt === 1) {
                $timeout = OkxPaperLivePolicy::RESYNC_ATTEMPT_TIMEOUT_SECONDS;
                $lateAttemptOne = $loop->timerCallback($timeout);
                $clock->sleep($timeout);
                $loop->fireTimerInterval($timeout);

                return;
            }
            if ($attempt === 2) {
                self::assertIsCallable($lateAttemptOne);
                $lateAttemptOne();
            }
        };

        $events->next();
        $replacement = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $replacement);
        self::assertSame('rest_resync_snapshot', $replacement->payload['origin'] ?? null);
        self::assertSame(2, $attempt);
        $state = $this->checkpointState();
        self::assertSame(2, $state['resync_by_symbol']['BTCUSDT']['attempt'] ?? null);
        self::assertSame(
            '2026-07-25T10:30:00.000000Z',
            $state['resync_by_symbol']['BTCUSDT']['deadline_at'] ?? null,
        );
    }

    public function testGapResyncStopsAfterThreeUnconnectableRestSnapshots(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9005', '9004', '5'),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $businessQueue->enqueue(json_encode([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ], \JSON_THROW_ON_ERROR));
        $loop = new DeterministicLoop();
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => 9003,
        ]];
        $applied = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        $source->acknowledge($applied->eventId);

        try {
            $events->next();
            self::fail('Three snapshots without exact queued overlap must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }

        $resyncCalls = array_values(array_filter(
            $rest->calls,
            static fn (array $call): bool => $call === [
                'orderBook',
                ['BTC-USDT-SWAP', 400],
            ],
        ));
        self::assertCount(4, $resyncCalls, 'One warmup call plus exactly three resync calls.');
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame(0, $publicQueue->count());
        self::assertSame(0, $businessQueue->count());
        self::assertSame([], $loop->timerIntervals());
        self::assertTrue($loop->stopped);
        self::assertSame('market_data_gap_unresolved', $source->failureReason());
        self::assertFalse($source->isComplete());
        $state = $this->checkpointState();
        self::assertSame('failed', $state['phase']);
        self::assertSame('market_data_gap_unresolved', $state['failure_reason']);
        self::assertSame(3, $state['resync_by_symbol']['BTCUSDT']['attempt'] ?? null);
        self::assertNull($state['pending_transition']);
    }

    public function testCaptureRecordsOneVisibleGapAndLeavesManifestIncompleteOnUnresolvedResync(): void
    {
        $manifest = new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: self::DATASET_ID,
            venue: \App\Trading\Paper\MarketData\PaperMarketDataVenue::OKX,
            network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            symbols: [
                'BTCUSDT' => 'BTC-USDT-SWAP',
                'ETHUSDT' => 'ETH-USDT-SWAP',
            ],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
        $recorder = new PaperDatasetRecorder($this->testRoot . '/capture', $manifest);
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($recorder->datasetDirectory(), clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $recoverySnapshot = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];
        $unconnectable = [[
            'asks' => [['103', '2', '0', '1']],
            'bids' => [['102', '3', '0', '2']],
            'ts' => '1784970302000',
            'seqId' => '9006',
        ]];
        $rest->bookResponsePages['BTC-USDT-SWAP'] = [
            $rest->bookRows['BTC-USDT-SWAP'],
            $recoverySnapshot,
            $unconnectable,
            $unconnectable,
            $unconnectable,
        ];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9005', '9004', '5'),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $consumer = new class($public) implements PaperLiveEventConsumerInterface {
            private bool $secondGapSent = false;

            public function __construct(private readonly Task7Transport $public)
            {
            }

            public function consume(string $datasetId, PaperMarketEvent $event): void
            {
                if (!$this->secondGapSent
                    && $event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                    && ($event->payload['reason'] ?? null) === 'sequence_gap'
                ) {
                    $this->secondGapSent = true;
                    $this->public->message(Task7Transport::bookFrame('9008', '9007', '6'));
                }
            }
        };

        try {
            (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);
            self::fail('An unresolved resync must escape capture.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }

        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        self::assertSame(
            ['mainnet/okx/BTCUSDT/top_of_book' => 1],
            $recorder->manifest()->sequenceGaps,
        );
        self::assertFalse($source->isComplete());
    }

    public function testCaptureLeavesManifestIncompleteOnBusinessBackpressure(): void
    {
        $manifest = new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: self::DATASET_ID,
            venue: \App\Trading\Paper\MarketData\PaperMarketDataVenue::OKX,
            network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            symbols: [
                'BTCUSDT' => 'BTC-USDT-SWAP',
                'ETHUSDT' => 'ETH-USDT-SWAP',
            ],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
        $recorder = new PaperDatasetRecorder(
            $this->testRoot . '/capture-backpressure',
            $manifest,
        );
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore(
            $recorder->datasetDirectory(),
            clock: $clock,
        );
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static function () use ($business): void {
                for ($index = 0; $index <= OkxPaperLivePolicy::MAX_QUEUED_FRAMES; ++$index) {
                    $business->message([
                        'arg' => [
                            'channel' => 'candle1m',
                            'instId' => 'BTC-USDT-SWAP',
                        ],
                        'data' => [[
                            (string) (1784970500000 + $index * 60_000),
                            '101',
                            '102',
                            '100',
                            '101.5',
                            '11',
                            '1',
                            '1100',
                            '1',
                        ]],
                    ]);
                }
            },
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            inboundBufferMaxBytes: 16_384,
        );
        $consumer = new class implements PaperLiveEventConsumerInterface {
            public function consume(string $datasetId, PaperMarketEvent $event): void
            {
            }
        };

        try {
            (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);
            self::fail('Business backpressure must escape capture.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'market_data_backpressure_exhausted',
                $exception->getMessage(),
            );
        }

        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        self::assertSame([], $recorder->manifest()->sequenceGaps);
        self::assertSame('market_data_backpressure_exhausted', $source->failureReason());
        self::assertFalse($source->isComplete());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame([], $deterministic->timerIntervals());
        self::assertTrue($deterministic->stopped);
    }

    public function testCaptureLeavesManifestIncompleteWhenSavedReconnectBudgetExhausts(): void
    {
        $manifest = new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: self::DATASET_ID,
            venue: \App\Trading\Paper\MarketData\PaperMarketDataVenue::OKX,
            network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            symbols: [
                'BTCUSDT' => 'BTC-USDT-SWAP',
                'ETHUSDT' => 'ETH-USDT-SWAP',
            ],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
        $recorder = new PaperDatasetRecorder(
            $this->testRoot . '/capture-reconnect',
            $manifest,
        );
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore(
            $recorder->datasetDirectory(),
            clock: $clock,
        );
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $initialDeterministic = new DeterministicLoop();
        $initialLoop = new Task7ScriptedLoop($initialDeterministic);
        $initialLoop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9950'])),
        ];
        $initial = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $initialLoop,
        );
        $initialEvents = $initial->events();
        self::assertInstanceOf(\Generator::class, $initialEvents);
        $this->acknowledgeWarmup($initial, $initialEvents);
        $trade = $initialEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $initial->acknowledge($trade->eventId);
        $public->disconnect();
        $initial->stop();
        $state = json_decode(
            (string) file_get_contents(
                $recorder->datasetDirectory()
                    . '/checkpoints/okx-live/checkpoint.json',
            ),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($state);
        $state['connection_epoch'] = 7;
        $state['reconnect'] = [
            'attempt' => 6,
            'deadline_at' => '2026-07-25T10:00:30.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        file_put_contents(
            $recorder->datasetDirectory() . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        );
        unset(
            $initialEvents,
            $initial,
            $store,
            $public,
            $business,
            $initialLoop,
            $initialDeterministic,
        );
        gc_collect_cycles();

        $restartPublic = new FakeOkxPaperPublicWebSocketTransport();
        $restartBusiness = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static function () use ($clock, $deterministic): void {
                $clock->sleep(30);
                $deterministic->fireTimerInterval(30.0);
            },
            static fn () => $restartPublic->disconnect(),
        ];
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: new OkxPaperLiveCheckpointStore(
                $recorder->datasetDirectory(),
                clock: $clock,
            ),
            clock: $clock,
            loop: $loop,
        );
        $consumer = new class implements PaperLiveEventConsumerInterface {
            public function consume(string $datasetId, PaperMarketEvent $event): void
            {
            }
        };

        try {
            (new PaperLiveDatasetCapture())->run($recorder, $resumed, $consumer);
            self::fail('The exhausted saved reconnect budget must escape capture.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'okx_paper_public_reconnect_exhausted',
                $exception->getMessage(),
            );
        }

        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        self::assertSame(
            'okx_paper_public_reconnect_exhausted',
            $resumed->failureReason(),
        );
        self::assertSame(6, $state['reconnect']['attempt']);
        self::assertCount(1, $restartPublic->connections);
        self::assertCount(0, $restartBusiness->connections);
        self::assertSame([], $deterministic->timerIntervals());
        self::assertTrue($deterministic->stopped);
        self::assertFalse($resumed->isComplete());
    }

    public function testResyncRestartKeepsAttemptDeadlineAndSingleOrdinalGap(): void
    {
        $initialClock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $initialClock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $initial = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $initialClock,
        );
        $initialEvents = $initial->events();
        self::assertInstanceOf(\Generator::class, $initialEvents);
        $this->acknowledgeWarmup($initial, $initialEvents);
        $applied = $initialEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        $initial->acknowledge($applied->eventId);

        $durable = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $state = $durable->toArray();
        $frontier = $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] ?? null;
        self::assertIsArray($frontier);
        $ordinals = \App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal::restore(
            $state['ordinal_state'],
        );
        $ordinals->reserveGap('okx/BTCUSDT/top_of_book');
        $state['ordinal_state'] = $ordinals->snapshot();
        $state['remaining_symbols'] = ['BTCUSDT'];
        $state['remaining_boundaries'] = [[
            'symbol' => 'BTCUSDT',
            'reason' => 'sequence_gap',
        ]];
        $state['source_epochs']['BTCUSDT'] = 2;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => '9002',
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);
        $durable = $store->saveTransition($candidate, 'resyncing', [
            'kind' => 'timer_schedule',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/top_of_book',
            'stage' => 'resync_timeout',
        ]);
        $store->saveTransition($durable, 'resyncing', [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ]);
        unset($initialEvents, $initial);
        gc_collect_cycles();

        $restartClock = new MockClock('2026-07-25T10:00:04.000000Z');
        $restartRest = new Task7RestClient();
        $restartRest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $subscriptions = new OkxPaperPublicSubscriptionSet(new OkxPaperInstrumentMap());
        foreach (self::publicArguments() as $argument) {
            $subscriptions->acknowledgePublic($argument);
        }
        foreach (self::businessArguments() as $argument) {
            $subscriptions->acknowledgeBusiness($argument);
        }
        $publicQueue = new OkxPaperPublicFrameQueue();
        $publicQueue->enqueue(json_encode(
            Task7Transport::bookFrame('9005', '9004', '5'),
            \JSON_THROW_ON_ERROR,
        ));
        $loop = new DeterministicLoop();
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $restartClock,
            loop: $loop,
            subscriptions: $subscriptions,
            publicQueue: $publicQueue,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replacement = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replacement);
        self::assertSame('rest_resync_snapshot', $replacement->payload['origin'] ?? null);
        self::assertSame((string) ((int) $applied->sequence + 2), $replacement->sequence);
        self::assertSame([6.0], $loop->timerIntervals());
        self::assertSame(
            [['orderBook', ['BTC-USDT-SWAP', 400]]],
            $restartRest->calls,
        );
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $restartPublic->connections);
        self::assertSame(
            [OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI],
            $restartBusiness->connections,
        );
        $resumedState = $this->checkpointState();
        self::assertSame(1, $resumedState['resync_by_symbol']['BTCUSDT']['attempt'] ?? null);
        self::assertSame(
            '2026-07-25T10:00:10.000000Z',
            $resumedState['resync_by_symbol']['BTCUSDT']['deadline_at'] ?? null,
        );
    }

    public function testResyncRestartAfterSnapshotAckRestoresBookAndRetainedQueue(): void
    {
        [
            'source' => $source,
            'events' => $events,
            'replacement' => $replacement,
            'store' => $store,
        ] = $this->sourceAtGapReplacement();
        $source->acknowledge($replacement->eventId);
        unset($events, $source);
        gc_collect_cycles();

        $observedPublicConnectTransition = null;
        $public = new Task7Transport(
            beforeAction: function (string $operation) use (
                &$observedPublicConnectTransition,
            ): void {
                if ($operation === 'connect') {
                    $observedPublicConnectTransition =
                        $this->checkpointState()['pending_transition'];
                }
            },
        );
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $restartRest = new Task7RestClient();
        $resumed = $this->source(
            $restartRest,
            $public,
            $business,
            checkpointStore: $store,
            loop: new DeterministicLoop(),
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $boundary = $resumedEvents->current();

        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame('sequence_gap', $boundary->payload['reason'] ?? null);
        self::assertSame('9004', $boundary->payload['source_seq_id'] ?? null);
        self::assertSame([], $restartRest->calls);
        self::assertSame([
            'kind' => 'transport_connect',
            'stage' => 'connect',
            'stream' => 'public',
            'symbol' => null,
        ], $observedPublicConnectTransition);
        $resumed->acknowledge($boundary->eventId);
        $resumedEvents->next();
        $retained = $resumedEvents->current();

        self::assertInstanceOf(PaperMarketEvent::class, $retained);
        self::assertSame('9005', $retained->payload['source_seq_id'] ?? null);
        self::assertSame('9004', $retained->payload['source_prev_seq_id'] ?? null);
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $public->connections);
        self::assertSame([OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI], $business->connections);
    }

    /** @param array<string, mixed> $savedTransition */
    #[DataProvider('lateResyncTransportTransitionProvider')]
    public function testResyncRestartRejournalsEveryPrerequisiteAndSurvivesAnotherCrash(
        array $savedTransition,
    ): void {
        [
            'source' => $source,
            'events' => $events,
            'replacement' => $replacement,
            'store' => $store,
        ] = $this->sourceAtGapReplacement();
        $source->acknowledge($replacement->eventId);
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $transportActions = [
            [
                'kind' => 'transport_connect',
                'stage' => 'connect',
                'stream' => 'public',
                'symbol' => null,
            ],
            [
                'kind' => 'transport_connect',
                'stage' => 'connect',
                'stream' => 'business',
                'symbol' => null,
            ],
            [
                'kind' => 'subscription_send',
                'stage' => 'subscribe',
                'stream' => 'public',
                'symbol' => null,
            ],
            [
                'kind' => 'subscription_send',
                'stage' => 'subscribe',
                'stream' => 'business',
                'symbol' => null,
            ],
        ];
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        foreach ($transportActions as $transition) {
            $checkpoint = $store->saveTransition(
                $checkpoint,
                'resyncing',
                $transition,
            );
            if ($transition === $savedTransition) {
                break;
            }
        }

        $observedAtCrash = null;
        $crashingPublic = new Task7Transport(
            beforeAction: function (string $operation) use (&$observedAtCrash): void {
                if ($operation !== 'connect') {
                    return;
                }
                $observedAtCrash = $this->checkpointState()['pending_transition'];

                throw new \RuntimeException('review_resync_recrash');
            },
        );
        $crashed = $this->source(
            new Task7RestClient(),
            $crashingPublic,
            new Task7Transport(),
            checkpointStore: $store,
            loop: new DeterministicLoop(),
        );
        try {
            $crashedEvents = $crashed->events();
            self::assertInstanceOf(\Generator::class, $crashedEvents);
            $crashedEvents->current();
            self::fail('The injected crash must interrupt the first replayed effect.');
        } catch (\RuntimeException $exception) {
            self::assertSame('review_resync_recrash', $exception->getMessage());
        }
        self::assertSame($transportActions[0], $observedAtCrash);
        self::assertSame(
            $transportActions[0],
            $this->checkpointState()['pending_transition'],
        );
        unset($crashedEvents, $crashed, $crashingPublic);
        gc_collect_cycles();

        $writeAhead = [];
        $public = new Task7Transport(
            beforeAction: function (string $operation) use (&$writeAhead): void {
                $writeAhead[] = [
                    'socket' => 'public',
                    'operation' => $operation,
                    'transition' => $this->checkpointState()['pending_transition'],
                ];
            },
        );
        $business = new Task7Transport(
            beforeAction: function (string $operation) use (&$writeAhead): void {
                $writeAhead[] = [
                    'socket' => 'business',
                    'operation' => $operation,
                    'transition' => $this->checkpointState()['pending_transition'],
                ];
            },
        );
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $resumed = $this->source(
            new Task7RestClient(),
            $public,
            $business,
            checkpointStore: $store,
            loop: new DeterministicLoop(),
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $boundary = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame('sequence_gap', $boundary->payload['reason'] ?? null);
        self::assertSame([
            [
                'socket' => 'public',
                'operation' => 'connect',
                'transition' => $transportActions[0],
            ],
            [
                'socket' => 'business',
                'operation' => 'connect',
                'transition' => $transportActions[1],
            ],
            [
                'socket' => 'public',
                'operation' => 'send',
                'transition' => $transportActions[2],
            ],
            [
                'socket' => 'business',
                'operation' => 'send',
                'transition' => $transportActions[3],
            ],
        ], $writeAhead);
    }

    public function testStopDuringResumedResyncConnectAbortsTheRemainingTransportActions(): void
    {
        [
            'source' => $source,
            'events' => $events,
            'replacement' => $replacement,
            'store' => $store,
        ] = $this->sourceAtGapReplacement();
        $source->acknowledge($replacement->eventId);
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $loop = new Task7ScriptedLoop(new DeterministicLoop());
        $resumed = null;
        $loop->scripts = [
            static function () use (&$resumed): void {
                self::assertInstanceOf(OkxPaperPublicLiveSource::class, $resumed);
                $resumed->stop();
            },
        ];
        $resumed = $this->source(
            new Task7RestClient(),
            $public,
            $business,
            checkpointStore: $store,
            loop: $loop,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);

        $resumedEvents->rewind();

        self::assertFalse($resumedEvents->valid());
        self::assertCount(1, $public->connections);
        self::assertCount(0, $business->connections);
        self::assertSame([], $public->sent);
        self::assertSame([], $business->sent);
    }

    public function testResumedResyncGenerationChangeHandsOffToPairedReconnect(): void
    {
        [
            'source' => $source,
            'events' => $events,
            'replacement' => $replacement,
            'store' => $store,
        ] = $this->sourceAtGapReplacement();
        $source->acknowledge($replacement->eventId);
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $public->disconnect(),
            static fn () => $deterministic->fireTimerInterval(1.0),
            static fn () => $public->open(attempt: 1),
            static fn () => $business->open(attempt: 1),
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'reconnectedPublic',
                ) as $acknowledgement) {
                    $public->message($acknowledgement, attempt: 1);
                }
            },
            static function () use ($business): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'reconnectedBusiness',
                ) as $acknowledgement) {
                    $business->message($acknowledgement, attempt: 1);
                }
            },
        ];
        $resumed = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            loop: $loop,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);

        $event = $resumedEvents->current();

        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertCount(2, $public->connections);
        self::assertCount(2, $business->connections);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function lateResyncTransportTransitionProvider(): iterable
    {
        yield 'Public subscription head' => [[
            'kind' => 'subscription_send',
            'stage' => 'subscribe',
            'stream' => 'public',
            'symbol' => null,
        ]];
        yield 'Business connect head' => [[
            'kind' => 'transport_connect',
            'stage' => 'connect',
            'stream' => 'business',
            'symbol' => null,
        ]];
        yield 'Business subscription head' => [[
            'kind' => 'subscription_send',
            'stage' => 'subscribe',
            'stream' => 'business',
            'symbol' => null,
        ]];
    }

    public function testResyncPersistsFramesArrivingWhileSnapshotAwaitsAcknowledgement(): void
    {
        [
            'source' => $source,
            'events' => $events,
            'replacement' => $replacement,
            'store' => $store,
            'public' => $public,
        ] = $this->sourceAtGapReplacement();
        $public->message(Task7Transport::bookFrame('9006', '9005', '6'));
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertCount(
            2,
            $store->streamingQueues($checkpoint)['public'],
        );
        self::assertArrayNotHasKey(
            'queued_public_frames',
            $checkpoint->resyncBySymbol['BTCUSDT'],
        );
        $source->acknowledge($replacement->eventId);
        $source->stop();
        unset($events, $source, $public);
        gc_collect_cycles();

        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            loop: new DeterministicLoop(),
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $boundary = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame('sequence_gap', $boundary->payload['reason'] ?? null);
        $resumed->acknowledge($boundary->eventId);
        foreach (['9005', '9006'] as $position => $sequence) {
            $resumedEvents->next();
            $retained = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $retained);
            self::assertSame($sequence, $retained->payload['source_seq_id'] ?? null);
            $resumed->acknowledge($retained->eventId);
            if ($position === 0) {
                self::assertSame(
                    '9004',
                    $retained->payload['source_prev_seq_id'] ?? null,
                );
            }
        }
    }

    public function testReconnectClosePersistsPairedAttemptBeforeOneSecondDelay(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
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
            static function () use ($public): void {
                $public->message(Task7Transport::tradeFrame(['9901']));
            },
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $staleSnapshot = Task7Transport::bookFrame('9003', '-1', '5');
        $staleSnapshot['action'] = 'snapshot';
        $public->message($staleSnapshot);
        $retainedTradeFrame = Task7Transport::tradeFrame(['9902']);
        $public->message($retainedTradeFrame);
        $public->disconnect();

        self::assertSame(
            [json_encode($retainedTradeFrame, \JSON_THROW_ON_ERROR)],
            $publicQueue->frames(),
            'A new connection must retain recoverable trades but discard prior-generation books.',
        );
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame([1.0], $deterministic->timerIntervals());
        $state = $this->checkpointState();
        self::assertSame('reconnecting', $state['phase']);
        self::assertSame(2, $state['connection_epoch']);
        self::assertSame(
            [
                'accepted_events' => 0,
                'attempt' => 1,
                'deadline_at' => '2026-07-25T10:00:01.000000Z',
                'stable_since' => null,
            ],
            $state['reconnect'],
        );
        self::assertSame(
            [
                ['reason' => 'reconnect', 'symbol' => 'BTCUSDT'],
                ['reason' => 'reconnect', 'symbol' => 'ETHUSDT'],
            ],
            $state['remaining_boundaries'],
        );
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $state['remaining_symbols']);
        self::assertSame(
            [
                'kind' => 'timer_schedule',
                'stage' => 'reconnect_delay',
                'stream' => null,
                'symbol' => null,
            ],
            $state['pending_transition'],
        );

        $public->message(Task7Transport::tradeFrame(['9902']), attempt: 0);
        $business->message([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ], attempt: 0);
        $public->open(attempt: 0);
        $business->fail(new \RuntimeException('stale'), attempt: 0);
        self::assertSame(1, $publicQueue->count());
        self::assertSame(0, $businessQueue->count());
        self::assertSame($state, $this->checkpointState());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
    }

    public function testHealthyStopYieldsAckGatedStoppedEventsThenCompletesCleanup(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $loop = new DeterministicLoop();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $source->requestHealthyOperatorStop();
        $requested = $this->checkpointState();
        self::assertSame('stopping', $requested['phase']);
        self::assertSame(
            [
                'liveness_proven' => true,
                'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
                'requested' => true,
            ],
            $requested['healthy_stop'],
        );
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $requested['remaining_symbols']);

        $events->next();
        $btcStopped = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcStopped);
        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $btcStopped->channel);
        self::assertSame('BTCUSDT', $btcStopped->symbol);
        self::assertSame('stopped', $btcStopped->payload['state'] ?? null);
        $source->acknowledge($btcStopped->eventId);
        self::assertSame(
            ['ETHUSDT'],
            $this->checkpointState()['healthy_stop']['remaining_symbols'],
        );

        $events->next();
        $ethStopped = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethStopped);
        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $ethStopped->channel);
        self::assertSame('ETHUSDT', $ethStopped->symbol);
        self::assertSame('stopped', $ethStopped->payload['state'] ?? null);
        $source->acknowledge($ethStopped->eventId);
        self::assertFalse($source->isComplete());

        $events->next();
        self::assertFalse($events->valid());
        self::assertTrue($source->isComplete());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertTrue($loop->stopped);
        $complete = $this->checkpointState();
        self::assertSame('complete', $complete['phase']);
        self::assertNull($complete['pending_transition']);
        self::assertSame([], $complete['healthy_stop']['remaining_symbols']);
    }

    public function testHealthyStopDrainsFrameAdmittedInTimerIterationBeforeStopping(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
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
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $firstTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstTrade);
        $source->acknowledge($firstTrade->eventId);

        $public->message(Task7Transport::tradeFrame(['9911']));
        $source->requestHealthyOperatorStop();
        self::assertSame('streaming', $this->checkpointState()['phase']);

        $events->next();
        $queuedTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $queuedTrade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $queuedTrade->channel);
        $source->acknowledge($queuedTrade->eventId);

        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $events->next();
            $stopped = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            self::assertSame('stopped', $stopped->payload['state'] ?? null);
            $source->acknowledge($stopped->eventId);
        }
        $events->next();

        self::assertFalse($events->valid());
        self::assertTrue($source->isComplete());
        self::assertSame('complete', $this->checkpointState()['phase']);
    }

    public function testHealthyStopWaitsForPendingSocketFreshnessProof(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $clock->sleep(21);
        $source->requestHealthyOperatorStop();
        self::assertSame('streaming', $this->checkpointState()['phase']);

        $public->message('pong');
        $business->message('pong');
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $events->next();
            $stopped = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            $source->acknowledge($stopped->eventId);
        }
        $events->next();

        self::assertFalse($events->valid());
        self::assertTrue($source->isComplete());
        self::assertSame('complete', $this->checkpointState()['phase']);
    }

    public function testHealthyStopFailsIfPendingFreshnessProofTimesOut(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $loop = new DeterministicLoop();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $clock->sleep(21);
        $loop->fireTimerInterval(OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS);
        $source->requestHealthyOperatorStop();
        $clock->sleep((int) OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
        try {
            $loop->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
            self::fail('A timed-out freshness proof must invalidate healthy stop.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_healthy_stop_invalid', $exception->getMessage());
        }
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame('okx_paper_public_healthy_stop_invalid', $source->failureReason());
        self::assertFalse($source->isComplete());
    }

    public function testHealthyStopDoesNotAdmitBurstWhileAwaitingOtherSocketFreshness(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $clock->sleep(21);
        $source->requestHealthyOperatorStop();
        for ($tradeId = 10000; $tradeId < 10300; ++$tradeId) {
            $public->message(Task7Transport::tradeFrame([(string) $tradeId]));
        }
        $business->message('pong');

        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $events->next();
            $stopped = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            $source->acknowledge($stopped->eventId);
        }
        $events->next();

        self::assertFalse($events->valid());
        self::assertTrue($source->isComplete());
        self::assertSame('complete', $this->checkpointState()['phase']);
    }

    public function testHealthyStopCannotReplaceOutstandingPongWithMarketFrameFreshness(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $loop = new DeterministicLoop();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $clock->sleep(21);
        $loop->fireTimerInterval(OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS);
        $source->requestHealthyOperatorStop();
        $public->message(Task7Transport::tradeFrame(['9911']));
        $business->message('pong');

        self::assertContains(
            OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS,
            $loop->timerIntervals(),
        );
        $clock->sleep((int) OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
        try {
            $loop->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
            self::fail('Outstanding pong timeout must invalidate the requested stop.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_healthy_stop_invalid', $exception->getMessage());
        }
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertFalse($source->isComplete());
    }

    public function testHealthyStopIntentAndQueuedFrameResumeWithoutReconnect(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
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
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $firstTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstTrade);
        $source->acknowledge($firstTrade->eventId);
        $public->message(Task7Transport::tradeFrame(['9911']));

        $source->requestHealthyOperatorStop();
        $requested = $this->checkpointState();
        self::assertSame('streaming', $requested['phase']);
        self::assertSame(
            [
                'liveness_proven' => true,
                'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
                'requested' => true,
            ],
            $requested['healthy_stop'],
        );
        $source->stop();
        unset($events, $source, $store, $public, $business);
        gc_collect_cycles();

        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $restartStore,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $queuedTrade = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $queuedTrade);
        self::assertSame('9911', $queuedTrade->payload['trade_id'] ?? null);
        $resumed->acknowledge($queuedTrade->eventId);
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $resumedEvents->next();
            $stopped = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            $resumed->acknowledge($stopped->eventId);
        }
        $resumedEvents->next();

        self::assertFalse($resumedEvents->valid());
        self::assertTrue($resumed->isComplete());
        self::assertSame([], $restartPublic->connections);
        self::assertSame([], $restartBusiness->connections);
    }

    public function testUnprovenHealthyStopRestartFailsWithoutReconnect(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $loop = new DeterministicLoop();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $clock->sleep(21);
        $loop->fireTimerInterval(OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS);

        $source->requestHealthyOperatorStop();
        self::assertSame(false, $this->checkpointState()['healthy_stop']['liveness_proven']);
        $source->stop();
        unset($events, $source, $store, $public, $business);
        gc_collect_cycles();

        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $restartStore,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        try {
            $resumedEvents = $resumed->events();
            self::assertInstanceOf(\Generator::class, $resumedEvents);
            $resumedEvents->current();
            self::fail('Unproven liveness cannot become a healthy stop after restart.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_healthy_stop_invalid', $exception->getMessage());
        }
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame([], $restartPublic->connections);
        self::assertSame([], $restartBusiness->connections);
    }

    public function testHealthyStopFailsStablyWhenSocketFreshnessExpiresMidFlow(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9910']),
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
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $source->requestHealthyOperatorStop();
        $events->next();
        $btcStopped = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcStopped);
        $source->acknowledge($btcStopped->eventId);

        $clock->sleep(21);
        try {
            $events->next();
            self::fail('Expected expired freshness to invalidate healthy stop.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_healthy_stop_invalid', $exception->getMessage());
        }
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame(
            'okx_paper_public_healthy_stop_invalid',
            $source->failureReason(),
        );
        self::assertFalse($source->isComplete());
    }

    public function testHealthyStopIgnoresStaleSocketCloseAfterAdmissionIsQuiesced(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9914'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $source->requestHealthyOperatorStop();
        $events->next();
        $btcStopped = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcStopped);
        $source->acknowledge($btcStopped->eventId);

        $business->disconnect();
        self::assertSame('stopping', $this->checkpointState()['phase']);

        $events->next();
        $ethStopped = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethStopped);
        self::assertSame('ETHUSDT', $ethStopped->symbol);
        $source->acknowledge($ethStopped->eventId);
        $events->next();

        self::assertSame('complete', $this->checkpointState()['phase']);
        self::assertNull($source->failureReason());
        self::assertTrue($source->isComplete());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertTrue($deterministic->stopped);
    }

    public function testHealthyStopRevalidatesFreshnessAfterLastStoppedAcknowledgement(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9915']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $source->requestHealthyOperatorStop();
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $events->next();
            $stopped = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            $source->acknowledge($stopped->eventId);
        }

        $clock->sleep(21);
        try {
            $events->next();
            self::fail('Freshness must be revalidated after the final stopped acknowledgement.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'okx_paper_public_healthy_stop_invalid',
                $exception->getMessage(),
            );
        }
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertFalse($source->isComplete());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
    }

    public function testHealthyStopIgnoresFrameAdmissionAfterLastStoppedAcknowledgement(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9916']),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $source->requestHealthyOperatorStop();
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $events->next();
            $stopped = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            $source->acknowledge($stopped->eventId);
        }

        $public->message(Task7Transport::tradeFrame(['9917']));
        self::assertSame('stopping', $this->checkpointState()['phase']);

        $events->next();
        self::assertTrue($source->isComplete());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
    }

    public function testHealthyStopRestartReplaysRemainingStoppedEventAndCleanupHead(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9912']),
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
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $source->requestHealthyOperatorStop();
        $events->next();
        $btcStopped = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcStopped);
        $source->acknowledge($btcStopped->eventId);
        unset($events, $source);
        gc_collect_cycles();

        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartLoop = new DeterministicLoop();
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $clock,
            loop: $restartLoop,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $ethStopped = $resumedEvents->current();

        self::assertInstanceOf(PaperMarketEvent::class, $ethStopped);
        self::assertSame('ETHUSDT', $ethStopped->symbol);
        self::assertSame('stopped', $ethStopped->payload['state'] ?? null);
        $resumed->acknowledge($ethStopped->eventId);
        $resumedEvents->next();

        self::assertFalse($resumedEvents->valid());
        self::assertTrue($resumed->isComplete());
        self::assertSame(1, $restartPublic->closeCount);
        self::assertSame(1, $restartBusiness->closeCount);
        self::assertTrue($restartLoop->stopped);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function healthyStopTradeJunctionProvider(): iterable
    {
        yield 'legacy trade junction' => [false, '9913'];
        // The production junction needs a websocket trade contiguous with the
        // warmup REST frontier (100); see
        // testAnchoredJunctionFailsClosedOnANonContiguousWebsocketTrade().
        yield 'anchored trade junction' => [true, '101'];
    }

    #[DataProvider('healthyStopTradeJunctionProvider')]
    public function testHealthyStopRestartResumesSavedBusinessCloseCleanupHead(
        bool $anchoredTradeJunctions,
        string $tradeId,
    ): void {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport('business', failOn: 'close');
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame([$tradeId]),
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
            clock: $clock,
            loop: new DeterministicLoop(),
            anchoredTradeJunctions: $anchoredTradeJunctions,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        self::assertSame($tradeId, $trade->payload['trade_id'] ?? null);
        $source->acknowledge($trade->eventId);
        $source->requestHealthyOperatorStop();
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $events->next();
            $stopped = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            $source->acknowledge($stopped->eventId);
        }
        try {
            $events->next();
            self::fail('The Business close interruption must leave its write-ahead cleanup head.');
        } catch (\RuntimeException $exception) {
            self::assertSame('task7_transport_interrupt', $exception->getMessage());
        }
        self::assertSame(
            [
                'kind' => 'transport_close',
                'stage' => 'close',
                'stream' => 'business',
                'symbol' => null,
            ],
            $this->checkpointState()['pending_transition'],
        );
        unset($events, $source);
        gc_collect_cycles();

        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartLoop = new DeterministicLoop();
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $clock,
            loop: $restartLoop,
            anchoredTradeJunctions: $anchoredTradeJunctions,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        iterator_to_array($resumedEvents);

        self::assertTrue($resumed->isComplete());
        self::assertSame(0, $restartPublic->closeCount);
        self::assertSame(1, $restartBusiness->closeCount);
        self::assertTrue($restartLoop->stopped);
    }

    public function testStopIsIdempotentAndAlwaysLeavesCheckpointIncomplete(): void
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $loop = new DeterministicLoop();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            loop: $loop,
        );

        $source->stop();
        $source->stop();

        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertTrue($loop->stopped);
        self::assertFalse($source->isComplete());
        self::assertSame('warming', $this->checkpointState()['phase']);
    }

    public function testStopCancelsAnActiveReconnectTimerAndRejectsItsStaleCallback(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9911'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);
        $public->disconnect();
        $staleReconnect = $deterministic->timerCallback(1.0);

        $source->stop();
        $source->stop();
        self::assertSame([], $deterministic->timerIntervals());
        $connections = [$public->connections, $business->connections];

        $staleReconnect();

        self::assertSame($connections, [$public->connections, $business->connections]);
        self::assertSame(2, $public->closeCount);
        self::assertSame(2, $business->closeCount);
        self::assertFalse($source->isComplete());
    }

    #[DataProvider('backpressureProvider')]
    public function testBackpressureLimitFailsAndClearsBothSocketQueues(
        string $limit,
        string $socket,
    ): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9920'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
            // Beyond the durable queue, frames wait in the inbound buffer: its hard
            // limit (twice its bound) is what fails closed now.
            inboundBufferMaxBytes: 16_384,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $business->message([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ]);

        $target = $socket === 'public' ? $public : $business;
        try {
            if ($limit === 'frames') {
                for ($index = 0; $index <= OkxPaperLivePolicy::MAX_QUEUED_FRAMES; ++$index) {
                    $target->message($socket === 'public'
                        ? Task7Transport::tradeFrame([(string) (10000 + $index)])
                        : [
                            'arg' => [
                                'channel' => 'candle1m',
                                'instId' => 'BTC-USDT-SWAP',
                            ],
                            'data' => [[
                                (string) (1784970500000 + $index * 60_000),
                                '101',
                                '102',
                                '100',
                                '101.5',
                                '11',
                                '1',
                                '1100',
                                '1',
                            ]],
                        ]);
                }
            } else {
                foreach ([450_000, 450_000, 200_000] as $index => $quotes) {
                    if ($socket === 'public') {
                        $frame = Task7Transport::tradeFrame([(string) (9800 + $index)]);
                        $frame['data'][0]['px'] = str_repeat('"', $quotes);
                    } else {
                        $frame = [
                            'arg' => [
                                'channel' => 'candle1m',
                                'instId' => 'BTC-USDT-SWAP',
                            ],
                            'data' => [[
                                (string) (1784970500000 + $index * 60_000),
                                str_repeat('"', $quotes),
                                '102',
                                '100',
                                '101.5',
                                '11',
                                '1',
                                '1100',
                                '1',
                            ]],
                        ];
                    }
                    $target->message($frame);
                }
            }
            self::fail('Either bounded socket queue must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_backpressure_exhausted', $exception->getMessage());
        }

        self::assertSame('market_data_backpressure_exhausted', $source->failureReason());
        self::assertSame(0, $publicQueue->count());
        self::assertSame(0, $businessQueue->count());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertFalse($source->isComplete());
    }

    /** @return iterable<string, array{string, string}> */
    public static function backpressureProvider(): iterable
    {
        yield 'Public frame above bound' => ['frames', 'public'];
        yield 'Public aggregate bytes above 2 MiB' => ['bytes', 'public'];
        yield 'Business frame above bound' => ['frames', 'business'];
        yield 'Business aggregate bytes above 2 MiB' => ['bytes', 'business'];
    }

    public function testHeartbeatPongRefreshesOnlyPublicAndMissingBusinessPongReconnectsPair(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $business->message([
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [[
                    '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
                ]],
            ]),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $candle = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $candle);
        $source->acknowledge($candle->eventId);
        self::assertSame([20.0, 20.0], $deterministic->timerIntervals());

        $clock->sleep(20);
        $deterministic->fireNextTimer();
        self::assertSame(['op' => 'ping'], $public->sent[array_key_last($public->sent)]);
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::businessArguments()]],
            $business->sent,
        );
        self::assertSame([20.0, 10.0], $deterministic->timerIntervals());

        $public->message('pong');
        self::assertSame([20.0, 20.0], $deterministic->timerIntervals());
        self::assertNull($this->checkpointState()['pending_event']);
        self::assertSame('streaming', $this->checkpointState()['phase']);

        $deterministic->fireNextTimer();
        self::assertSame(['op' => 'ping'], $business->sent[array_key_last($business->sent)]);
        self::assertSame([20.0, 10.0], $deterministic->timerIntervals());
        $clock->sleep(10);
        $deterministic->fireTimerInterval(10.0);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame([1.0], $deterministic->timerIntervals());
    }

    public function testHeartbeatDoesNotPingASocketWhileItsDurableInboundQueueIsBacklogged(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9914'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $pendingPublic = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pendingPublic);

        $business->message([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ]);
        self::assertSame(1, $publicQueue->count());
        self::assertSame(1, $businessQueue->count());

        $clock->sleep(20);
        self::assertCount(2, $deterministic->timers);
        $deterministic->fireNextTimer();
        $deterministic->fireNextTimer();

        self::assertSame(
            [['op' => 'subscribe', 'args' => self::publicArguments()]],
            $public->sent,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::businessArguments()]],
            $business->sent,
        );
        self::assertSame([20.0, 20.0], $deterministic->timerIntervals());
        self::assertSame('streaming', $this->checkpointState()['phase']);

        $source->acknowledge($pendingPublic->eventId);
        self::assertSame(0, $publicQueue->count());
        self::assertSame(1, $businessQueue->count());
        $drainedIntervals = $deterministic->timerIntervals();
        sort($drainedIntervals);
        self::assertSame([0.0, 20.0], $drainedIntervals);
    }

    public function testValidNonPongFrameRefreshesOnlyItsRoutedSocket(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9915'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $heartbeatCallbacks = array_column($deterministic->timers, 1);
        self::assertCount(2, $heartbeatCallbacks);

        $clock->sleep(19);
        $public->message(Task7Transport::tradeFrame(['9916']));
        $clock->sleep(1);
        foreach ($heartbeatCallbacks as $heartbeatCallback) {
            $heartbeatCallback();
        }

        self::assertCount(1, $public->sent);
        self::assertSame(['op' => 'ping'], $business->sent[array_key_last($business->sent)]);
        self::assertSame('streaming', $this->checkpointState()['phase']);
    }

    public function testValidDataAfterPingDoesNotSatisfyOutstandingPong(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9918'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $clock->sleep(20);
        $deterministic->fireNextTimer();
        self::assertSame(['op' => 'ping'], $business->sent[array_key_last($business->sent)]);
        self::assertSame([20.0, 10.0], $deterministic->timerIntervals());
        $business->message([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ]);

        $clock->sleep(10);
        $deterministic->fireTimerInterval(10.0);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame([1.0], $deterministic->timerIntervals());
    }

    public function testHeartbeatReconnectIsLoggedWithItsLivenessProbe(): void
    {
        $logger = new OkxRecordingLogger();
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9918'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
            logger: $logger,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        // The event loop was blocked: the 20 s heartbeat runs 130 s late.
        $clock->sleep(150);
        $deterministic->fireNextTimer();
        self::assertSame(['op' => 'ping'], $business->sent[array_key_last($business->sent)]);
        self::assertSame([20.0, 10.0], $deterministic->timerIntervals());
        $business->message([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ]);

        $clock->sleep(10);
        $deterministic->fireTimerInterval(10.0);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame([1.0], $deterministic->timerIntervals());
        // Data read after the ping, pong still missing: a live connection whose
        // pong waits behind a backlog.
        $probe = $logger->first('okx_paper_public_liveness_reconnect');
        self::assertSame('business', $probe['socket']);
        self::assertSame('pong_timeout', $probe['trigger']);
        // The candle came right after the ping, then 10 s of silence: dead.
        self::assertSame('reconnect', $probe['decision']);
        self::assertSame(1, $probe['frames_since_ping']);
        self::assertSame(10.0, $probe['ping_age_s']);
        self::assertSame(10.0, $probe['last_frame_age_s']);
        self::assertNull($probe['last_pong_age_s']);
        self::assertSame(130.0, $probe['loop_stall_s']);
        // The candle read after the ping is still queued.
        self::assertSame(1, $probe['business_queue_frames']);
    }

    public function testPongBehindABacklogIsAwaitedWhileFramesProveTheConnectionAlive(): void
    {
        // Production (run7, burst at ~200 events/s): both queues saturated, 256
        // frames read on the pinged socket since the ping, the pong 180 s late
        // behind the backlog.
        $logger = new OkxRecordingLogger();
        [$source, $events, $public, $business, $deterministic, $clock] = $this->pingedBusinessFixture($logger);
        $candle = 0;
        $candles = static function (int $count) use ($business, &$candle): void {
            for ($index = 0; $index < $count; ++$index) {
                ++$candle;
                $business->message([
                    'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                    'data' => [[
                        '1784970460000', '101', '102', '100', (string) (100 + $candle / 1000), '11', '1', '1100', '0',
                    ]],
                ]);
            }
        };
        $candles(256);
        for ($offset = 1; $offset <= 200; ++$offset) {
            $public->message(Task7Transport::bookFrame((string) (9001 + $offset), (string) (9000 + $offset), (string) $offset));
        }
        // The sockets are still read: the backlog waits in the inbound buffer.
        self::assertSame(0, $business->pauseCount + $public->pauseCount);
        for ($elapsed = 10; $elapsed <= 170; $elapsed += 10) {
            $clock->sleep(8);
            $candles(2);
            $clock->sleep(2);
            $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
            self::assertSame('streaming', $this->checkpointState()['phase'], $elapsed . ' s');
            self::assertSame(0, $public->closeCount + $business->closeCount);
        }
        $clock->sleep(10);
        $business->message('pong');
        self::assertNotContains(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS, $deterministic->timerIntervals());

        // No reconnect: the backlog drains (all 200 book deltas, chain intact).
        for ($offset = 1; $offset <= 200; ++$offset) {
            $events->next();
            $book = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $book);
            self::assertSame((string) (9001 + $offset), $book->payload['source_seq_id'] ?? null);
            $source->acknowledge($book->eventId);
        }
        self::assertSame('streaming', $this->checkpointState()['phase']);
        self::assertSame(0, $public->closeCount + $business->closeCount);
        $deferrals = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => $record['message'] === 'okx_paper_public_liveness_reconnect',
        ));
        // Logged when first deferred, then once a minute.
        self::assertSame(['deferred_alive', 'deferred_alive', 'deferred_alive'], array_map(
            static fn (array $record): string => $record['context']['decision'],
            $deferrals,
        ));
        self::assertTrue($deferrals[0]['context']['backlog']);
        // The 256 backlog frames and the 2 read since.
        self::assertSame(258, $deferrals[0]['context']['frames_since_ping']);
        self::assertSame(10.0, $deferrals[0]['context']['ping_age_s']);
    }

    public function testSilentSocketStillReconnectsAfterThePongTimeout(): void
    {
        $logger = new OkxRecordingLogger();
        [, , $public, $business, $deterministic, $clock] = $this->pingedBusinessFixture($logger);

        $clock->sleep(10);
        $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);

        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        $probe = $logger->first('okx_paper_public_liveness_reconnect');
        self::assertSame('reconnect', $probe['decision']);
        self::assertSame(0, $probe['frames_since_ping']);
    }

    public function testPongMissingWithoutBacklogReconnectsAtTheCap(): void
    {
        // Frames keep arriving but nothing is backlogged: a pong missing for a
        // minute means our requests no longer reach OKX.
        $logger = new OkxRecordingLogger();
        [, , , $business, $deterministic, $clock] = $this->pingedBusinessFixture($logger);
        $cap = (int) OkxPaperLivePolicy::LIVENESS_PONG_CAP_SECONDS;
        for ($elapsed = 10; $elapsed <= $cap; $elapsed += 10) {
            $clock->sleep(5);
            $business->message([
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [['1784970460000', '101', '102', '100', (string) (101 + $elapsed / 100), '11', '1', '1100', '0']],
            ]);
            $clock->sleep(5);
            $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
            self::assertSame(
                $elapsed < $cap ? 'streaming' : 'reconnecting',
                $this->checkpointState()['phase'],
                $elapsed . ' s',
            );
        }
        $decisions = array_map(
            static fn (array $record): string => $record['context']['decision'],
            array_values(array_filter(
                $logger->records,
                static fn (array $record): bool => $record['message'] === 'okx_paper_public_liveness_reconnect',
            )),
        );
        self::assertSame(['deferred_alive', 'pong_cap'], $decisions);
    }

    public function testBusinessFramesAreReadWhileThePublicBacklogWaitsInMemory(): void
    {
        // Production (run8, 14:33): the public durable queue full, the public
        // backlog in memory, the business queue empty. Business frames must keep
        // being read (no paired pause): they prove the socket alive.
        $logger = new OkxRecordingLogger();
        [, , $public, $business, $deterministic, $clock] = $this->pingedBusinessFixture($logger);
        for ($offset = 1; $offset <= OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 300; ++$offset) {
            $public->message(Task7Transport::bookFrame((string) (9001 + $offset), (string) (9000 + $offset), (string) $offset));
        }
        for ($second = 1; $second <= 30; ++$second) {
            $clock->sleep(1);
            $business->message([
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [['1784970460000', '101', '102', '100', (string) (101 + $second / 100), '11', '1', '1100', '0']],
            ]);
            if ($second % 10 === 0) {
                $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
            }
            self::assertSame('streaming', $this->checkpointState()['phase'], $second . ' s');
        }
        self::assertSame(0, $public->pauseCount + $business->pauseCount);
        self::assertSame(0, $public->closeCount + $business->closeCount);
        $probe = $logger->first('okx_paper_public_liveness_reconnect');
        self::assertSame('deferred_alive', $probe['decision']);
        self::assertSame(10, $probe['frames_since_ping']);
        self::assertFalse($probe['paused_by_us']);
        self::assertSame(OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES, $probe['public_queue_frames']);
        self::assertSame(300, $probe['inbound_buffered_frames']);
        $business->message('pong');
        self::assertNotContains(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS, $deterministic->timerIntervals());
    }

    public function testMuteBusinessSocketStillReconnectsDuringAPublicBacklog(): void
    {
        // Read, but nothing arrives (not even the pong) for PONG_TIMEOUT_SECONDS:
        // the business connection is dead, whatever the public backlog.
        $logger = new OkxRecordingLogger();
        [, , $public, $business, $deterministic, $clock] = $this->pingedBusinessFixture($logger);
        for ($offset = 1; $offset <= OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES + 300; ++$offset) {
            $public->message(Task7Transport::bookFrame((string) (9001 + $offset), (string) (9000 + $offset), (string) $offset));
        }
        $clock->sleep(10);
        $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);

        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertSame(1, $business->closeCount);
        $probe = $logger->first('okx_paper_public_liveness_reconnect');
        self::assertSame('reconnect', $probe['decision']);
        self::assertTrue($probe['backlog']);
        self::assertFalse($probe['paused_by_us']);
        self::assertSame(0, $probe['frames_since_ping']);
    }

    public function testSocketWeStoppedReadingIsNeverDeclaredDead(): void
    {
        // The business backlog fills the inbound buffer: the business socket is
        // paused, so OKX's frames and the pong wait in it. No reconnect, no cap.
        $logger = new OkxRecordingLogger();
        [, , , $business, $deterministic, $clock] = $this->pingedBusinessFixture($logger, 16_384);
        for ($index = 0; $business->pauseCount === 0 && $index < 1_000; ++$index) {
            $business->message([
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [['1784970460000', '101', '102', '100', (string) (101 + $index / 1000), '11', '1', '1100', '0']],
            ]);
        }
        self::assertSame(1, $business->pauseCount);
        $clock->sleep(60);
        for ($elapsed = 10; $elapsed <= 120; $elapsed += 10) {
            $clock->sleep(10);
            $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
            self::assertSame('streaming', $this->checkpointState()['phase'], $elapsed . ' s');
        }
        self::assertSame(0, $business->closeCount);
        $probe = $logger->first('okx_paper_public_liveness_reconnect');
        self::assertSame('deferred_paused', $probe['decision']);
        self::assertTrue($probe['paused_by_us']);
    }

    public function testSilenceCountsOnlyWhileTheEventLoopPollsTheSockets(): void
    {
        // A 9.5 s gap between two polls (a long batch): the sockets were not read,
        // the pong may be waiting. The silence counts again from the next poll.
        $logger = new OkxRecordingLogger();
        [$source, , $public, $business, $deterministic, $clock] = $this->pingedBusinessFixture($logger);
        $poll = new \ReflectionMethod($source, 'pumpNetworkLoop');
        $poll->invoke($source);
        $clock->sleep(9.5);
        $poll->invoke($source);
        $clock->sleep(0.5);
        $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
        self::assertSame('streaming', $this->checkpointState()['phase']);
        $deferral = $logger->first('okx_paper_public_liveness_reconnect');
        self::assertSame('deferred_paused', $deferral['decision']);
        self::assertSame(2, $deferral['polls_since_ping']);
        self::assertGreaterThanOrEqual(9.5, $deferral['max_poll_gap_s']);
        self::assertSame(0.5, $deferral['read_active_s']);

        // Read for PONG_TIMEOUT_SECONDS and still nothing: dead.
        $clock->sleep(10);
        $deterministic->fireTimerInterval(OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
    }

    /**
     * Streaming, then 20 s without business frames: the heartbeat pings the
     * business socket.
     *
     * @return array{OkxPaperPublicLiveSource, \Generator, FakeOkxPaperPublicWebSocketTransport, FakeOkxPaperPublicWebSocketTransport, DeterministicLoop, MockClock}
     */
    private function pingedBusinessFixture(OkxRecordingLogger $logger, ?int $inboundBufferMaxBytes = null): array
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(self::publicArguments(), 'public') as $acknowledgement) {
                    $public->message($acknowledgement);
                }
            },
            static function () use ($business): void {
                foreach (Task7Transport::acknowledgements(self::businessArguments(), 'business') as $acknowledgement) {
                    $business->message($acknowledgement);
                }
            },
            static fn () => $public->message(Task7Transport::tradeFrame(['9918'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
            logger: $logger,
            inboundBufferMaxBytes: $inboundBufferMaxBytes,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $clock->sleep(20);
        $deterministic->fireNextTimer();
        self::assertSame(['op' => 'ping'], $business->sent[array_key_last($business->sent)]);

        return [$source, $events, $public, $business, $deterministic, $clock];
    }

    #[DataProvider('invalidFreshnessFrameProvider')]
    public function testMalformedFrameCannotRefreshOneSocketsInboundFreshness(
        string $invalidFrame,
    ): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9931'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);
        $heartbeatCallbacks = array_column($deterministic->timers, 1);

        $clock->sleep(19);
        try {
            $public->message($invalidFrame);
            self::fail('Invalid input must terminalize before returning from its callback.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_message_invalid', $exception->getMessage());
        }

        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame('okx_paper_public_message_invalid', $source->failureReason());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame([], $deterministic->timerIntervals());
        self::assertNotEmpty($heartbeatCallbacks);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFreshnessFrameProvider(): iterable
    {
        yield 'malformed json' => ['{'];
        yield 'valid channel on wrong socket' => [json_encode([
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ], \JSON_THROW_ON_ERROR)];
    }

    public function testGapOverlapRetainsConnectingRowAfterObsoleteRowInSameFrame(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $multiRow = Task7Transport::bookFrame('9005', '9004', '5');
        $multiRow['data'] = [
            Task7Transport::bookFrame('9002', '9001', '4')['data'][0],
            $multiRow['data'][0],
        ];
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            $multiRow,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source($rest, $public, $business, loop: new DeterministicLoop());
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $applied = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        $source->acknowledge($applied->eventId);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];

        $events->next();
        $replacement = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $replacement);
        self::assertSame('9004', $replacement->payload['source_seq_id'] ?? null);
        self::assertSame(
            2,
            \count(array_filter(
                $rest->calls,
                static fn (array $call): bool => $call[0] === 'orderBook'
                    && $call[1] === ['BTC-USDT-SWAP', 400],
            )),
        );
    }

    public function testReconnectTimerStartsAsynchronousPairWithoutReenteringLoop(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9902'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $public->disconnect();

        $loop->invokeWhileRunning(static function () use ($clock, $deterministic): void {
            $clock->sleep(1);
            $deterministic->fireTimerInterval(1.0);
        });

        self::assertCount(2, $public->connections);
        self::assertCount(1, $business->connections);
        self::assertSame([], $deterministic->timerIntervals());
        $public->open(attempt: 1);
        self::assertCount(1, $public->sent);
        self::assertCount(2, $business->connections);
        $business->open(attempt: 1);
        self::assertCount(2, $public->sent);
        self::assertCount(2, $business->sent);
        self::assertSame(
            [
                'kind' => 'subscription_send',
                'stage' => 'subscribe',
                'stream' => 'business',
                'symbol' => null,
            ],
            $this->checkpointState()['pending_transition'],
        );
    }

    #[DataProvider('postOpenRetryFailureProvider')]
    public function testPostOpenRetryFailureClosesPairAndSchedulesExactNextAttempt(
        string $socket,
        string $callback,
        bool $acknowledgePair,
    ): void {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9941'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $public->disconnect();
        $clock->sleep(1);
        $deterministic->fireTimerInterval(1.0);
        $public->open(attempt: 1);
        if ($socket === 'business') {
            $business->open(attempt: 1);
        }
        if ($acknowledgePair) {
            foreach (Task7Transport::acknowledgements(
                self::publicArguments(),
                'publicRetry',
            ) as $acknowledgement) {
                $public->message($acknowledgement, attempt: 1);
            }
            foreach (Task7Transport::acknowledgements(
                self::businessArguments(),
                'businessRetry',
            ) as $acknowledgement) {
                $business->message($acknowledgement, attempt: 1);
            }
        }

        $transport = $socket === 'public' ? $public : $business;
        if ($callback === 'close') {
            $transport->disconnect(attempt: 1);
        } else {
            $transport->fail(new \RuntimeException('retryable_transport_error'), attempt: 1);
        }

        self::assertSame(2, $public->closeCount);
        self::assertSame(2, $business->closeCount);
        self::assertSame([2.0], $deterministic->timerIntervals());
        $state = $this->checkpointState();
        self::assertSame('reconnecting', $state['phase']);
        self::assertSame(2, $state['reconnect']['attempt']);
        self::assertSame('2026-07-25T10:00:03.000000Z', $state['reconnect']['deadline_at']);
        self::assertSame(3, $state['connection_epoch']);
        self::assertSame([
            'kind' => 'timer_schedule',
            'stage' => 'reconnect_delay',
            'stream' => null,
            'symbol' => null,
        ], $state['pending_transition']);
        self::assertNull($source->failureReason());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function postOpenRetryFailureProvider(): iterable
    {
        yield 'Public close after Public open and before Business open' => [
            'public',
            'close',
            false,
        ];
        yield 'Business error after both subscription subsets are ready' => [
            'business',
            'error',
            true,
        ];
    }

    #[DataProvider('postOpenTerminalTransportErrorProvider')]
    public function testPostOpenOversizeTransportErrorFailsInsteadOfRetrying(
        string $socket,
    ): void {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9942'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        $public->disconnect();
        $clock->sleep(1);
        $deterministic->fireTimerInterval(1.0);
        $public->open(attempt: 1);
        if ($socket === 'business') {
            $business->open(attempt: 1);
        }
        $transport = $socket === 'public' ? $public : $business;

        try {
            $transport->fail(
                new OkxPaperLiveIntegrityException(
                    'okx_paper_public_ws_frame_too_large',
                ),
                attempt: 1,
            );
            self::fail('An oversize transport error must be terminal after onOpen.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'okx_paper_public_ws_frame_too_large',
                $exception->getMessage(),
            );
        }

        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame(
            'okx_paper_public_ws_frame_too_large',
            $source->failureReason(),
        );
        self::assertSame(2, $public->closeCount);
        self::assertSame(2, $business->closeCount);
        self::assertSame([], $deterministic->timerIntervals());
        self::assertTrue($deterministic->stopped);
    }

    /** @return iterable<string, array{string}> */
    public static function postOpenTerminalTransportErrorProvider(): iterable
    {
        yield 'Public oversize error after open' => ['public'];
        yield 'Business oversize error after open' => ['business'];
    }

    public function testGapOverlapRejectsDisconnectedLaterRowBeforeSnapshotEmission(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $disconnected = Task7Transport::bookFrame('9005', '9004', '5');
        $disconnected['data'][] =
            Task7Transport::bookFrame('9007', '9006', '6')['data'][0];
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            $disconnected,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $applied = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        $source->acknowledge($applied->eventId);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];

        try {
            $events->next();
            self::fail('Every retained target-book row must form one verified chain.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }

        self::assertCount(
            4,
            array_filter(
                $rest->calls,
                static fn (array $call): bool => $call === [
                    'orderBook',
                    ['BTC-USDT-SWAP', 400],
                ],
            ),
        );
        self::assertSame('failed', $this->checkpointState()['phase']);
    }

    public function testReconnectUsesBoundedDelaysAndAttemptSevenFailsTerminally(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9940'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $public->disconnect();

        $observed = [];
        try {
            foreach ([1.0, 2.0, 4.0, 8.0, 15.0, 30.0] as $attempt => $delay) {
                self::assertSame([$delay], $deterministic->timerIntervals());
                $observed[] = $delay;
                $clock->sleep($delay);
                $deterministic->fireTimerInterval($delay);
                $public->disconnect(attempt: $attempt + 1);
            }
            self::fail('The failure after the sixth retry budget must terminate.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'okx_paper_public_reconnect_exhausted',
                $exception->getMessage(),
            );
        }

        self::assertSame([1.0, 2.0, 4.0, 8.0, 15.0, 30.0], $observed);
        self::assertSame([], $deterministic->timerIntervals());
        self::assertSame('okx_paper_public_reconnect_exhausted', $source->failureReason());
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame(6, $this->checkpointState()['reconnect']['attempt']);
        self::assertCount(7, $public->connections);
        self::assertCount(1, $business->connections);
        self::assertSame(7, $public->closeCount);
        self::assertSame(7, $business->closeCount);
        self::assertFalse($source->isComplete());
    }

    public function testReconnectRestartSchedulesOnlySavedDeadlineRemainder(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9950'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);
        $public->disconnect();
        $beforeRestart = $this->checkpointState();
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $restartClock = new MockClock('2026-07-25T10:00:00.250000Z');
        $restartLoop = new DeterministicLoop();
        $restartPublic = new FakeOkxPaperPublicWebSocketTransport();
        $restartBusiness = new FakeOkxPaperPublicWebSocketTransport();
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $restartClock,
            loop: $restartLoop,
        );

        self::assertSame([0.75], $restartLoop->timerIntervals());
        self::assertSame([], $restartPublic->connections);
        self::assertSame([], $restartBusiness->connections);
        self::assertSame($beforeRestart['reconnect'], $this->checkpointState()['reconnect']);
        self::assertSame(
            $beforeRestart['connection_epoch'],
            $this->checkpointState()['connection_epoch'],
        );
        self::assertFalse($resumed->isComplete());
    }

    /** @param array<string, mixed> $savedTransition */
    #[DataProvider('reconnectTransportRestartTransitionProvider')]
    public function testReconnectRestartExecutesTheSavedTransportTransitionThenExactPair(
        array $savedTransition,
    ): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static fn () => $public->message(Task7Transport::tradeFrame(['9951'])),
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);
        $public->disconnect();
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        foreach ([
            [
                'kind' => 'timer_cancel',
                'symbol' => null,
                'stream' => null,
                'stage' => 'cancel_reconnect_timer',
            ],
            [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'connect',
            ],
            [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'connect',
            ],
            [
                'kind' => 'subscription_send',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'subscribe',
            ],
            [
                'kind' => 'subscription_send',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'subscribe',
            ],
        ] as $transition) {
            $checkpoint = $store->saveTransition(
                $checkpoint,
                'reconnecting',
                $transition,
            );
            if ($transition === $savedTransition) {
                break;
            }
        }
        $staleBook = Task7Transport::bookFrame('9999', '-1', '5');
        $staleBook['action'] = 'snapshot';
        $retainedTrade = Task7Transport::tradeFrame(['9952']);
        $checkpoint = $store->saveStreamingQueues(
            $checkpoint,
            [
                json_encode($staleBook, \JSON_THROW_ON_ERROR),
                json_encode($retainedTrade, \JSON_THROW_ON_ERROR),
            ],
            [],
        );
        self::assertEquals($savedTransition, $this->checkpointState()['pending_transition']);

        $writeAhead = [];
        $restartPublic = new Task7Transport(
            beforeAction: function (string $operation) use (&$writeAhead): void {
                $writeAhead[] = [
                    'socket' => 'public',
                    'operation' => $operation,
                    'transition' => $this->checkpointState()['pending_transition'],
                ];
            },
        );
        $restartBusiness = new Task7Transport(
            beforeAction: function (string $operation) use (&$writeAhead): void {
                $writeAhead[] = [
                    'socket' => 'business',
                    'operation' => $operation,
                    'transition' => $this->checkpointState()['pending_transition'],
                ];
            },
        );
        $restartPublic->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $restartPublicQueue = new OkxPaperPublicFrameQueue();
        $resumed = $this->source(
            Task7RestClient::withInitialDataset(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $clock,
            loop: new DeterministicLoop(),
            publicQueue: $restartPublicQueue,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $reconnecting = $resumedEvents->current();

        self::assertInstanceOf(PaperMarketEvent::class, $reconnecting);
        self::assertSame('reconnecting', $reconnecting->payload['state'] ?? null);
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $restartPublic->connections);
        self::assertSame(
            [OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI],
            $restartBusiness->connections,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::publicArguments()]],
            $restartPublic->sent,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::businessArguments()]],
            $restartBusiness->sent,
        );
        self::assertSame([
            [
                'socket' => 'public',
                'operation' => 'connect',
                'transition' => [
                    'kind' => 'transport_connect',
                    'stage' => 'connect',
                    'stream' => 'public',
                    'symbol' => null,
                ],
            ],
            [
                'socket' => 'business',
                'operation' => 'connect',
                'transition' => [
                    'kind' => 'transport_connect',
                    'stage' => 'connect',
                    'stream' => 'business',
                    'symbol' => null,
                ],
            ],
            [
                'socket' => 'public',
                'operation' => 'send',
                'transition' => [
                    'kind' => 'subscription_send',
                    'stage' => 'subscribe',
                    'stream' => 'public',
                    'symbol' => null,
                ],
            ],
            [
                'socket' => 'business',
                'operation' => 'send',
                'transition' => [
                    'kind' => 'subscription_send',
                    'stage' => 'subscribe',
                    'stream' => 'business',
                    'symbol' => null,
                ],
            ],
        ], $writeAhead);
        self::assertSame(1, $this->checkpointState()['reconnect']['attempt']);
        self::assertSame(
            [json_encode($retainedTrade, \JSON_THROW_ON_ERROR)],
            $restartPublicQueue->frames(),
            'Crash-resume socket rebuilding must purge prior-generation book frames.',
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function reconnectTransportRestartTransitionProvider(): iterable
    {
        yield 'timer cancellation' => [[
            'kind' => 'timer_cancel',
            'symbol' => null,
            'stream' => null,
            'stage' => 'cancel_reconnect_timer',
        ]];
        yield 'Public connect' => [[
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'connect',
        ]];
        yield 'Business connect' => [[
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'connect',
        ]];
        yield 'Public subscription' => [[
            'kind' => 'subscription_send',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'subscribe',
        ]];
        yield 'Business subscription' => [[
            'kind' => 'subscription_send',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'subscribe',
        ]];
    }

    public function testReconnectRestartResumesSavedPairedCloseBeforeSameAttemptDelay(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9952']),
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
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $store->saveTransition($checkpoint, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ]);
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $restartPublic = new FakeOkxPaperPublicWebSocketTransport();
        $restartBusiness = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $loop->scripts = [
            static function () use ($clock, $deterministic): void {
                $clock->sleep(1);
                $deterministic->fireTimerInterval(1.0);
            },
            static fn () => $restartPublic->open(),
            static fn () => $restartBusiness->open(),
            static function () use ($restartPublic): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'restartPublic',
                ) as $acknowledgement) {
                    $restartPublic->message($acknowledgement);
                }
            },
            static function () use ($restartBusiness): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'restartBusiness',
                ) as $acknowledgement) {
                    $restartBusiness->message($acknowledgement);
                }
            },
        ];
        $resumed = $this->source(
            Task7RestClient::withInitialDataset(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $reconnecting = $resumedEvents->current();

        self::assertInstanceOf(PaperMarketEvent::class, $reconnecting);
        self::assertSame('reconnecting', $reconnecting->payload['state'] ?? null);
        self::assertSame(1, $this->checkpointState()['reconnect']['attempt']);
        self::assertSame(2, $this->checkpointState()['connection_epoch']);
        self::assertSame(1, $restartPublic->closeCount);
        self::assertSame(1, $restartBusiness->closeCount);
        self::assertCount(1, $restartPublic->connections);
        self::assertCount(1, $restartBusiness->connections);
    }

    #[DataProvider('currentReconnectSuffixProvider')]
    public function testReconnectCurrentResponseSuffixSurvivesCrashAfterFirstPendingEvent(
        string $kind,
    ): void {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        if ($kind === 'trade') {
            $stream = 'BTCUSDT/rest/public_trade';
            $stage = 'recent_trades';
            $rows = [
                self::restTrade('100', '1784970100000'),
                self::restTrade('200', '1784970101000'),
                self::restTrade('300', '1784970102000'),
            ];
            $frontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
                $normalizer->recoveryTrade($rows[0]),
            );
        } else {
            $stream = 'BTCUSDT/rest/candle_1m';
            $stage = 'current_candles';
            $rows = [
                ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
                ['1784970060000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
                ['1784970120000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
            ];
            $anchor = $normalizer->warmupCandle(
                'BTC-USDT-SWAP',
                '1m',
                $rows[0],
            );
            self::assertInstanceOf(PaperMarketEvent::class, $anchor);
            $frontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
                $anchor,
            );
        }
        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers'][$stream] = $frontier->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => $stream,
            'stage' => $stage,
        ];
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($seed);
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(
                OkxPaperLiveCheckpoint::fromArray($state)->toArray(),
            ) . "\n",
        ));

        $rest = new Task7RestClient();
        if ($kind === 'trade') {
            $rest->tradeRows['BTC-USDT-SWAP'] = $rows;
        } else {
            $rest->candleRows['BTC-USDT-SWAP/1m'] = $rows;
        }
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pendingA = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pendingA);
        self::assertSame(
            $kind === 'trade' ? '200' : '1784970060000',
            $kind === 'trade'
                ? ($pendingA->payload['trade_id'] ?? null)
                : $pendingA->exchangeTimestamp->format('Uv'),
        );
        $crashed = $this->checkpointState();
        self::assertEquals($pendingA->toArray(), $crashed['pending_event']);
        $crashedOrdinals = $crashed['ordinal_state'];
        $crashedFrontiers = $crashed['stream_frontiers'];
        $source->stop();
        unset($events, $source, $store, $public, $business);
        gc_collect_cycles();

        $restartRest = new Task7RestClient();
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $restartStore,
            clock: $clock,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayedA = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayedA);
        self::assertEquals($pendingA->toArray(), $replayedA->toArray());
        self::assertSame($crashedOrdinals, $this->checkpointState()['ordinal_state']);
        self::assertSame($crashedFrontiers, $this->checkpointState()['stream_frontiers']);

        try {
            $resumed->acknowledge($replayedA->eventId);
            self::assertSame($crashedOrdinals, $this->checkpointState()['ordinal_state']);
            $resumedEvents->next();
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail(sprintf(
                'The durable reconnect suffix was lost after replaying A: %s',
                $exception->getMessage(),
            ));
        }
        $replayedB = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayedB);
        self::assertSame(
            $kind === 'trade' ? '300' : '1784970120000',
            $kind === 'trade'
                ? ($replayedB->payload['trade_id'] ?? null)
                : $replayedB->exchangeTimestamp->format('Uv'),
        );
        self::assertSame([], $restartRest->calls);
        $pendingB = $this->checkpointState();
        self::assertSame(
            $kind === 'trade' ? '200' : '1m|1784970060000',
            $pendingB['stream_frontiers'][$stream]['source_identity'] ?? null,
        );
        self::assertSame(
            $kind === 'trade' ? '300' : '1m|1784970120000',
            $pendingB['pending_frontier']['frontier']['source_identity'] ?? null,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function currentReconnectSuffixProvider(): iterable
    {
        yield 'current candles' => ['candle'];
        yield 'recent trades' => ['trade'];
    }

    public function testReconnectCurrentCandleSuffixFitsTheCanonicalCheckpointBudget(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rows = array_map(
            static fn (int $index): array => [
                (string) (1784970000000 + (60_000 * $index)),
                '100',
                '101',
                '99',
                '100.5',
                '10',
                '1',
                '1000',
                '1',
            ],
            range(0, 299),
        );
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        $anchor = $normalizer->warmupCandle('BTC-USDT-SWAP', '1m', $rows[0]);
        self::assertInstanceOf(PaperMarketEvent::class, $anchor);
        $frontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $anchor,
        );
        $stream = 'BTCUSDT/rest/candle_1m';
        $this->seedSaturatedIdentityCheckpoint();
        $state = $this->checkpointState();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers'][$stream] = $frontier->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => $stream,
            'stage' => 'current_candles',
        ];
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(
                OkxPaperLiveCheckpoint::fromArray($state)->toArray(),
            ) . "\n",
        ));

        $rest = new Task7RestClient();
        $rest->candleRows['BTC-USDT-SWAP/1m'] = $rows;
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                clock: $clock,
            ),
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $first = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('1784970060000', $first->exchangeTimestamp->format('Uv'));
        $retained = $this->checkpointState()['overlap_pagination_by_stream'][$stream][
            'retained_rows'
        ] ?? null;
        self::assertIsArray($retained);
        self::assertCount(300, $retained);
        foreach ($retained as $row) {
            self::assertIsString($row);
        }
        $checkpoint = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($checkpoint);
        self::assertLessThanOrEqual(
            OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES,
            \strlen($checkpoint),
        );
    }

    public function testReconnectRecentTradeSuffixFitsTheCanonicalCheckpointBudget(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rows = array_map(
            static fn (int $index): array => [
                ...self::restTrade(
                    (string) (1_000 + $index),
                    (string) (1784970100000 + $index),
                ),
                'count' => '2',
                'seqId' => 88_001 + $index,
            ],
            range(0, 499),
        );
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        $frontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade($rows[0]),
        );
        $stream = 'BTCUSDT/rest/public_trade';
        $this->seedSaturatedIdentityCheckpoint();
        $state = $this->checkpointState();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers'][$stream] = $frontier->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => $stream,
            'stage' => 'recent_trades',
        ];
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(
                OkxPaperLiveCheckpoint::fromArray($state)->toArray(),
            ) . "\n",
        ));

        // A full recent snapshot drops its oldest millisecond (it may be partial).
        array_unshift($rows, [
            ...self::restTrade('999', '1784970099999'),
            'count' => '2',
            'seqId' => 88_000,
        ]);
        $rest = new Task7RestClient();
        $rest->tradeRows['BTC-USDT-SWAP'] = $rows;
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                clock: $clock,
            ),
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $first = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('1001', $first->payload['trade_id'] ?? null);
        self::assertSame('2', $first->payload['aggregate_count'] ?? null);
        self::assertSame('88002', $first->payload['source_seq_id'] ?? null);
        $retained = $this->checkpointState()['overlap_pagination_by_stream'][$stream][
            'retained_rows'
        ] ?? null;
        self::assertIsArray($retained);
        self::assertCount(499, $retained);
        self::assertSame(CanonicalJson::encode([
            'BTC-USDT-SWAP', '1001', '100.5', '2', 'buy', '2', '0',
            '1784970100001', 88_002,
        ]), $retained[0]);
        self::assertSame(CanonicalJson::encode([
            'BTC-USDT-SWAP', '1499', '100.5', '2', 'buy', '2', '0',
            '1784970100499', 88_500,
        ]), $retained[498]);
    }

    public function testReconnectRecentTradeSuffixFitsWithExternalizedIdentityHistory(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rows = array_map(
            static function (int $index): array {
                $row = self::restTrade(
                    (string) (2_000 + $index),
                    (string) (1784970200000 + $index),
                );
                $row['px'] = '1.' . str_repeat('0', 997) . '1';

                return $row;
            },
            range(0, 499),
        );
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        $frontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade($rows[0]),
        );
        $stream = 'BTCUSDT/rest/public_trade';
        $this->seedSaturatedIdentityCheckpoint();
        $state = $this->checkpointState();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers'][$stream] = $frontier->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => $stream,
            'stage' => 'recent_trades',
        ];
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(
                OkxPaperLiveCheckpoint::fromArray($state)->toArray(),
            ) . "\n",
        ));

        // A full recent snapshot drops its oldest millisecond (it may be partial).
        array_unshift($rows, self::restTrade('1999', '1784970199999'));
        $rest = new Task7RestClient();
        $rest->tradeRows['BTC-USDT-SWAP'] = $rows;
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                clock: $clock,
            ),
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('2001', $first->payload['trade_id'] ?? null);
        $checkpoint = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($checkpoint);
        self::assertLessThanOrEqual(
            OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES,
            \strlen($checkpoint),
        );
    }

    public function testReconnectTradeSuffixRetainsTheSiblingFrontierForItsHistoryStage(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rows = array_map(
            static fn (int $index): array => self::restTrade(
                (string) (1_000 + $index),
                (string) (1784970100000 + $index),
            ),
            range(0, 499),
        );
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        // The REST stream was recovered first, so its frontier (1100) is newer
        // than the websocket one (1050): the websocket suffix starts at 1101.
        $websocketFrontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade($rows[50]),
        );
        $restFrontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade($rows[100]),
        );
        $stream = 'BTCUSDT/ws/public_trade';
        (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        gc_collect_cycles();
        $state = $this->checkpointState();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers'][$stream] = $websocketFrontier->toArray();
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = $restFrontier->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $websocketFrontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => $stream,
            'stage' => 'recent_trades',
        ];
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(
                OkxPaperLiveCheckpoint::fromArray($state)->toArray(),
            ) . "\n",
        ));

        $rest = new Task7RestClient();
        $rest->tradeRows['BTC-USDT-SWAP'] = $rows;
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                clock: $clock,
            ),
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('1101', $first->payload['trade_id'] ?? null);

        // The history cursor covers only the oldest kept millisecond (1001) and
        // older trades: without the rows from the REST frontier on, 1002..1100
        // are never fetched again and the stage pages back to its budget.
        $pagination = $this->checkpointState()['overlap_pagination_by_stream'][$stream] ?? null;
        self::assertIsArray($pagination);
        self::assertSame('1784970100002', $pagination['next_cursor']);
        self::assertSame(
            array_map('strval', range(1_100, 1_499)),
            array_map(
                static fn (string $row): string => OkxPaperRetainedTradeRow::expand($row)['tradeId'],
                $pagination['retained_rows'],
            ),
        );

        $tradeIds = [];
        $failure = null;
        try {
            for ($step = 0; $step < 1_000 && $events->valid(); ++$step) {
                $event = $events->current();
                self::assertInstanceOf(PaperMarketEvent::class, $event);
                if (!isset($event->payload['trade_id'])) {
                    break;
                }
                $tradeIds[] = $event->payload['trade_id'];
                $source->acknowledge($event->eventId);
                $events->next();
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        self::assertSame(array_map('strval', range(1_101, 1_499)), $tradeIds);
        // Once the suffix is durable, its history stage proves both overlaps from
        // the retained rows alone: no history page is requested. The recovery then
        // moves to the reconnect boundary, which this synthetic checkpoint (no book
        // frontier) cannot make actionable.
        self::assertSame(['recentTrades'], array_column($rest->calls, 0));
        self::assertInstanceOf(\Throwable::class, $failure);
        self::assertSame('okx_paper_live_checkpoint_invalid', $failure->getMessage());
    }

    public function testReconnectLongAfterTheLastRestRecoveryOnlyOverlapsTheNewestTradeFrontier(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rows = array_map(
            static fn (int $index): array => self::restTrade(
                (string) (4_900 + $index),
                (string) (1784970100000 + $index),
            ),
            range(0, 499),
        );
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        // The REST frontier dates from 11 minutes before the websocket frontier,
        // which kept streaming: only the newest frontier has to be found again.
        $restFrontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade(self::restTrade('100', '1784969440000')),
        );
        $websocketFrontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade($rows[100]),
        );
        $stream = 'BTCUSDT/rest/public_trade';
        $this->seedTradeRecoveryCheckpoint($stream, [
            $stream => $restFrontier,
            'BTCUSDT/ws/public_trade' => $websocketFrontier,
        ]);
        $rest = new Task7RestClient();
        $rest->tradeRows['BTC-USDT-SWAP'] = $rows;
        $source = $this->reconnectSource($rest, $clock);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        [$tradeIds, $failure] = $this->acknowledgeTradeEvents($source, $events);

        // Nothing is paged back to the REST frontier: the recovery cost follows
        // the outage, not the age of the last REST recovery.
        self::assertSame(array_map('strval', range(5_001, 5_399)), $tradeIds);
        self::assertNotContains('historyTrades', array_column($rest->calls, 0));
        self::assertNotSame('market_data_gap_unresolved', $failure?->getMessage());
    }

    public function testFirstWebsocketTradeAnchorsTheJunctionAndRecordsTheGapFromRest(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        // REST lags behind the websocket once (103 is not served yet), then
        // serves every trade between the warmup frontier (100) and 104.
        $rest->historyTradePages = [
            [
                self::restTrade('102', '1784970100002'),
                self::restTrade('101', '1784970100001'),
                self::restTrade('100', '1784970100000'),
            ],
            [
                self::restTrade('103', '1784970100003'),
                self::restTrade('102', '1784970100002'),
                self::restTrade('101', '1784970100001'),
                self::restTrade('100', '1784970100000'),
            ],
        ];
        $source = $this->junctionSource($rest, Task7Transport::tradeFrame(['104']));
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 14);

        self::assertSame(
            [
                ['101', 'rest_recovery'],
                ['102', 'rest_recovery'],
                ['103', 'rest_recovery'],
                ['104', 'ws_aggregated'],
            ],
            $this->nextTradeEvents($source, $events, 4),
        );
        self::assertSame(
            array_fill(0, 2, ['historyTrades', ['BTC-USDT-SWAP', 1, '104', 100]]),
            array_values(array_filter(
                $rest->calls,
                static fn (array $call): bool => $call[0] === 'historyTrades',
            )),
        );
        $source->stop();
    }

    public function testWebsocketTradesBeforeTheRestSnapshotAreDroppedAndAStraddlingAggregateIsReplaced(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        // REST rows carry the websocket fixture timestamps of the same trades: a
        // dropped websocket row must match what REST recorded.
        // The fills of an aggregate share its timestamp (here the warmup trade's).
        $rest->historyTradePages = [
            [
                self::restTrade('102', '1784970100000'),
                self::restTrade('101', '1784970100000'),
                self::restTrade('100', '1784970100000'),
            ],
            // 99 was recorded before the warmup snapshot: it is read back.
            [self::restTrade('99', '1784970300099')],
        ];
        // The websocket started before the REST snapshot: 99 is already recorded
        // and the aggregate of 100..102 straddles the warmup frontier (100).
        $frame = Task7Transport::tradeFrame(['99', '102', '103']);
        $frame['data'][1]['count'] = '3';
        $frame['data'][1]['sz'] = '6';
        $frame['data'][1]['ts'] = '1784970100000';
        $source = $this->junctionSource($rest, $frame);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 14);

        self::assertSame(
            [['101', 'rest_recovery'], ['102', 'rest_recovery'], ['103', 'ws_aggregated']],
            $this->nextTradeEvents($source, $events, 3),
        );
        self::assertSame(
            [
                ['historyTrades', ['BTC-USDT-SWAP', 1, '103', 100]],
                // The read-back of 99 starts a page ahead (99 + 100).
                ['historyTrades', ['BTC-USDT-SWAP', 1, '199', 100]],
            ],
            array_values(array_filter(
                $rest->calls,
                static fn (array $call): bool => $call[0] === 'historyTrades',
            )),
        );
        $source->stop();
    }

    public function testReconnectJunctionDropsAnAggregateEndingAtTheRecordedFrontier(): void
    {
        $rest = new Task7RestClient();
        $source = $this->source(
            $rest,
            new Task7Transport(),
            new Task7Transport(),
            anchoredTradeJunctions: true,
        );
        $tradeFrontier = new \ReflectionMethod($source, 'tradeFrontier');
        $checkpointProperty = new \ReflectionProperty($source, 'checkpoint');
        $checkpoint = $checkpointProperty->getValue($source);
        self::assertInstanceOf(OkxPaperLiveCheckpoint::class, $checkpoint);
        $state = $checkpoint->toArray();
        // After a reconnect recovery, the websocket trade stream recorded REST rows
        // up to 205 (newer than the REST stream frontier).
        foreach (['rest' => '200', 'ws' => '205'] as $kind => $tradeId) {
            // Same timestamps as the websocket fixture rows of these trades.
            $frontier = $tradeFrontier->invoke(
                $source,
                self::restTrade($tradeId, (string) (1784970300000 + (int) $tradeId)),
            );
            self::assertInstanceOf(\App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::class, $frontier);
            $state['stream_frontiers']['BTCUSDT/' . $kind . '/public_trade'] = $frontier->toArray();
        }
        $checkpointProperty->setValue($source, OkxPaperLiveCheckpoint::fromArray($state));
        (new \ReflectionMethod($source, 'openTradeJunctions'))->invoke($source);
        $recorded = [];
        foreach (['203' => '2', '204' => '2', '205' => '3'] as $tradeId => $size) {
            $row = self::restTrade((string) $tradeId, '1784970300205');
            $row['sz'] = $size;
            $recorded[] = $row;
        }
        (new \ReflectionMethod($source, 'rememberRestTradeRows'))->invoke($source, 'BTCUSDT', $recorded);

        // The first queued websocket trade aggregates 203..205: its size differs
        // from the REST row of 205, which failed as an identity conflict when the
        // junction required an exact overlap.
        $frame = Task7Transport::tradeFrame(['205', '206']);
        $frame['data'][0]['count'] = '3';
        $frame['data'][0]['sz'] = '7';
        $accepted = (new \ReflectionMethod($source, 'eventsFromMessage'))->invoke($source, $frame, false);

        self::assertIsArray($accepted);
        self::assertSame(
            ['206'],
            array_map(
                static fn (array $accepted): mixed => $accepted['event']->payload['trade_id'] ?? null,
                $accepted,
            ),
        );
        $junctions = (new \ReflectionProperty($source, 'tradeJunctions'))->getValue($source);
        self::assertIsArray($junctions);
        self::assertArrayNotHasKey('BTCUSDT', $junctions);
        self::assertSame([], $rest->calls);
    }

    public function testAnchoredJunctionFailsClosedOnANonContiguousWebsocketTrade(): void
    {
        // No history page ever serves 101..9912: after the bounded retries the
        // junction fails closed instead of recording a gap. With a transport that
        // fails on close (healthy-stop fixtures), the terminal cleanup raises that
        // transport failure instead.
        $rest = Task7RestClient::withInitialDataset();
        $source = $this->junctionSource($rest, Task7Transport::tradeFrame(['9913']));
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        try {
            $this->acknowledgeWarmup($source, $events);
            $events->current();
            self::fail('A non-contiguous websocket trade must not be recorded.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }

        self::assertSame('failed', $this->checkpointState()['phase'] ?? null);
        self::assertSame('market_data_gap_unresolved', $this->checkpointState()['failure_reason'] ?? null);
        self::assertSame(
            array_fill(0, 6, ['historyTrades', ['BTC-USDT-SWAP', 1, '9913', 100]]),
            array_values(array_filter(
                $rest->calls,
                static fn (array $call): bool => $call[0] === 'historyTrades',
            )),
        );
    }

    public function testCandleJunctionAcceptsTheNextConfirmedCandleAndDropsRecordedOnes(): void
    {
        $source = $this->candleJunctionReflectionSource(67, 66);

        $accepted = (new \ReflectionMethod($source, 'eventsFromMessage'))->invoke($source, [
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [
                self::candleRow(self::minute(67)),
                self::candleRow(self::minute(68)),
                self::candleRow(self::minute(69), '0'),
            ],
        ], true);

        self::assertIsArray($accepted);
        self::assertSame(
            ['10:08'],
            array_map(
                static fn (array $accepted): string => $accepted['event']->exchangeTimestamp->format('H:i'),
                $accepted,
            ),
        );
        $junctions = (new \ReflectionProperty($source, 'candleJunctions'))->getValue($source);
        self::assertIsArray($junctions);
        self::assertArrayNotHasKey('BTCUSDT/1m', $junctions);
        self::assertArrayHasKey('BTCUSDT/5m', $junctions);
        self::assertArrayHasKey('ETHUSDT/1H', $junctions);
    }

    public function testCandleJunctionFailsClosedWhenTheWebsocketDiffersFromTheRecordedCandle(): void
    {
        $source = $this->candleJunctionReflectionSource(67, 66);
        $recorded = self::candleRow(self::minute(67));
        $recorded[4] = '100.7';

        try {
            (new \ReflectionMethod($source, 'eventsFromMessage'))->invoke($source, [
                'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [$recorded, self::candleRow(self::minute(68))],
            ], true);
            self::fail('A websocket candle differing from its REST record must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
    }

    public function testCandleJunctionWiderThanTheCurrentCandlesFailsClosed(): void
    {
        $rest = new Task7RestClient();
        $source = $this->source($rest, new Task7Transport(), new Task7Transport(), anchoredTradeJunctions: true);
        $newest = (new \ReflectionMethod($source, 'candleFrontier'))
            ->invoke($source, 'BTC-USDT-SWAP', '1m', self::candleRow(self::minute(0)));

        try {
            (new \ReflectionMethod($source, 'candleJunctionRows'))
                ->invoke($source, 'BTC-USDT-SWAP', '1m', $newest, (string) self::minute(400));
            self::fail('A junction wider than 300 bars must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }
        self::assertSame([], $rest->calls);
    }

    public function testCandleJunctionRecordsMissingBarsFromRestAndItsPendingStateRoundTrips(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        // Warmup and bridge see 09:00; REST then lags once (09:02 missing) before
        // serving every bar between 09:00 and the first websocket candle (09:03).
        $rest->candleResponsePages['BTC-USDT-SWAP/1m'] = [
            [self::candleRow(self::minute(0))],
            [self::candleRow(self::minute(0))],
            [self::candleRow(self::minute(1)), self::candleRow(self::minute(0))],
            [
                self::candleRow(self::minute(3), '0'),
                self::candleRow(self::minute(2)),
                self::candleRow(self::minute(1)),
                self::candleRow(self::minute(0)),
            ],
        ];
        $source = $this->candleJunctionSource($rest, self::candleFrame(self::minute(3)));
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 14);

        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        $state = $this->checkpointState();
        self::assertSame('streaming', $state['phase'] ?? null);
        self::assertSame('BTCUSDT/rest/candle_1m', $state['pending_frontier']['stream'] ?? null);
        $roundTrip = OkxPaperLiveCheckpoint::fromArray($state)->toArray();
        self::assertSame($roundTrip, OkxPaperLiveCheckpoint::fromArray($roundTrip)->toArray());
        self::assertSame(
            CanonicalJson::encode(['pending_event' => $state['pending_event'] ?? null]),
            CanonicalJson::encode(['pending_event' => $roundTrip['pending_event'] ?? null]),
        );
        self::assertSame(
            [['09:01', 'rest_warmup'], ['09:02', 'rest_warmup'], ['09:03', 'ws_candle']],
            $this->nextCandleEvents($source, $events, 3),
        );
        $source->stop();
    }

    public function testCandleJunctionFillHardCrashRestartsWithoutGapOrDuplicate(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the hard-crash junction test.');
        }
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Forked child: see ignoreInheritedShutdownErrorsInForkedChild().
            self::ignoreInheritedShutdownErrorsInForkedChild();
            // The first websocket candle (09:03) anchors the junction after the
            // bridged frontier (09:00): the child is killed while REST fills it.
            $rest = Task7RestClient::withInitialDataset();
            $rest->candleResponsePages['BTC-USDT-SWAP/1m'] = [
                [self::candleRow(self::minute(0))],
                [self::candleRow(self::minute(0))],
            ];
            $rest->beforeCurrentCandles = static function (string $instrumentId, string $bar, int $call): void {
                if ($instrumentId === 'BTC-USDT-SWAP' && $bar === '1m' && $call === 3) {
                    posix_kill(posix_getpid(), \SIGKILL);
                }
            };
            $source = $this->candleJunctionSource($rest, self::candleFrame(self::minute(3)));
            $events = $source->events();
            self::assertInstanceOf(\Generator::class, $events);
            $this->acknowledgeWarmup($source, $events);
            $events->current();
            exit(93);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status), 'The child must die inside the candle junction fill.');
        self::assertSame(\SIGKILL, pcntl_wtermsig($status));

        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $state = $this->checkpointState();
        self::assertSame('streaming', $state['phase'] ?? null);
        self::assertNull($state['pending_event'] ?? null);
        self::assertSame(
            '1m|' . self::minute(0),
            $state['stream_frontiers']['BTCUSDT/rest/candle_1m']['source_identity'] ?? null,
        );

        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->candleRows['BTC-USDT-SWAP/1m'] = [
            self::candleRow(self::minute(2)),
            self::candleRow(self::minute(1)),
            self::candleRow(self::minute(0)),
        ];
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(self::publicArguments(), 'restartPublic');
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $restartClock = new MockClock('2026-07-25T10:00:00.000000Z');
        $restartDeterministic = new DeterministicLoop();
        $restartLoop = new Task7ScriptedLoop($restartDeterministic);
        $restartLoop->scripts = [
            static function () use ($restartClock, $restartDeterministic): void {
                $restartClock->sleep(1);
                $restartDeterministic->fireTimerInterval(1.0);
            },
        ];
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $restartClock,
            loop: $restartLoop,
            anchoredTradeJunctions: true,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $minutes = [];
        for ($index = 0; $index < 400 && end($minutes) !== '09:02'; ++$index) {
            if ($index > 0) {
                $resumedEvents->next();
            }
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel === PaperMarketDataChannel::CANDLE_1M && $event->symbol === 'BTCUSDT') {
                $minutes[] = $event->exchangeTimestamp->format('H:i');
            }
            $resumed->acknowledge($event->eventId);
        }

        // The reconnect recovery records 09:01 and 09:02 exactly once from the
        // frontier the crash left: no gap, no duplicate.
        self::assertSame(['09:01', '09:02'], $minutes);
        $resumed->stop();
    }

    private function candleJunctionReflectionSource(int $restMinute, int $websocketMinute): OkxPaperPublicLiveSource
    {
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            anchoredTradeJunctions: true,
        );
        $candleFrontier = new \ReflectionMethod($source, 'candleFrontier');
        $checkpointProperty = new \ReflectionProperty($source, 'checkpoint');
        $checkpoint = $checkpointProperty->getValue($source);
        self::assertInstanceOf(OkxPaperLiveCheckpoint::class, $checkpoint);
        $state = $checkpoint->toArray();
        // After a reconnect recovery REST recorded a newer candle than the
        // websocket stream, which never sends a confirmed candle again.
        foreach (['rest' => $restMinute, 'ws' => $websocketMinute] as $kind => $minute) {
            $frontier = $candleFrontier->invoke($source, 'BTC-USDT-SWAP', '1m', self::candleRow(self::minute($minute)));
            self::assertInstanceOf(\App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::class, $frontier);
            $state['stream_frontiers']['BTCUSDT/' . $kind . '/candle_1m'] = $frontier->toArray();
        }
        $checkpointProperty->setValue($source, OkxPaperLiveCheckpoint::fromArray($state));
        (new \ReflectionMethod($source, 'openCandleJunctions'))->invoke($source);

        return $source;
    }

    /** @param array<string, mixed> $candleFrame */
    private function candleJunctionSource(Task7RestClient $rest, array $candleFrame): OkxPaperPublicLiveSource
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(self::publicArguments(), 'public');
        $business->responses = [
            ...Task7Transport::acknowledgements(self::businessArguments(), 'business'),
            $candleFrame,
        ];

        return $this->source($rest, $public, $business, anchoredTradeJunctions: true);
    }

    /** @return list<array{string, string}> */
    private function nextCandleEvents(OkxPaperPublicLiveSource $source, \Generator $events, int $count): array
    {
        $candles = [];
        for ($index = 0; $index < $count; ++$index) {
            if ($index > 0) {
                $events->next();
            }
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $candles[] = [
                $event->exchangeTimestamp->format('H:i'),
                (string) ($event->payload['origin'] ?? ''),
            ];
            $source->acknowledge($event->eventId);
        }

        return $candles;
    }

    /** BTCUSDT 1m candle open time, $minutes after the warmup candle (09:00). */
    private static function minute(int $minutes): int
    {
        return 1784970000000 + $minutes * 60_000;
    }

    /** @return list<string> */
    private static function candleRow(int $timestamp, string $confirmed = '1'): array
    {
        return [(string) $timestamp, '100', '101', '99', '100.5', '10', '1', '1000', $confirmed];
    }

    /** @return array<string, mixed> */
    private static function candleFrame(int $timestamp): array
    {
        return [
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [self::candleRow($timestamp)],
        ];
    }

    /** @return iterable<string, array{list<array<string, string>>, list<string>|null}> */
    public static function droppedAggregateProvider(): iterable
    {
        // REST recorded 4344383061..66 (buy, same millisecond): 61..64 at 2677.8
        // (0.01, 0.1, 0.01, 0.01), 65..66 at 2677.81 (0.01 each), up to the frontier.
        $new = self::websocketTrade('4344383067', '1', '2677.81', '0.01');
        yield 'aggregates as OKX sends them' => [[
            self::websocketTrade('4344383064', '4', '2677.8', '0.13'),
            self::websocketTrade('4344383066', '2', '2677.81', '0.02'),
            $new,
        ], ['4344383067']];
        yield 'size differs' => [[self::websocketTrade('4344383066', '2', '2677.81', '0.03'), $new], null];
        yield 'price differs' => [[self::websocketTrade('4344383066', '2', '2677.8', '0.02'), $new], null];
        yield 'side differs' => [[self::websocketTrade('4344383066', '2', '2677.81', '0.02', 'sell'), $new], null];
        yield 'aggregate across two prices' => [[self::websocketTrade('4344383066', '3', '2677.8', '0.03'), $new], null];
        yield 'timestamp differs' => [[
            array_replace(self::websocketTrade('4344383066', '2', '2677.81', '0.02'), ['ts' => '1790722657582']),
            $new,
        ], null];
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string>|null $accepted null when the junction must fail closed
     */
    #[DataProvider('droppedAggregateProvider')]
    public function testJunctionComparesADroppedAggregateWithItsWholeRestRange(array $rows, ?array $accepted): void
    {
        $rest = new Task7RestClient();
        $source = $this->droppedAggregateSource($rest);
        (new \ReflectionMethod($source, 'rememberRestTradeRows'))->invoke($source, 'BTCUSDT', self::restRange());

        try {
            $events = (new \ReflectionMethod($source, 'eventsFromMessage'))->invoke($source, [
                'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
                'data' => $rows,
            ], false);
            self::assertIsArray($events);
            self::assertSame($accepted, array_map(
                static fn (array $accepted): mixed => $accepted['event']->payload['trade_id'] ?? null,
                $events,
            ));
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertNull($accepted, $exception->getMessage());
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
            // Both sides are chained for diagnosis: the aggregate and all its REST rows.
            $cause = $exception->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $cause);
            self::assertStringStartsWith('okx_paper_junction_trade_mismatch ', $cause->getMessage());
            self::assertStringContainsString('"websocket":{"tradeId":"4344383066"', $cause->getMessage());
            self::assertStringContainsString('"tradeId":"4344383065"', $cause->getMessage());
        }
        self::assertSame([], $rest->calls);
    }

    public function testJunctionAcceptsAProductionTradeWhoseSourceDiffersBetweenWebsocketAndRest(): void
    {
        $logger = new OkxRecordingLogger();
        $recorded = [
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => '4345185165',
            'px' => '2675.28',
            'sz' => '81.47',
            'side' => 'sell',
            'source' => '0',
            'ts' => '1790754797224',
        ];
        $source = $this->droppedTradeSource([$recorded], $logger);

        // Production (run2, 07:53:42): the same trade, `source` 1 on the websocket.
        $events = (new \ReflectionMethod($source, 'eventsFromMessage'))->invoke($source, [
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [
                ['count' => '1', 'seqId' => '4345185165'] + array_replace($recorded, ['source' => '1']),
                ['count' => '1', 'seqId' => '4345185166'] + array_replace($recorded, ['tradeId' => '4345185166']),
            ],
        ], false);

        self::assertIsArray($events);
        self::assertSame(['4345185166'], array_map(
            static fn (array $accepted): mixed => $accepted['event']->payload['trade_id'] ?? null,
            $events,
        ));
        $divergence = $logger->first('okx_paper_public_trade_source_divergence');
        self::assertSame('4345185165', $divergence['trade_id']);
        self::assertSame('1', $divergence['websocket_source']);
        self::assertSame([['trade_id' => '4345185165', 'rest_source' => '0']], $divergence['rest_sources']);
    }

    public function testJunctionAcceptsAnAggregateWhoseRestRowsDisagreeOnSource(): void
    {
        $logger = new OkxRecordingLogger();
        $rows = self::restRange();
        $rows[4]['source'] = '1';
        $source = $this->droppedTradeSource($rows, $logger);

        $events = (new \ReflectionMethod($source, 'eventsFromMessage'))->invoke($source, [
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [
                self::websocketTrade('4344383066', '2', '2677.81', '0.02'),
                self::websocketTrade('4344383067', '1', '2677.81', '0.01'),
            ],
        ], false);

        self::assertIsArray($events);
        self::assertCount(1, $events);
        self::assertSame(
            [['trade_id' => '4344383065', 'rest_source' => '1']],
            $logger->first('okx_paper_public_trade_source_divergence')['rest_sources'],
        );
    }

    public function testReconnectRecoveryOverlapsAWebsocketFrontierWhoseSourceDiffersFromRest(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rows = array_map(
            static fn (int $index): array => self::restTrade(
                (string) (4_900 + $index),
                (string) (1784970100000 + $index),
            ),
            range(0, 499),
        );
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        $restFrontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade(self::restTrade('100', '1784969440000')),
        );
        // The websocket recorded trade 5000 with `source` 1; REST serves it with 0.
        $websocketRow = ['count' => '1', 'seqId' => '5000'] + array_replace($rows[100], ['source' => '1']);
        $websocketFrontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->webSocketTrade($websocketRow),
        );
        $stream = 'BTCUSDT/rest/public_trade';
        $this->seedTradeRecoveryCheckpoint($stream, [
            $stream => $restFrontier,
            'BTCUSDT/ws/public_trade' => $websocketFrontier,
        ]);
        $rest = new Task7RestClient();
        $rest->tradeRows['BTC-USDT-SWAP'] = $rows;
        $source = $this->reconnectSource($rest, $clock);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        [$tradeIds, $failure] = $this->acknowledgeTradeEvents($source, $events);

        // The A1 overlap with the newest (websocket) frontier ignores `source`.
        self::assertSame(array_map('strval', range(5_001, 5_399)), $tradeIds);
        self::assertNotSame('market_event_identity_conflict', $failure?->getMessage());
    }

    /** @param list<array<string, string>> $restRows the last one is the recorded frontier */
    private function droppedTradeSource(array $restRows, OkxRecordingLogger $logger): OkxPaperPublicLiveSource
    {
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            anchoredTradeJunctions: true,
            logger: $logger,
        );
        $frontier = (new \ReflectionMethod($source, 'tradeFrontier'))->invoke($source, $restRows[\count($restRows) - 1]);
        self::assertInstanceOf(\App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::class, $frontier);
        $checkpointProperty = new \ReflectionProperty($source, 'checkpoint');
        $checkpoint = $checkpointProperty->getValue($source);
        self::assertInstanceOf(OkxPaperLiveCheckpoint::class, $checkpoint);
        $state = $checkpoint->toArray();
        $state['stream_frontiers']['BTCUSDT/ws/public_trade'] = $frontier->toArray();
        $checkpointProperty->setValue($source, OkxPaperLiveCheckpoint::fromArray($state));
        (new \ReflectionMethod($source, 'openTradeJunctions'))->invoke($source);
        (new \ReflectionMethod($source, 'rememberRestTradeRows'))->invoke($source, 'BTCUSDT', $restRows);

        return $source;
    }

    public function testReconnectBookOverlapAcceptsIntegerWebsocketSequences(): void
    {
        $publicQueue = new OkxPaperPublicFrameQueue();
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            publicQueue: $publicQueue,
        );
        // OKX pushes book sequences as JSON integers, not strings.
        $update = Task7Transport::bookFrame('9002', '9001', '4');
        $update['data'][0]['seqId'] = 9002;
        $update['data'][0]['prevSeqId'] = 9001;
        $next = Task7Transport::bookFrame('9003', '9002', '5');
        $next['data'][0]['seqId'] = 9003;
        $next['data'][0]['prevSeqId'] = 9002;
        $publicQueue->replace([
            json_encode($update, \JSON_THROW_ON_ERROR),
            json_encode($next, \JSON_THROW_ON_ERROR),
        ]);

        $found = (new \ReflectionMethod($source, 'filterQueuedBookOverlap'))
            ->invoke($source, 'BTC-USDT-SWAP', '9001');

        // The chain from the REST snapshot (9001) is found and kept for streaming.
        self::assertTrue($found);
        self::assertCount(2, $publicQueue->frames());
        self::assertTrue((new \ReflectionMethod($source, 'hasQueuedReconnectBookAuthority'))
            ->invoke($source, 'BTC-USDT-SWAP', '9001'));
    }

    public function testJunctionReadsBackRestRowsRecordedBeforeARestart(): void
    {
        $rest = new Task7RestClient();
        $rest->historyTradePages = [array_reverse(self::restRange())];
        $source = $this->droppedAggregateSource($rest);

        $events = (new \ReflectionMethod($source, 'eventsFromMessage'))->invoke($source, [
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [
                self::websocketTrade('4344383066', '2', '2677.81', '0.02'),
                self::websocketTrade('4344383067', '1', '2677.81', '0.01'),
            ],
        ], false);

        self::assertIsArray($events);
        self::assertCount(1, $events);
        // One page from the aggregate's first id (…065 + 100): it also covers the next ids.
        self::assertSame([['historyTrades', ['BTC-USDT-SWAP', 1, '4344383165', 100]]], $rest->calls);
    }

    /**
     * After a long reconnect recovery, the websocket aggregates queued since the new
     * connection overlap it: each is dropped and compared with its REST rows, and
     * those beyond the remembered rows are read back from REST history. A read-back
     * page must also cover the following aggregates, or the drain falls to one REST
     * request per aggregate (production run: ~3.5 aggregates/s instead of ~100).
     */
    #[DataProvider('readBackAfterLongRecoveryProvider')]
    public function testDroppedAggregatesAfterALongRecoveryAreReadBackAHundredIdsPerRequest(
        bool $rememberRecovery,
        int $maximumRequests,
    ): void {
        $base = 4_344_300_000;
        // A 20,000-row recovery, in groups of 3 fills at one price and timestamp.
        $history = [];
        for ($index = 1; $index <= 20_000; ++$index) {
            $history[] = [
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => (string) ($base + $index),
                'px' => '2677.8',
                'sz' => '0.01',
                'side' => 'buy',
                'source' => '0',
                'ts' => (string) (1_790_722_000_000 + intdiv($index, 3)),
            ];
        }
        $rest = new Task7RestClient();
        // OKX history (type 1): the 100 trades older than `after`, newest first.
        $rest->beforeHistoryTrades = static function () use ($rest, $history, $base): void {
            $below = min(20_000, max(0, (int) $rest->calls[\count($rest->calls) - 1][1][2] - $base - 1));
            $rest->historyTradePages = [array_reverse(\array_slice($history, max(0, $below - 100), min(100, $below)))];
        };
        $source = $this->source($rest, new Task7Transport(), new Task7Transport(), anchoredTradeJunctions: true);
        $frontier = (new \ReflectionMethod($source, 'tradeFrontier'))->invoke($source, $history[19_999]);
        self::assertInstanceOf(\App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::class, $frontier);
        $checkpointProperty = new \ReflectionProperty($source, 'checkpoint');
        $checkpoint = $checkpointProperty->getValue($source);
        self::assertInstanceOf(OkxPaperLiveCheckpoint::class, $checkpoint);
        $state = $checkpoint->toArray();
        $state['stream_frontiers']['BTCUSDT/ws/public_trade'] = $frontier->toArray();
        $checkpointProperty->setValue($source, OkxPaperLiveCheckpoint::fromArray($state));
        (new \ReflectionMethod($source, 'openTradeJunctions'))->invoke($source);
        if ($rememberRecovery) {
            // As the recovery's acceptance does (ascending): the newest 5,000 rows stay.
            (new \ReflectionMethod($source, 'rememberRestTradeRows'))->invoke($source, 'BTCUSDT', $history);
        }

        // The websocket queue overlaps the newest 9,000 recovered ids (3,000 aggregates).
        $eventsFromMessage = new \ReflectionMethod($source, 'eventsFromMessage');
        for ($group = 0; $group < 3_000; $group += 100) {
            $aggregates = [];
            foreach (range($group, $group + 99) as $offset) {
                $last = $history[11_000 + 3 * $offset + 2];
                $aggregates[] = ['count' => '3', 'sz' => '0.03', 'seqId' => $last['tradeId']] + $last;
            }
            $events = $eventsFromMessage->invoke($source, [
                'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
                'data' => $aggregates,
            ], false);
            self::assertSame([], $events);
        }

        $requests = \count($rest->calls);
        self::assertLessThanOrEqual($maximumRequests, $requests, sprintf('%d history requests', $requests));
    }

    /** @return iterable<string, array{bool, int}> */
    public static function readBackAfterLongRecoveryProvider(): iterable
    {
        // 4,000 ids beyond the 5,000 remembered rows: 40 pages of 100.
        yield 'recovery remembered' => [true, 41];
        // After a restart nothing is remembered: 9,000 ids, 90 pages.
        yield 'restart, nothing remembered' => [false, 91];
    }

    private function droppedAggregateSource(Task7RestClient $rest): OkxPaperPublicLiveSource
    {
        $source = $this->source($rest, new Task7Transport(), new Task7Transport(), anchoredTradeJunctions: true);
        $frontier = (new \ReflectionMethod($source, 'tradeFrontier'))->invoke($source, self::restRange()[5]);
        self::assertInstanceOf(\App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::class, $frontier);
        $checkpointProperty = new \ReflectionProperty($source, 'checkpoint');
        $checkpoint = $checkpointProperty->getValue($source);
        self::assertInstanceOf(OkxPaperLiveCheckpoint::class, $checkpoint);
        $state = $checkpoint->toArray();
        $state['stream_frontiers']['BTCUSDT/ws/public_trade'] = $frontier->toArray();
        $checkpointProperty->setValue($source, OkxPaperLiveCheckpoint::fromArray($state));
        (new \ReflectionMethod($source, 'openTradeJunctions'))->invoke($source);

        return $source;
    }

    /** @return list<array<string, string>> */
    private static function restRange(): array
    {
        $rows = [];
        foreach ([61 => ['2677.8', '0.01'], 62 => ['2677.8', '0.1'], 63 => ['2677.8', '0.01'],
            64 => ['2677.8', '0.01'], 65 => ['2677.81', '0.01'], 66 => ['2677.81', '0.01']] as $suffix => [$price, $size]) {
            $rows[] = [
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => '43443830' . $suffix,
                'px' => $price,
                'sz' => $size,
                'side' => 'buy',
                'source' => '0',
                'ts' => '1790722657581',
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    private static function websocketTrade(
        string $tradeId,
        string $count,
        string $price,
        string $size,
        string $side = 'buy',
    ): array {
        return [
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => $tradeId,
            'px' => $price,
            'sz' => $size,
            'side' => $side,
            'source' => '0',
            'ts' => '1790722657581',
            'count' => $count,
            'seqId' => $tradeId,
        ];
    }

    public function testServiceUpgradeNoticeTriggersAPlannedPairedReconnect(): void
    {
        $logger = new OkxRecordingLogger();
        [$source, $events, $public, $business, $loop] = $this->streamingFixture($logger);
        $captured = null;
        $stopOnceReconnecting = function () use (&$stopOnceReconnecting, &$captured, $loop, $source, $public, $business): void {
            $state = $this->checkpointState();
            if ($state['phase'] !== 'reconnecting') {
                $loop->scripts[] = $stopOnceReconnecting;

                return;
            }
            $captured = [$state, $public->closeCount, $business->closeCount];
            $source->stop();
        };
        $loop->scripts[] = $stopOnceReconnecting;

        $public->message([
            'event' => 'notice',
            'code' => '64008',
            'msg' => 'The connection will soon be closed for a service upgrade. Please reconnect.',
            'connId' => 'a4d3ae55',
        ]);
        try {
            $events->next();
        } catch (\Throwable) {
            // Stopped while the scripted transports never reconnect.
        }

        // The notice is consumed and the usual paired reconnect (A1/B1/C1 recovery)
        // starts at once, before OKX closes the connection.
        self::assertIsArray($captured);
        [$state, $publicCloses, $businessCloses] = $captured;
        self::assertSame(1, $publicCloses);
        self::assertSame(1, $businessCloses);
        self::assertSame(2, $state['connection_epoch']);
        self::assertSame(1, $state['reconnect']['attempt']);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $state['remaining_symbols']);
        self::assertSame(
            ['kind' => 'timer_schedule', 'stage' => 'reconnect_delay', 'stream' => null, 'symbol' => null],
            $state['pending_transition'],
        );
        self::assertSame(
            ['okx_paper_public_service_notice', 'okx_paper_public_planned_reconnect'],
            array_values(array_intersect(
                $logger->messages(),
                ['okx_paper_public_service_notice', 'okx_paper_public_planned_reconnect'],
            )),
        );
        $notice = $logger->first('okx_paper_public_service_notice');
        self::assertSame('public', $notice['socket']);
        self::assertSame('64008', $notice['notice_code']);
        self::assertSame('streaming', $notice['phase']);
    }

    public function testUnknownControlEventFailsClosedWithAMaskedFrameExcerptInTheLog(): void
    {
        $logger = new OkxRecordingLogger();
        [, $events, $public, , $loop] = $this->streamingFixture($logger);
        // Control frames are decoded as the socket admits them, inside the loop.
        $loop->scripts[] = static function () use ($public): void {
            $public->message('{"event":"channel-conn-count","channel":"trades","connCount":"2","connId":"a4d3ae55"}');
        };

        try {
            $events->next();
            self::fail('An unknown control event must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_message_invalid', $exception->getMessage());
        }

        $rejected = $logger->first('okx_paper_public_frame_rejected');
        self::assertSame('public', $rejected['socket']);
        self::assertStringContainsString('"event":"channel-conn-count"', (string) $rejected['frame_excerpt']);
        self::assertSame('okx_paper_public_message_invalid', $rejected['exception_message']);
        self::assertStringStartsWith('OkxPaperPublicFrameDecoder.php:', (string) $rejected['exception_site']);
        self::assertSame('streaming', $rejected['phase']);
        // The failure leaving the source is never anonymous: reason, site and frame.
        $failed = $logger->first('okx_paper_public_source_failed');
        self::assertSame('okx_paper_public_message_invalid', $failed['public_reason']);
        self::assertStringStartsWith('OkxPaperPublicLiveSource.php:', (string) $failed['exception_site']);
        self::assertStringContainsString('@OkxPaperPublicFrameDecoder.php:', (string) $failed['exception_previous']);
        self::assertIsArray($failed['rejected_frame']);
        self::assertSame('public', $failed['rejected_frame']['socket']);
    }

    public function testANewConnectionForgetsTheBookResubscriptionsOfTheDeadOne(): void
    {
        [$source, , $public] = $this->streamingFixture(new OkxRecordingLogger());
        $resubscriptions = new \ReflectionProperty($source, 'bookResubscriptions');
        $discarded = new \ReflectionProperty($source, 'discardedBookSnapshots');
        $resubscriptions->setValue($source, ['ETH-USDT-SWAP' => ['subscribe' => true]]);
        $discarded->setValue($source, ['ETHUSDT' => true]);

        $public->disconnect();

        // The reconnect's own books acknowledgement must not be taken for the dead
        // connection's resubscription.
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
        self::assertSame([], $resubscriptions->getValue($source));
        self::assertSame([], $discarded->getValue($source));
    }

    public function testUnrequestedUnsubscribeAcknowledgementFailsClosed(): void
    {
        $logger = new OkxRecordingLogger();
        [, $events, $public, , $loop] = $this->streamingFixture($logger);
        $loop->scripts[] = static function () use ($public): void {
            $public->message([
                'event' => 'unsubscribe',
                'arg' => ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
                'connId' => 'a4d3ae55',
            ]);
        };

        try {
            $events->next();
            self::fail('Only the acknowledgement of our own resubscription is accepted.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_subscription_invalid', $exception->getMessage());
        }
    }

    public function testWebSocketCloseIsLoggedWithItsCodeAndSocket(): void
    {
        $logger = new OkxRecordingLogger();
        [, , $public] = $this->streamingFixture($logger);

        $public->disconnect(1006);

        $closed = $logger->first('okx_paper_public_ws_closed');
        self::assertSame('public', $closed['socket']);
        self::assertSame(1006, $closed['ws_close_code']);
        self::assertTrue($closed['current_connection']);
        self::assertSame('streaming', $closed['phase']);
        self::assertSame('reconnecting', $this->checkpointState()['phase']);
    }

    /**
     * Warmup then one acknowledged websocket trade, on scripted transports.
     *
     * @return array{OkxPaperPublicLiveSource, \Generator, FakeOkxPaperPublicWebSocketTransport, FakeOkxPaperPublicWebSocketTransport, Task7ScriptedLoop}
     */
    private function streamingFixture(OkxRecordingLogger $logger): array
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $loop = new Task7ScriptedLoop(new DeterministicLoop());
        $loop->scripts = [
            static function () use ($public): void {
                $public->open();
            },
            static function () use ($business): void {
                $business->open();
            },
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(self::publicArguments(), 'public') as $acknowledgement) {
                    $public->message($acknowledgement);
                }
            },
            static function () use ($business): void {
                foreach (Task7Transport::acknowledgements(self::businessArguments(), 'business') as $acknowledgement) {
                    $business->message($acknowledgement);
                }
            },
            static function () use ($public): void {
                $public->message(Task7Transport::tradeFrame(['9901']));
            },
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            logger: $logger,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        $source->acknowledge($trade->eventId);

        return [$source, $events, $public, $business, $loop];
    }

    public function testJunctionPaginationHardCrashRestartsWithoutGapOrDuplicate(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the hard-crash junction test.');
        }
        $trade = static fn (int $tradeId): array => self::restTrade(
            (string) $tradeId,
            (string) (1784970100000 + $tradeId - 100),
        );
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Forked child: see ignoreInheritedShutdownErrorsInForkedChild().
            self::ignoreInheritedShutdownErrorsInForkedChild();
            // The first websocket trade (250) anchors the junction after the warmup
            // frontier (100): the child is killed while paging 101..249 by id.
            $rest = Task7RestClient::withInitialDataset();
            $rest->historyTradePages = [
                array_map($trade, range(249, 150)),
                array_map($trade, range(149, 100)),
            ];
            $rest->beforeHistoryTrades = static function (int $call): void {
                if ($call === 2) {
                    posix_kill(posix_getpid(), \SIGKILL);
                }
            };
            $source = $this->junctionSource($rest, Task7Transport::tradeFrame(['250']));
            $events = $source->events();
            self::assertInstanceOf(\Generator::class, $events);
            $this->acknowledgeWarmup($source, $events);
            $events->current();
            exit(93);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status), 'The child must die inside the junction pagination.');
        self::assertSame(\SIGKILL, pcntl_wtermsig($status));

        // The checkpoint left by the crash is reloaded (fromArray) as is: streaming,
        // nothing of the junction recorded, the REST trade frontier still at 100.
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $state = $this->checkpointState();
        self::assertSame('streaming', $state['phase'] ?? null);
        self::assertNull($state['pending_event'] ?? null);
        self::assertSame(
            '100',
            $state['stream_frontiers']['BTCUSDT/rest/public_trade']['source_identity'] ?? null,
        );

        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->tradeRows['BTC-USDT-SWAP'] = array_map($trade, range(100, 250));
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $restartClock = new MockClock('2026-07-25T10:00:00.000000Z');
        $restartDeterministic = new DeterministicLoop();
        $restartLoop = new Task7ScriptedLoop($restartDeterministic);
        $restartLoop->scripts = [
            static function () use ($restartClock, $restartDeterministic): void {
                $restartClock->sleep(1);
                $restartDeterministic->fireTimerInterval(1.0);
            },
        ];
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $restartClock,
            loop: $restartLoop,
            anchoredTradeJunctions: true,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $tradeIds = [];
        for ($index = 0; $index < 400 && end($tradeIds) !== '250'; ++$index) {
            if ($index > 0) {
                $resumedEvents->next();
            }
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE && $event->symbol === 'BTCUSDT') {
                $tradeIds[] = $event->payload['trade_id'] ?? null;
            }
            $resumed->acknowledge($event->eventId);
        }

        // The reconnect recovery records 101..250 exactly once from the newest
        // frontier: the killed pagination leaves neither a gap nor a duplicate.
        self::assertSame(array_map('strval', range(101, 250)), $tradeIds);
        self::assertSame(
            '250',
            $this->checkpointState()['stream_frontiers']['BTCUSDT/rest/public_trade']['source_identity'] ?? null,
        );
        $resumed->stop();
    }

    public function testDeferredQueueSaveHardCrashRecoversTheUnsavedFramesFromRest(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the hard-crash test.');
        }
        $trade = static fn (int $tradeId): array => self::restTrade(
            (string) $tradeId,
            (string) (1784970100000 + $tradeId - 100),
        );
        $websocketFrame = static fn (int ...$tradeIds): array => [
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'data' => array_map(
                static fn (int $tradeId): array => $trade($tradeId) + ['count' => '1', 'seqId' => (string) $tradeId],
                $tradeIds,
            ),
        ];
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Forked child: see ignoreInheritedShutdownErrorsInForkedChild().
            self::ignoreInheritedShutdownErrorsInForkedChild();
            $public = new Task7Transport();
            $business = new Task7Transport();
            $public->responses = [
                ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
                $websocketFrame(101),
            ];
            $business->responses = Task7Transport::acknowledgements(self::businessArguments(), 'business');
            $source = $this->source(Task7RestClient::withInitialDataset(), $public, $business, anchoredTradeJunctions: true);
            $events = $source->events();
            self::assertInstanceOf(\Generator::class, $events);
            $this->acknowledgeWarmup($source, $events);
            $first = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $first);
            $source->acknowledge($first->eventId);
            // Admitted within the save interval: in memory only, like a socket buffer.
            foreach (range(102, 110) as $tradeId) {
                $public->message($websocketFrame($tradeId));
            }
            posix_kill(posix_getpid(), \SIGKILL);
            exit(93);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status), 'The child must die with the deferred frames unsaved.');
        self::assertSame(\SIGKILL, pcntl_wtermsig($status));

        // The durable state left by the crash: trade 101 acknowledged, none of the
        // nine admitted frames persisted.
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $state = $this->checkpointState();
        self::assertSame('streaming', $state['phase'] ?? null);
        self::assertSame(
            '101',
            $state['stream_frontiers']['BTCUSDT/ws/public_trade']['source_identity'] ?? null,
        );
        self::assertSame(0, $state['streaming_queue_ref']['public']['frames'] ?? 0);

        // The restart reconnects and recovers them from REST, once each.
        $restartRest = Task7RestClient::withInitialDataset();
        $restartRest->tradeRows['BTC-USDT-SWAP'] = array_map($trade, range(100, 110));
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(self::publicArguments(), 'restartPublic');
        $restartBusiness->responses = Task7Transport::acknowledgements(self::businessArguments(), 'restartBusiness');
        $restartClock = new MockClock('2026-07-25T10:00:00.000000Z');
        $restartDeterministic = new DeterministicLoop();
        $restartLoop = new Task7ScriptedLoop($restartDeterministic);
        $restartLoop->scripts = [
            static function () use ($restartClock, $restartDeterministic): void {
                $restartClock->sleep(1);
                $restartDeterministic->fireTimerInterval(1.0);
            },
        ];
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $restartClock,
            loop: $restartLoop,
            anchoredTradeJunctions: true,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $tradeIds = [];
        for ($index = 0; $index < 100 && end($tradeIds) !== '110'; ++$index) {
            if ($index > 0) {
                $resumedEvents->next();
            }
            $event = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE && $event->symbol === 'BTCUSDT') {
                $tradeIds[] = $event->payload['trade_id'] ?? null;
            }
            $resumed->acknowledge($event->eventId);
        }
        self::assertSame(array_map('strval', range(102, 110)), $tradeIds);
        $resumed->stop();
    }

    public function testJunctionPendingCheckpointRoundTripsThroughFromArray(): void
    {
        $rest = Task7RestClient::withInitialDataset();
        $rest->historyTradePages = [[
            self::restTrade('102', '1784970100002'),
            self::restTrade('101', '1784970100001'),
            self::restTrade('100', '1784970100000'),
        ]];
        $source = $this->junctionSource($rest, Task7Transport::tradeFrame(['103']));
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmupEvents($source, $events, 14);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame('101', $pending->payload['trade_id'] ?? null);

        // The durable B1 state: a REST trade pending on the REST trade stream while
        // streaming, without transition.
        $state = $this->checkpointState();
        self::assertSame('streaming', $state['phase'] ?? null);
        self::assertNull($state['pending_transition'] ?? null);
        self::assertSame('BTCUSDT/rest/public_trade', $state['pending_frontier']['stream'] ?? null);
        $roundTrip = OkxPaperLiveCheckpoint::fromArray($state)->toArray();
        self::assertSame($roundTrip, OkxPaperLiveCheckpoint::fromArray($roundTrip)->toArray());
        foreach (['phase', 'pending_event', 'pending_frontier', 'stream_frontiers', 'ordinal_state'] as $key) {
            self::assertSame(
                CanonicalJson::encode([$key => $state[$key] ?? null]),
                CanonicalJson::encode([$key => $roundTrip[$key] ?? null]),
            );
        }

        $source->stop();
        unset($events, $source);
        gc_collect_cycles();
        $reloaded = (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame($pending->eventId, $reloaded->pendingEvent?->eventId);
    }

    public function testHistoryTradePaginationCheckpointDurablyRoundTripsRetainedRows(): void
    {
        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $frontier = [
            'source_identity' => '100',
            'natural_identity' => 'okx|BTC-USDT-SWAP|public_trade|100',
            'canonical_digest' => str_repeat('b', 64),
            'overlap_digest' => str_repeat('c', 64),
        ];
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = $frontier;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784970101000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'retained_rows' => [[
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => '200',
                'px' => '100.7',
                'sz' => '1',
                'side' => 'buy',
                'source' => '0',
                'ts' => '1784970101000',
            ]],
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];

        $restored = OkxPaperLiveCheckpoint::fromArray($state);

        self::assertSame(
            $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'],
            $restored->toArray()['overlap_pagination_by_stream'][
                'BTCUSDT/rest/public_trade'
            ],
        );
    }

    public function testHistoryTradePageMergesWithoutMaterializingExpandedSuffix(): void
    {
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
        );
        $retained = [];
        for ($tradeId = 101; $tradeId <= 5_100; ++$tradeId) {
            $retained[] = OkxPaperRetainedTradeRow::compact(self::restTrade(
                (string) $tradeId,
                (string) (1784970100000 + $tradeId),
            ));
        }
        $olderPage = [];
        for ($tradeId = 100; $tradeId >= 1; --$tradeId) {
            $olderPage[] = self::restTrade(
                (string) $tradeId,
                (string) (1784970100000 + $tradeId),
            );
        }

        $merge = new \ReflectionMethod($source, 'mergeRetainedTradePage');
        $merged = $merge->invoke($source, $olderPage, $retained);

        self::assertCount(5_100, $merged);
        self::assertContainsOnly('string', $merged);
        self::assertSame('1', OkxPaperRetainedTradeRow::expand($merged[0])['tradeId']);
        self::assertSame('5100', OkxPaperRetainedTradeRow::expand($merged[5_099])['tradeId']);
    }

    public function testHistoryTradeRecoveryMaterializesOneBoundedBatchFromLargeSuffix(): void
    {
        $clock = new MockClock('2026-07-25T10:00:02.000000Z');
        $rows = [];
        for ($tradeId = 100; $tradeId < 5_700; ++$tradeId) {
            $rows[] = self::restTrade(
                (string) $tradeId,
                (string) (1784970100000 + $tradeId - 100),
            );
        }
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer(
            $clock,
        );
        $frontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade($rows[0]),
        );
        $siblingFrontier = \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier::fromEvent(
            $normalizer->recoveryTrade($rows[2_910]),
        );
        $stream = 'BTCUSDT/rest/public_trade';
        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:03.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers'][$stream] = $frontier->toArray();
        $state['stream_frontiers']['BTCUSDT/ws/public_trade'] =
            $siblingFrontier->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream'][$stream] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 1,
            'next_cursor' => '99',
            'pages_consumed' => 51,
            'pages_remaining' => 199,
            'target_frontier' => $frontier->toArray(),
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'retained_rows' => array_map(
                OkxPaperRetainedTradeRow::compact(...),
                $rows,
            ),
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => $stream,
            'stage' => 'history_trades',
        ];
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($seed);
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        ));

        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $source = $this->source(
            new Task7RestClient(),
            $public,
            $business,
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $memoryBeforeRecovery = memory_get_usage(true);
        $first = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('3011', $first->payload['trade_id'] ?? null);
        self::assertLessThan(
            16 * 1024 * 1024,
            memory_get_usage(true) - $memoryBeforeRecovery,
        );
        // One anchor (the newest trade frontier) and the suffix after it.
        self::assertCount(
            2_690,
            $this->checkpointState()['overlap_pagination_by_stream'][$stream][
                'retained_rows'
            ] ?? [],
        );
        self::assertSame(256, $source->pendingDurableBatchSize());
        for ($tradeId = 3_011; $tradeId <= 3_266; ++$tradeId) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame((string) $tradeId, $event->payload['trade_id'] ?? null);
            $source->acknowledge($event->eventId);
            $events->next();
        }
        $nextBatch = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $nextBatch);
        self::assertSame('3267', $nextBatch->payload['trade_id'] ?? null);
        self::assertSame(256, $source->pendingDurableBatchSize());
        self::assertNull($source->failureReason());
    }

    public function testHistoryTradePaginationRecoversBeyondFormerFiftyPageBound(): void
    {
        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $frontier = [
            'source_identity' => '100',
            'natural_identity' => 'okx|BTC-USDT-SWAP|public_trade|100',
            'canonical_digest' => hash('sha256', CanonicalJson::encode([
                'channel' => 'public_trade',
                'native_symbol' => 'BTC-USDT-SWAP',
                'source_fields' => [
                    'exchange_timestamp_ms' => '1784970100000',
                    'price' => '100.5',
                    'size_contracts' => '2',
                    'source' => '0',
                    'taker_side' => 'buy',
                    'trade_id' => '100',
                ],
                'venue' => 'okx',
                'exchange_timestamp' => '2026-07-25T09:01:40.000000Z',
            ])),
            // Cross-origin identity ignores the size and `source` (OKX endpoints can
            // disagree on it).
            'overlap_digest' => hash('sha256', CanonicalJson::encode([
                'channel' => 'public_trade',
                'native_symbol' => 'BTC-USDT-SWAP',
                'source_fields' => [
                    'exchange_timestamp_ms' => '1784970100000',
                    'price' => '100.5',
                    'taker_side' => 'buy',
                    'trade_id' => '100',
                ],
                'venue' => 'okx',
                'exchange_timestamp' => '2026-07-25T09:01:40.000000Z',
            ])),
        ];
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers']['BTCUSDT/ws/public_trade'] = $frontier;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784970101000',
            'pages_consumed' => 0,
            'pages_remaining' => OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
            'target_frontier' => $frontier,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'retained_rows' => [self::restTrade('200', '1784970101000')],
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/public_trade',
            'stage' => 'history_trades',
        ];
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($seed);
        file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        );

        $rest = new Task7RestClient();
        for ($tradeId = 150; $tradeId >= 100; --$tradeId) {
            $rest->historyTradePages[] = [self::restTrade(
                (string) $tradeId,
                (string) (1784970100000 + $tradeId - 100),
            )];
        }
        $observedPublicConnectTransition = null;
        $public = new Task7Transport(
            beforeAction: function (string $operation) use (
                &$observedPublicConnectTransition,
            ): void {
                if ($operation === 'connect') {
                    $observedPublicConnectTransition =
                        $this->checkpointState()['pending_transition'];
                }
            },
        );
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            clock: new MockClock('2026-07-25T10:00:02.000000Z'),
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $first = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('101', $first->payload['trade_id'] ?? null);
        self::assertCount(51, $rest->calls);
        self::assertSame(
            ['historyTrades', ['BTC-USDT-SWAP', 2, '1784970101000', 100]],
            $rest->calls[0],
        );
        self::assertSame(
            ['historyTrades', ['BTC-USDT-SWAP', 1, '101', 100]],
            $rest->calls[50],
        );
        self::assertSame([
            'kind' => 'transport_connect',
            'stage' => 'connect',
            'stream' => 'public',
            'symbol' => null,
        ], $observedPublicConnectTransition);
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $public->connections);
        self::assertSame([OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI], $business->connections);
        $source->acknowledge($first->eventId);
        $history = $this->checkpointState()['acknowledged_identity_history'][
            'BTCUSDT/public_trade'
        ] ?? [];
        $history = array_map(OkxPaperAcknowledgedIdentityEntry::expand(...), $history);
        self::assertIsString(
            array_column($history, 2, 0)[hash(
                'sha256',
                'okx|BTC-USDT-SWAP|public_trade|101',
            )] ?? null,
        );
        self::assertNull($source->failureReason());
    }

    public function testForwardTradeRecoveryEmitsEachPageBeforeFetchingTheNext(): void
    {
        // A gap recovered page by page from the frontier (run8: ~27,000 BTC trades in
        // the US session): contiguous, no duplicate, one page retained.
        $rest = $this->seedForwardTradeRecovery('600', '200');
        [$source, $events] = $this->resumedForwardRecoverySource($rest);
        $tradeIds = [];
        $maxRetained = 0;
        for ($index = 0; $index < 1_000; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel !== PaperMarketDataChannel::PUBLIC_TRADE || $event->symbol !== 'BTCUSDT') {
                break;
            }
            $tradeIds[] = (int) ($event->payload['trade_id'] ?? 0);
            $source->acknowledge($event->eventId);
            $pagination = $this->checkpointState()['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'] ?? null;
            $maxRetained = max($maxRetained, \count($pagination['retained_rows'] ?? []));
            if (end($tradeIds) === 600) {
                // The page reaching forward_through (600) was the last one: the
                // recovery then completes like a backward one (not seeded here).
                break;
            }
            $events->next();
        }
        self::assertSame(range(101, 600), $tradeIds);
        self::assertLessThanOrEqual(100, $maxRetained);
        $cursors = array_column(array_column(array_filter(
            $rest->calls,
            static fn (array $call): bool => $call[0] === 'historyTrades',
        ), 1), 2);
        // Each page starts at the acknowledged frontier: 100, 199, 298...
        self::assertSame(['200', '299', '398', '497', '596', '601'], $cursors);
    }

    public function testForwardTradeRecoveryRestartsFromTheAcknowledgedFrontier(): void
    {
        // A crash after a page was fetched but before it was acknowledged: the saved
        // cursor is ahead, the frontier is not. The next page starts at the frontier.
        $rest = $this->seedForwardTradeRecovery('600', '9999');
        [$source, $events] = $this->resumedForwardRecoverySource($rest);
        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('101', $first->payload['trade_id'] ?? null);
        self::assertSame(['historyTrades', ['BTC-USDT-SWAP', 1, '200', 100]], $rest->calls[0]);
        $source->acknowledge($first->eventId);
    }

    public function testForwardTargetMovesUpToTheFirstQueuedTradeOfTheConnection(): void
    {
        // Production (run9, 15:35): OKX closed the public socket paused during a long
        // recovery. The new connection's first trade (1,000) lies beyond the target
        // of the earlier attempt (600): the recovery goes on up to just before it.
        $rest = $this->seedForwardTradeRecovery('600', '200', 1_199);
        [$source, $events] = $this->resumedForwardRecoverySource($rest, [Task7Transport::tradeFrame(['1000'])]);
        $tradeIds = [];
        while (end($tradeIds) !== '999') {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $batch = $source->pendingDurableBatchSize();
            for ($index = 0; $index < $batch; ++$index) {
                if ($index > 0) {
                    $events->next();
                    $event = $events->current();
                    self::assertInstanceOf(PaperMarketEvent::class, $event);
                }
                $tradeIds[] = $event->payload['trade_id'] ?? null;
                $source->acknowledge($event->eventId);
            }
            if (end($tradeIds) !== '999') {
                $events->next();
            }
        }
        self::assertSame(array_map('strval', range(101, 999)), $tradeIds);
        self::assertSame(
            '999',
            $this->checkpointState()['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade']['forward_through'] ?? null,
        );
    }

    public function testReconnectTradeGapBeyondTheForwardThresholdPagesForwardFromTheFrontier(): void
    {
        // Recent trades 2,300..2,799 do not reach the frontier (100): more than
        // FORWARD_RECOVERY_MIN_TRADES ids apart, the gap is paged forward from it.
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $rows = array_map(
            static fn (int $tradeId): array => self::restTrade((string) $tradeId, (string) (1784970100000 + $tradeId - 100)),
            range(2_300, 2_799),
        );
        $normalizer = new \App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer($clock);
        $frontier = OkxPaperStreamFrontier::fromEvent($normalizer->recoveryTrade(self::restTrade('100', '1784970100000')));
        $stream = 'BTCUSDT/ws/public_trade';
        $this->seedTradeRecoveryCheckpoint($stream, [$stream => $frontier]);
        $rest = new Task7RestClient();
        $rest->tradeRows['BTC-USDT-SWAP'] = $rows;
        self::servesHistoryTradesById($rest, 2_799);
        $source = $this->reconnectSource($rest, $clock);
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $tradeIds = [];
        $forwardThrough = null;
        while (end($tradeIds) !== '2799') {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $batch = $source->pendingDurableBatchSize();
            for ($index = 0; $index < $batch; ++$index) {
                if ($index > 0) {
                    $events->next();
                    $event = $events->current();
                    self::assertInstanceOf(PaperMarketEvent::class, $event);
                }
                self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $event->channel);
                $tradeIds[] = $event->payload['trade_id'] ?? null;
                $source->acknowledge($event->eventId);
            }
            $forwardThrough ??= $this->checkpointState()['overlap_pagination_by_stream'][$stream]['forward_through'] ?? null;
            if (end($tradeIds) !== '2799') {
                $events->next();
            }
        }
        self::assertSame('2799', $forwardThrough);
        self::assertSame(array_map('strval', range(101, 2_799)), $tradeIds);
        $history = array_values(array_filter($rest->calls, static fn (array $call): bool => $call[0] === 'historyTrades'));
        self::assertSame(['BTC-USDT-SWAP', 1, '200', 100], $history[0][1]);
    }

    /**
     * Bench (skipped unless OKX_PAPER_RECOVERY_BENCH=<trades>): a forward recovery of
     * that many trades (30 min of BTC in the US session: ~160,000), consumed like the
     * capture (durable batches), REST through the production history limit (16
     * requests per 2 s sliding window). Prints the duration and the request rate.
     */
    public function testForwardTradeRecoveryBench(): void
    {
        $trades = (int) getenv('OKX_PAPER_RECOVERY_BENCH');
        if ($trades <= 0) {
            self::markTestSkipped('Set OKX_PAPER_RECOVERY_BENCH to a number of trades.');
        }
        $limiter = (new RateLimiterFactory(
            ['id' => 'okx_paper_history', 'policy' => 'sliding_window', 'limit' => 16, 'interval' => '2 seconds'],
            new InMemoryStorage(),
        ))->create('public');
        $through = 100 + $trades;
        $rest = $this->seedForwardTradeRecovery((string) $through, '200', $through + 99, $limiter);
        [$source, $events] = $this->resumedForwardRecoverySource($rest);
        $started = microtime(true);
        $emitted = 0;
        $maxRetained = 0;
        $requestTimes = [];
        $rest->beforeHistoryTrades = (static function (\Closure $serve) use (&$requestTimes): \Closure {
            return static function () use ($serve, &$requestTimes): void {
                $serve();
                $requestTimes[] = microtime(true);
            };
        })($rest->beforeHistoryTrades);
        $last = 0;
        while ($last < $through) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $batch = $source->pendingDurableBatchSize();
            for ($index = 0; $index < $batch; ++$index) {
                if ($index > 0) {
                    $events->next();
                    $event = $events->current();
                    self::assertInstanceOf(PaperMarketEvent::class, $event);
                }
                $tradeId = (int) ($event->payload['trade_id'] ?? 0);
                self::assertSame($last === 0 ? 101 : $last + 1, $tradeId);
                $last = $tradeId;
                ++$emitted;
                $source->acknowledge($event->eventId);
            }
            $pagination = $this->checkpointState()['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'] ?? null;
            $maxRetained = max($maxRetained, \count($pagination['retained_rows'] ?? []));
            if ($last < $through) {
                $events->next();
            }
        }
        $elapsed = microtime(true) - $started;
        $peakWindow = 0;
        foreach ($requestTimes as $index => $at) {
            $inWindow = 0;
            for ($other = $index; $other < \count($requestTimes) && $requestTimes[$other] < $at + 2.0; ++$other) {
                ++$inWindow;
            }
            $peakWindow = max($peakWindow, $inWindow);
        }
        fwrite(\STDERR, sprintf(
            "forward recovery: %d trades in %.1f s (%.0f trades/s), %d requests (%.2f/s, max %d per 2 s), max retained rows %d, peak memory %.0f MB\n",
            $emitted,
            $elapsed,
            $emitted / max(0.001, $elapsed),
            \count($requestTimes),
            \count($requestTimes) / max(0.001, $elapsed),
            $peakWindow,
            $maxRetained,
            memory_get_peak_usage(true) / 1_048_576,
        ));
        self::assertLessThanOrEqual(100, $maxRetained);
        self::assertLessThanOrEqual(16, $peakWindow);
    }

    /**
     * A checkpoint in a forward BTC trade recovery from trade 100; REST serves the ids
     * 1..$lastTradeId, through $limiter if given (as OkxPaperPublicRateLimiter does).
     */
    private function seedForwardTradeRecovery(
        string $forwardThrough,
        string $nextCursor,
        int $lastTradeId = 799,
        ?LimiterInterface $limiter = null,
    ): Task7RestClient {
        $rest = new Task7RestClient();
        // The frontier is trade 100 as REST serves it.
        $frontier = OkxPaperStreamFrontier::fromArray([
            'source_identity' => '100',
            'natural_identity' => 'okx|BTC-USDT-SWAP|public_trade|100',
            'canonical_digest' => hash('sha256', CanonicalJson::encode([
                'channel' => 'public_trade',
                'native_symbol' => 'BTC-USDT-SWAP',
                'source_fields' => [
                    'exchange_timestamp_ms' => '1784970100000',
                    'price' => '100.5',
                    'size_contracts' => '2',
                    'source' => '0',
                    'taker_side' => 'buy',
                    'trade_id' => '100',
                ],
                'venue' => 'okx',
                'exchange_timestamp' => '2026-07-25T09:01:40.000000Z',
            ])),
            'overlap_digest' => hash('sha256', CanonicalJson::encode([
                'channel' => 'public_trade',
                'native_symbol' => 'BTC-USDT-SWAP',
                'source_fields' => [
                    'exchange_timestamp_ms' => '1784970100000',
                    'price' => '100.5',
                    'taker_side' => 'buy',
                    'trade_id' => '100',
                ],
                'venue' => 'okx',
                'exchange_timestamp' => '2026-07-25T09:01:40.000000Z',
            ])),
        ]);
        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers']['BTCUSDT/ws/public_trade'] = $frontier->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:15:00.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 1,
            'next_cursor' => $nextCursor,
            'pages_consumed' => 0,
            'pages_remaining' => OkxPaperLivePolicy::MAX_FORWARD_RECOVERY_PAGES,
            'target_frontier' => $frontier->toArray(),
            'deadline_at' => '2026-07-25T10:15:00.000000Z',
            'retained_rows' => [],
            'forward_through' => $forwardThrough,
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/public_trade',
            'stage' => 'history_trades',
        ];
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($seed);
        file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        );
        self::servesHistoryTradesById($rest, $lastTradeId, $limiter);

        return $rest;
    }

    /** history-trades by id: the 100 ids below the cursor (up to $lastTradeId), newest first. */
    private static function servesHistoryTradesById(
        Task7RestClient $rest,
        int $lastTradeId,
        ?LimiterInterface $limiter = null,
    ): void {
        $rest->beforeHistoryTrades = static function () use ($rest, $lastTradeId, $limiter): void {
            $limiter?->reserve(1, 2.0)->wait();
            $cursor = (int) $rest->calls[array_key_last($rest->calls)][1][2];
            $page = [];
            for ($tradeId = min($cursor - 1, $lastTradeId); $tradeId >= max(1, $cursor - 100); --$tradeId) {
                $page[] = self::restTrade((string) $tradeId, (string) (1784970100000 + $tradeId - 100));
            }
            $rest->historyTradePages = [$page];
            // Only the last call is ever read: keep the call log bounded in the bench.
            if (\count($rest->calls) > 1_000) {
                $rest->calls = \array_slice($rest->calls, -10);
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $publicFrames queued after the subscriptions
     * @return array{OkxPaperPublicLiveSource, \Generator}
     */
    private function resumedForwardRecoverySource(Task7RestClient $rest, array $publicFrames = []): array
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'restartPublic'),
            ...$publicFrames,
        ];
        $business->responses = Task7Transport::acknowledgements(self::businessArguments(), 'restartBusiness');
        $source = $this->source(
            $rest,
            $public,
            $business,
            clock: new MockClock('2026-07-25T10:00:02.000000Z'),
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        return [$source, $events];
    }

    public function testHistoryCandlePaginationRestartCallsSavedCursorAndEmitsDurableSuffix(): void
    {
        $anchor = [
            '1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1',
        ];
        $retained = [
            '1784970120000', '102', '103', '101', '102.5', '12', '1', '1200', '1',
        ];
        $frontier = [
            'source_identity' => '1m|1784970000000',
            'natural_identity' => 'okx|BTC-USDT-SWAP|candle_1m|1m|1784970000000',
            'canonical_digest' => hash('sha256', CanonicalJson::encode([
                'channel' => 'candle_1m',
                'native_symbol' => 'BTC-USDT-SWAP',
                'source_fields' => [
                    'bar' => '1m',
                    'close' => '100.5',
                    'confirmed' => true,
                    'high' => '101',
                    'low' => '99',
                    'open' => '100',
                    'opening_timestamp_ms' => '1784970000000',
                    'volume_base' => '1',
                    'volume_contracts' => '10',
                    'volume_quote' => '1000',
                ],
                'venue' => 'okx',
                'exchange_timestamp' => '2026-07-25T09:00:00.000000Z',
            ])),
        ];
        $frontier['overlap_digest'] = $frontier['canonical_digest'];
        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers']['BTCUSDT/rest/candle_1m'] = $frontier;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'] = [
            'endpoint' => 'history_candles',
            'pagination_type' => null,
            'next_cursor' => '1784970120000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'retained_rows' => [$retained],
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/candle_1m',
            'stage' => 'history_candles',
        ];
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($seed);
        file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        );

        $rest = new Task7RestClient();
        $rest->historyCandlePages = [[
            ['1784970060000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
            $anchor,
        ]];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            clock: new MockClock('2026-07-25T10:00:02.000000Z'),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        self::assertSame('1784970060000', $first->exchangeTimestamp->format('Uv'));
        $source->acknowledge($first->eventId);
        $events->next();
        $second = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $second);
        self::assertSame('1784970120000', $second->exchangeTimestamp->format('Uv'));
        self::assertSame(
            [['historyCandles', ['BTC-USDT-SWAP', '1m', '1784970120000', 300]]],
            $rest->calls,
        );
    }

    #[DataProvider('terminalSavedPaginationProvider')]
    public function testSavedPaginationFailsTerminallyWithoutGrantingFreshBudget(
        string $case,
        string $expectedReason,
    ): void {
        $frontier = [
            'source_identity' => '100',
            'natural_identity' => 'okx|BTC-USDT-SWAP|public_trade|100',
            'canonical_digest' => hash('sha256', CanonicalJson::encode([
                'channel' => 'public_trade',
                'native_symbol' => 'BTC-USDT-SWAP',
                'source_fields' => [
                    'exchange_timestamp_ms' => '1784970100000',
                    'price' => '100.5',
                    'size_contracts' => '2',
                    'source' => '0',
                    'taker_side' => 'buy',
                    'trade_id' => '100',
                ],
                'venue' => 'okx',
                'exchange_timestamp' => '2026-07-25T09:01:40.000000Z',
            ])),
            'overlap_digest' => hash('sha256', CanonicalJson::encode([
                'channel' => 'public_trade',
                'native_symbol' => 'BTC-USDT-SWAP',
                'source_fields' => [
                    'exchange_timestamp_ms' => '1784970100000',
                    'price' => '100.5',
                    'source' => '0',
                    'taker_side' => 'buy',
                    'trade_id' => '100',
                ],
                'venue' => 'okx',
                'exchange_timestamp' => '2026-07-25T09:01:40.000000Z',
            ])),
        ];
        $deadline = $case === 'deadline'
            ? '2026-07-25T10:00:00.000000Z'
            : '2026-07-25T10:00:10.000000Z';
        $retainedRows = [self::restTrade('200', '1784970101000')];
        if ($case === 'identity') {
            $conflicting = self::restTrade('100', '1784970100000');
            $conflicting['px'] = '999.9';
            $retainedRows = [$conflicting, ...$retainedRows];
        }
        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = $frontier;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => $case === 'budget' ? 1 : 2,
            'next_cursor' => $case === 'budget' ? '200' : '1784970101000',
            'pages_consumed' => $case === 'budget' ? 10 : 0,
            'pages_remaining' => $case === 'budget' ? 0 : 10,
            'target_frontier' => $frontier,
            'deadline_at' => $deadline,
            'retained_rows' => $retainedRows,
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($seed);
        file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        );

        $rest = new Task7RestClient();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            clock: new MockClock('2026-07-25T10:00:00.000000Z'),
        );

        try {
            $events = $source->events();
            self::assertInstanceOf(\Generator::class, $events);
            $events->current();
            self::fail('Saved terminal pagination state cannot receive another page.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame($expectedReason, $exception->getMessage());
        }

        self::assertSame([], $rest->calls);
        self::assertSame('failed', $this->checkpointState()['phase']);
        self::assertSame($expectedReason, $source->failureReason());
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
    }

    /** @return iterable<string, array{string, string}> */
    public static function terminalSavedPaginationProvider(): iterable
    {
        yield 'saved page budget is exhausted' => [
            'budget',
            'market_data_gap_unresolved',
        ];
        yield 'saved absolute deadline is exact' => [
            'deadline',
            'market_data_gap_unresolved',
        ];
        yield 'saved overlap digest conflicts' => [
            'identity',
            'market_event_identity_conflict',
        ];
    }

    public function testReconnectResubscribesTheBooksOfASymbolWhoseReserveReachedThePauseThreshold(): void
    {
        [, , $public, , , , $logger] = $this->recoverWithEthBookSnapshotDuringBtcWait(saturateEthReserve: true);

        $books = ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'];
        self::assertContains(['op' => 'unsubscribe', 'args' => [$books]], $public->sent);
        self::assertContains(['op' => 'subscribe', 'args' => [$books]], $public->sent);
        $discarded = $logger->first('okx_paper_public_book_snapshot_discarded');
        self::assertSame('ETHUSDT', $discarded['symbol']);
        self::assertSame('BTCUSDT', $discarded['while_recovering']);
        self::assertGreaterThanOrEqual(OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES, $discarded['queued_book_frames']);
        $resubscribe = $logger->first('okx_paper_public_book_resubscribe');
        self::assertSame('ETHUSDT', $resubscribe['symbol']);
        self::assertTrue($resubscribe['snapshot_discarded']);
        self::assertNotContains('okx_paper_public_book_authority_unavailable', $logger->messages());
    }

    public function testPendingSymbolTakesItsBookAuthorityFromItsReserveAfterALongRecovery(): void
    {
        // Production (runDrain1): BTC waited for its authority under a second, which
        // dropped ETH's websocket snapshot; BTC's trade recovery then took 2 min 47 s
        // while BTC book deltas filled the queue, and ETH's resubscription snapshot
        // stayed behind the TCP backlog. ETH's snapshot is now kept in reserve.
        $beforeEthRestBook = static function (FakeOkxPaperPublicWebSocketTransport $public, MockClock $clock): void {
            $clock->sleep(167);
            foreach ([9006, 9007, 9008] as $sequence) {
                $eth = Task7Transport::bookFrame((string) $sequence, (string) ($sequence - 1), '6');
                $eth['arg']['instId'] = 'ETH-USDT-SWAP';
                $public->message($eth, attempt: 1);
                $public->message(
                    Task7Transport::bookFrame((string) ($sequence - 1), (string) ($sequence - 2), '6'),
                    attempt: 1,
                );
            }
        };
        [$source, $events, $public, , , , $logger] = $this->recoverWithEthBookSnapshotDuringBtcWait(
            beforeEthRestBook: $beforeEthRestBook,
        );

        $books = ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'];
        self::assertNotContains(['op' => 'unsubscribe', 'args' => [$books]], $public->sent);
        $reserved = $logger->first('okx_paper_public_book_snapshot_reserved');
        self::assertSame('ETHUSDT', $reserved['symbol']);
        self::assertSame('BTCUSDT', $reserved['while_recovering']);
        foreach ([
            'okx_paper_public_book_snapshot_discarded',
            'okx_paper_public_book_resubscribe',
            'okx_paper_public_book_authority_unavailable',
        ] as $message) {
            self::assertNotContains($message, $logger->messages());
        }
        // ETH had its authority at once: no wait was logged for it.
        foreach ($logger->records as $record) {
            if ($record['message'] === 'okx_paper_public_book_authority_wait') {
                self::assertSame('BTCUSDT', $record['context']['symbol']);
            }
        }
        // The queue is emitted in order: the reserve (9005) and the deltas after it,
        // BTC's snapshot (9004) and deltas, each chain intact.
        $emitted = [];
        for ($index = 0; $index < 8; ++$index) {
            $events->next();
            $book = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $book);
            $emitted[] = [$book->symbol, $book->payload['source_seq_id'] ?? null];
            $source->acknowledge($book->eventId);
        }
        self::assertSame([
            ['ETHUSDT', '9005'],
            ['BTCUSDT', '9004'],
            ['ETHUSDT', '9006'],
            ['BTCUSDT', '9005'],
            ['ETHUSDT', '9007'],
            ['BTCUSDT', '9006'],
            ['ETHUSDT', '9008'],
            ['BTCUSDT', '9007'],
        ], $emitted);
        self::assertSame('streaming', $this->checkpointState()['phase']);
    }

    public function testHealthyStopRequestedDuringReconnectRecoveryCompletesAfterIt(): void
    {
        [$source, $events, $public, $business, $deterministic, $clock, $logger] =
            $this->recoverWithEthBookSnapshotDuringBtcWait(
                static fn (OkxPaperPublicLiveSource $source) => $source->requestHealthyOperatorStop(),
            );
        self::assertContains('okx_paper_public_healthy_stop_deferred', $logger->messages());
        self::assertFalse($this->checkpointState()['healthy_stop']['requested']);

        // The stop stays deferred while the recovered connection proves itself
        // (12 accepted events and 30 s), then proceeds as a normal healthy stop.
        // Queue order: ETH's reserve (9005) arrived before BTC's snapshot (9004).
        foreach (['9005', '9004'] as $sequence) {
            $events->next();
            $retained = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $retained);
            self::assertSame($sequence, $retained->payload['source_seq_id'] ?? null);
            $source->acknowledge($retained->eventId);
        }
        $recovered = $this->checkpointState();
        self::assertSame('streaming', $recovered['phase']);
        self::assertFalse($recovered['healthy_stop']['requested']);
        self::assertSame(1, $recovered['reconnect']['attempt']);
        for ($offset = 1; $offset <= 10; ++$offset) {
            $public->message(
                Task7Transport::bookFrame((string) (9004 + $offset), (string) (9003 + $offset), (string) (5 + $offset)),
                attempt: 1,
            );
            $events->next();
            $accepted = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $accepted);
            self::assertSame('BTCUSDT', $accepted->symbol);
            $source->acknowledge($accepted->eventId);
        }
        self::assertSame(12, $this->checkpointState()['reconnect']['accepted_events']);
        self::assertSame('streaming', $this->checkpointState()['phase']);
        $clock->sleep(30);
        $deterministic->fireTimerInterval(30.0);
        self::assertSame(0, $this->checkpointState()['reconnect']['attempt']);

        $public->message('pong', attempt: 1);
        $business->message('pong', attempt: 1);
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $events->next();
            $stopped = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $stopped);
            self::assertSame($symbol, $stopped->symbol);
            self::assertSame('stopped', $stopped->payload['state'] ?? null);
            $source->acknowledge($stopped->eventId);
        }
        $events->next();
        self::assertFalse($events->valid());
        self::assertTrue($source->isComplete());
        self::assertSame('complete', $this->checkpointState()['phase']);
        self::assertContains('okx_paper_public_healthy_stop_resumed', $logger->messages());
    }

    /**
     * A paired reconnect in which ETH's websocket books snapshot arrives while BTC
     * still waits for its own book authority: it is kept in reserve, or dropped
     * when the reserve reaches the pause threshold ($saturateEthReserve). Runs
     * until ETH's reconnect boundary is acknowledged.
     *
     * @param (\Closure(OkxPaperPublicLiveSource): mixed)|null $duringBtcBookWait
     * @param (\Closure(FakeOkxPaperPublicWebSocketTransport, MockClock): void)|null $beforeEthRestBook
     * @return array{OkxPaperPublicLiveSource, \Generator, FakeOkxPaperPublicWebSocketTransport, FakeOkxPaperPublicWebSocketTransport, DeterministicLoop, MockClock, OkxRecordingLogger}
     */
    private function recoverWithEthBookSnapshotDuringBtcWait(
        ?\Closure $duringBtcBookWait = null,
        bool $saturateEthReserve = false,
        ?\Closure $beforeEthRestBook = null,
    ): array {
        $logger = new OkxRecordingLogger();
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $initialBtcTrade = $rest->tradeRows['BTC-USDT-SWAP'][0];
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $ethApplied = Task7Transport::bookFrame('9003', '9002', '4');
        $ethApplied['arg']['instId'] = 'ETH-USDT-SWAP';
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static function () use ($public, $ethApplied): void {
                $public->message(Task7Transport::bookFrame('9002', '9001', '4'));
                $public->message($ethApplied);
                $public->message(Task7Transport::tradeFrame(['110']));
            },
        ];
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            logger: $logger,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        foreach ([
            ['BTCUSDT', '9002'],
            ['ETHUSDT', '9003'],
        ] as $position => [$symbol, $sequence]) {
            $book = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $book);
            self::assertSame($symbol, $book->symbol);
            self::assertSame($sequence, $book->payload['source_seq_id'] ?? null);
            if ($position === 0) {
                self::assertSame(1, $source->pendingDurableBatchSize());
            }
            $source->acknowledge($book->eventId);
            if ($position === 0) {
                $events->next();
            }
        }
        $events->next();
        $initialTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $initialTrade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $initialTrade->channel);
        self::assertSame('110', $initialTrade->payload['trade_id'] ?? null);
        $source->acknowledge($initialTrade->eventId);
        $public->disconnect();

        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9003',
        ]];
        $rest->bookRows['ETH-USDT-SWAP'] = [[
            'asks' => [['202', '2', '0', '1']],
            'bids' => [['201', '3', '0', '2']],
            'ts' => '1784970302000',
            'seqId' => 9004,
        ]];
        $rest->tradeRows['BTC-USDT-SWAP'] = [
            $initialBtcTrade,
            ...Task7Transport::tradeFrame(['110', '111'])['data'],
        ];
        if ($beforeEthRestBook !== null) {
            $rest->beforeOrderBook = static function () use ($rest, $public, $clock, $beforeEthRestBook): void {
                if (($rest->calls[\count($rest->calls) - 1][1][0] ?? null) === 'ETH-USDT-SWAP') {
                    $beforeEthRestBook($public, $clock);
                }
            };
        }
        $loop->scripts = [
            static fn () => $public->open(attempt: 1),
            static fn () => $business->open(attempt: 1),
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'publicReconnect',
                ) as $acknowledgement) {
                    $public->message($acknowledgement, attempt: 1);
                }
            },
            static function () use ($business, $public): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'businessReconnect',
                ) as $acknowledgement) {
                    $business->message($acknowledgement, attempt: 1);
                }
                for ($index = 0; $index < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES; ++$index) {
                    $public->message(Task7Transport::tradeFrame(['110']), attempt: 1);
                }
            },
        ];
        $clock->sleep(1);
        $deterministic->fireTimerInterval(1.0);
        self::assertCount(2, $public->connections);
        self::assertCount(
            1,
            $business->connections,
            'Business starts only after asynchronous Public open.',
        );
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);

        $events->next();
        self::assertSame(1, $public->pauseCount);
        $btcReconnecting = $events->current();
        self::assertCount(2, $business->connections);
        self::assertInstanceOf(PaperMarketEvent::class, $btcReconnecting);
        self::assertSame('BTCUSDT', $btcReconnecting->symbol);
        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $btcReconnecting->channel);
        self::assertSame('reconnecting', $btcReconnecting->payload['state'] ?? null);
        $source->acknowledge($btcReconnecting->eventId);
        $resumeCount = 0;
        $public->afterResume = static function () use (
            $public,
            $source,
            $duringBtcBookWait,
            $saturateEthReserve,
            &$resumeCount,
        ): void {
            ++$resumeCount;
            if ($resumeCount === 1) {
                ($duringBtcBookWait ?? static function (): void {})($source);
                // ETH's websocket snapshot arrives while BTC still waits for its
                // authority: it is kept in reserve for ETH's own recovery.
                $ethSnapshot = Task7Transport::bookFrame('9005', '-1', '5');
                $ethSnapshot['action'] = 'snapshot';
                $ethSnapshot['arg']['instId'] = 'ETH-USDT-SWAP';
                $ethSnapshot['data'][0]['asks'] = [['202', '2', '0', '1']];
                $ethSnapshot['data'][0]['bids'][] = ['201', '3', '0', '2'];
                $public->message($ethSnapshot, attempt: 1);
                // Unless its deltas bring the queue to the pause threshold.
                for ($sequence = 9006; $saturateEthReserve && $sequence < 9006 + OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES; ++$sequence) {
                    $delta = Task7Transport::bookFrame((string) $sequence, (string) ($sequence - 1), '5');
                    $delta['arg']['instId'] = 'ETH-USDT-SWAP';
                    $public->message($delta, attempt: 1);
                }
            }
            if ($resumeCount <= 3) {
                for ($index = 0; $index < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES; ++$index) {
                    $public->message(Task7Transport::tradeFrame(['110']), attempt: 1);
                }

                return;
            }
            $snapshot = Task7Transport::bookFrame('9004', '-1', '5');
            $snapshot['action'] = 'snapshot';
            $snapshot['data'][0]['asks'] = [['102', '2', '0', '1']];
            $snapshot['data'][0]['bids'][] = ['101', '3', '0', '2'];
            $public->message($snapshot, attempt: 1);
        };
        $events->next();
        $btcReplacement = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcReplacement);
        self::assertSame('BTCUSDT', $btcReplacement->symbol);
        self::assertSame('rest_resync_snapshot', $btcReplacement->payload['origin'] ?? null);
        $reconnectCallOffset = count(Task7RestClient::expectedInitialCalls())
            + count(Task7RestClient::expectedInitialBridgeCalls());
        self::assertSame(
            ['orderBook', ['BTC-USDT-SWAP', 400]],
            $rest->calls[$reconnectCallOffset] ?? null,
            'Book overlap must be captured before slower historical recovery.',
        );
        self::assertSame('9003', $btcReplacement->payload['source_seq_id'] ?? null);
        self::assertSame(2, $btcReplacement->payload['source_epoch'] ?? null);
        self::assertSame(4, $public->resumeCount);
        $source->acknowledge($btcReplacement->eventId);
        $events->next();
        $btcRecoveredTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcRecoveredTrade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $btcRecoveredTrade->channel);
        self::assertSame('111', $btcRecoveredTrade->payload['trade_id'] ?? null);
        self::assertSame('rest_recovery', $btcRecoveredTrade->payload['origin'] ?? null);
        $source->acknowledge($btcRecoveredTrade->eventId);
        $events->next();
        $btcBoundary = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcBoundary);
        self::assertSame('reconnect', $btcBoundary->payload['reason'] ?? null);
        $source->acknowledge($btcBoundary->eventId);

        $events->next();
        $ethReconnecting = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethReconnecting);
        self::assertSame('ETHUSDT', $ethReconnecting->symbol);
        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $ethReconnecting->channel);
        self::assertSame('reconnecting', $ethReconnecting->payload['state'] ?? null);
        $source->acknowledge($ethReconnecting->eventId);
        // Without a reserve, ETH's recovery resubscribes the books channel at once,
        // and OKX answers with a full snapshot.
        $public->afterSend = static function (array $message) use ($public): void {
            if (($message['op'] ?? null) !== 'subscribe'
                || ($message['args'][0]['channel'] ?? null) !== 'books'
            ) {
                return;
            }
            foreach (['unsubscribe', 'subscribe'] as $event) {
                $public->message([
                    'event' => $event,
                    'arg' => ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'],
                    'connId' => 'resubscribe1',
                ], attempt: 1);
            }
            $snapshot = Task7Transport::bookFrame('9005', '-1', '5');
            $snapshot['action'] = 'snapshot';
            $snapshot['arg']['instId'] = 'ETH-USDT-SWAP';
            $snapshot['data'][0]['asks'] = [['202', '2', '0', '1']];
            $snapshot['data'][0]['bids'][] = ['201', '3', '0', '2'];
            $public->message($snapshot, attempt: 1);
        };
        $events->next();
        $ethReplacement = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethReplacement);
        self::assertSame('ETHUSDT', $ethReplacement->symbol);
        self::assertSame('rest_resync_snapshot', $ethReplacement->payload['origin'] ?? null);
        self::assertSame('9004', $ethReplacement->payload['source_seq_id'] ?? null);
        self::assertSame(2, $ethReplacement->payload['source_epoch'] ?? null);
        $source->acknowledge($ethReplacement->eventId);
        $events->next();
        $ethBoundary = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethBoundary);
        self::assertSame('reconnect', $ethBoundary->payload['reason'] ?? null);
        $source->acknowledge($ethBoundary->eventId);

        return [$source, $events, $public, $business, $deterministic, $clock, $logger];
    }

    public function testReconnectRecoversBothBooksBeforeResumingQueuedWebSocketFrames(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $initialBtcTrade = $rest->tradeRows['BTC-USDT-SWAP'][0];
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $ethApplied = Task7Transport::bookFrame('9003', '9002', '4');
        $ethApplied['arg']['instId'] = 'ETH-USDT-SWAP';
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static function () use ($public, $ethApplied): void {
                $public->message(Task7Transport::bookFrame('9002', '9001', '4'));
                $public->message($ethApplied);
                $public->message(Task7Transport::tradeFrame(['110']));
            },
        ];
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        foreach ([
            ['BTCUSDT', '9002'],
            ['ETHUSDT', '9003'],
        ] as $position => [$symbol, $sequence]) {
            $book = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $book);
            self::assertSame($symbol, $book->symbol);
            self::assertSame($sequence, $book->payload['source_seq_id'] ?? null);
            if ($position === 0) {
                self::assertSame(1, $source->pendingDurableBatchSize());
            }
            $source->acknowledge($book->eventId);
            if ($position === 0) {
                $events->next();
            }
        }
        $events->next();
        $initialTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $initialTrade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $initialTrade->channel);
        self::assertSame('110', $initialTrade->payload['trade_id'] ?? null);
        $source->acknowledge($initialTrade->eventId);
        $public->disconnect();

        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9003',
        ]];
        $rest->bookRows['ETH-USDT-SWAP'] = [[
            'asks' => [['202', '2', '0', '1']],
            'bids' => [['201', '3', '0', '2']],
            'ts' => '1784970302000',
            'seqId' => 9004,
        ]];
        $rest->tradeRows['BTC-USDT-SWAP'] = [
            $initialBtcTrade,
            ...Task7Transport::tradeFrame(['110', '111'])['data'],
        ];
        $loop->scripts = [
            static fn () => $public->open(attempt: 1),
            static fn () => $business->open(attempt: 1),
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'publicReconnect',
                ) as $acknowledgement) {
                    $public->message($acknowledgement, attempt: 1);
                }
            },
            static function () use ($business, $public): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'businessReconnect',
                ) as $acknowledgement) {
                    $business->message($acknowledgement, attempt: 1);
                }
                for ($index = 0; $index < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES; ++$index) {
                    $public->message(Task7Transport::tradeFrame(['110']), attempt: 1);
                }
            },
        ];
        $clock->sleep(1);
        $deterministic->fireTimerInterval(1.0);
        self::assertCount(2, $public->connections);
        self::assertCount(
            1,
            $business->connections,
            'Business starts only after asynchronous Public open.',
        );
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);

        $events->next();
        self::assertSame(1, $public->pauseCount);
        $btcReconnecting = $events->current();
        self::assertCount(2, $business->connections);
        self::assertInstanceOf(PaperMarketEvent::class, $btcReconnecting);
        self::assertSame('BTCUSDT', $btcReconnecting->symbol);
        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $btcReconnecting->channel);
        self::assertSame('reconnecting', $btcReconnecting->payload['state'] ?? null);
        $source->acknowledge($btcReconnecting->eventId);
        $resumeCount = 0;
        $public->afterResume = static function () use ($public, &$resumeCount): void {
            ++$resumeCount;
            if ($resumeCount <= 3) {
                for ($index = 0; $index < OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES; ++$index) {
                    $public->message(Task7Transport::tradeFrame(['110']), attempt: 1);
                }

                return;
            }
            $snapshot = Task7Transport::bookFrame('9004', '-1', '5');
            $snapshot['action'] = 'snapshot';
            $snapshot['data'][0]['asks'] = [['102', '2', '0', '1']];
            $snapshot['data'][0]['bids'][] = ['101', '3', '0', '2'];
            $public->message($snapshot, attempt: 1);
        };
        $events->next();
        $btcReplacement = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcReplacement);
        self::assertSame('BTCUSDT', $btcReplacement->symbol);
        self::assertSame('rest_resync_snapshot', $btcReplacement->payload['origin'] ?? null);
        $reconnectCallOffset = count(Task7RestClient::expectedInitialCalls())
            + count(Task7RestClient::expectedInitialBridgeCalls());
        self::assertSame(
            ['orderBook', ['BTC-USDT-SWAP', 400]],
            $rest->calls[$reconnectCallOffset] ?? null,
            'Book overlap must be captured before slower historical recovery.',
        );
        self::assertSame('9003', $btcReplacement->payload['source_seq_id'] ?? null);
        self::assertSame(2, $btcReplacement->payload['source_epoch'] ?? null);
        self::assertSame(4, $public->resumeCount);
        $source->acknowledge($btcReplacement->eventId);
        $events->next();
        $btcRecoveredTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcRecoveredTrade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $btcRecoveredTrade->channel);
        self::assertSame('111', $btcRecoveredTrade->payload['trade_id'] ?? null);
        self::assertSame('rest_recovery', $btcRecoveredTrade->payload['origin'] ?? null);
        $source->acknowledge($btcRecoveredTrade->eventId);
        $events->next();
        $btcBoundary = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcBoundary);
        self::assertSame('reconnect', $btcBoundary->payload['reason'] ?? null);
        $source->acknowledge($btcBoundary->eventId);

        $events->next();
        $ethReconnecting = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethReconnecting);
        self::assertSame('ETHUSDT', $ethReconnecting->symbol);
        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $ethReconnecting->channel);
        self::assertSame('reconnecting', $ethReconnecting->payload['state'] ?? null);
        $source->acknowledge($ethReconnecting->eventId);
        $loop->scripts = [static function () use ($public): void {
            $snapshot = Task7Transport::bookFrame('9005', '-1', '5');
            $snapshot['action'] = 'snapshot';
            $snapshot['arg']['instId'] = 'ETH-USDT-SWAP';
            $snapshot['data'][0]['asks'] = [['202', '2', '0', '1']];
            $snapshot['data'][0]['bids'][] = ['201', '3', '0', '2'];
            $public->message($snapshot, attempt: 1);
        }];
        $events->next();
        $ethReplacement = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethReplacement);
        self::assertSame('ETHUSDT', $ethReplacement->symbol);
        self::assertSame('rest_resync_snapshot', $ethReplacement->payload['origin'] ?? null);
        self::assertSame('9004', $ethReplacement->payload['source_seq_id'] ?? null);
        self::assertSame(2, $ethReplacement->payload['source_epoch'] ?? null);
        $source->acknowledge($ethReplacement->eventId);
        $events->next();
        $ethBoundary = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethBoundary);
        self::assertSame('reconnect', $ethBoundary->payload['reason'] ?? null);
        $source->acknowledge($ethBoundary->eventId);
        $expectedReconnectCalls = Task7RestClient::expectedReconnectCalls();
        array_splice($expectedReconnectCalls, 6, 0, [[
            'recentTrades',
            ['BTC-USDT-SWAP', 500],
        ]]);
        self::assertSame(
            [
                ...Task7RestClient::expectedInitialCalls(),
                ...Task7RestClient::expectedInitialBridgeCalls(),
                ...$expectedReconnectCalls,
            ],
            $rest->calls,
            'Reconnect must recover every 4-candle/trade/book logical stream for both symbols.',
        );

        $events->next();
        $btcRetained = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcRetained);
        self::assertSame('BTCUSDT', $btcRetained->symbol);
        self::assertSame('9004', $btcRetained->payload['source_seq_id'] ?? null);
        self::assertSame('streaming', $this->checkpointState()['phase']);
        self::assertSame(
            '2026-07-25T10:00:01.000000Z',
            $this->checkpointState()['reconnect']['stable_since'],
        );
        self::assertSame(
            2,
            $source->pendingDurableBatchSize(),
            'Reconnect stabilization must preserve per-event acknowledgement effects.',
        );
        $source->acknowledge($btcRetained->eventId);
        $events->next();
        $ethRetained = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethRetained);
        self::assertSame('9005', $ethRetained->payload['source_seq_id'] ?? null);
        $source->acknowledge($ethRetained->eventId);
        self::assertSame(2, $this->checkpointState()['reconnect']['accepted_events']);

        $public->message(Task7Transport::tradeFrame(['111']), attempt: 1);
        $loop->scripts = [static fn () => $public->message(
            Task7Transport::tradeFrame(['110', '111', '112']),
            attempt: 1,
        )];
        $events->next();
        $retainedTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $retainedTrade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $retainedTrade->channel);
        self::assertSame('112', $retainedTrade->payload['trade_id'] ?? null);
        $source->acknowledge($retainedTrade->eventId);
        self::assertSame(3, $this->checkpointState()['reconnect']['accepted_events']);

        for ($offset = 1; $offset <= 10; ++$offset) {
            $previous = (string) (9003 + $offset);
            $sequence = (string) (9004 + $offset);
            $public->message(
                Task7Transport::bookFrame($sequence, $previous, (string) (5 + $offset)),
                attempt: 1,
            );
            $events->next();
            $accepted = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $accepted);
            self::assertSame($sequence, $accepted->payload['source_seq_id'] ?? null);
            $source->acknowledge($accepted->eventId);
        }
        $beforeTimeThreshold = $this->checkpointState()['reconnect'];
        self::assertSame(1, $beforeTimeThreshold['attempt']);
        self::assertSame(12, $beforeTimeThreshold['accepted_events']);
        self::assertContains(30.0, $deterministic->timerIntervals());

        $clock->sleep(30);
        $deterministic->fireTimerInterval(30.0);
        $reset = $this->checkpointState()['reconnect'];
        self::assertSame(0, $reset['attempt']);
        self::assertNull($reset['deadline_at']);
        self::assertNull($reset['stable_since']);
        self::assertSame(0, $reset['accepted_events']);
    }

    public function testReconnectBookAuthorityWaitIsLoggedResubscribesThenFailsClosedAfterSixtySeconds(): void
    {
        $logger = new OkxRecordingLogger();
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $ethApplied = Task7Transport::bookFrame('9003', '9002', '4');
        $ethApplied['arg']['instId'] = 'ETH-USDT-SWAP';
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static function () use ($public, $ethApplied): void {
                $public->message(Task7Transport::bookFrame('9002', '9001', '4'));
                $public->message($ethApplied);
                $public->message(Task7Transport::tradeFrame(['110']));
            },
        ];
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            logger: $logger,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        for ($index = 0; $index < 3; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 2) {
                $events->next();
            }
        }
        $public->disconnect();

        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9003',
        ]];
        $loop->scripts = [
            static fn () => $public->open(attempt: 1),
            static fn () => $business->open(attempt: 1),
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'publicReconnect',
                ) as $acknowledgement) {
                    $public->message($acknowledgement, attempt: 1);
                }
            },
            static function () use ($business): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'businessReconnect',
                ) as $acknowledgement) {
                    $business->message($acknowledgement, attempt: 1);
                }
            },
        ];
        $clock->sleep(1);
        $deterministic->fireTimerInterval(1.0);
        $events->next();
        $reconnecting = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $reconnecting);
        self::assertSame('reconnecting', $reconnecting->payload['state'] ?? null);
        $source->acknowledge($reconnecting->eventId);
        // No authority ever comes: 5 s pass on every network wait.
        $loop->scripts = array_fill(0, 40, static fn () => $clock->sleep(5));

        try {
            $events->next();
            self::fail('A reconnect without book authority must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_gap_unresolved', $exception->getMessage());
        }
        self::assertSame('market_data_gap_unresolved', $source->failureReason());
        $timeline = [];
        foreach ($logger->records as $record) {
            if (str_starts_with($record['message'], 'okx_paper_public_book_')) {
                $timeline[] = [$record['message'], $record['context']['waited_s'] ?? null];
            }
        }
        self::assertSame([
            ['okx_paper_public_book_authority_wait', 0.0],
            ['okx_paper_public_book_authority_wait', 10.0],
            ['okx_paper_public_book_resubscribe', null],
            ['okx_paper_public_book_authority_wait', 20.0],
            ['okx_paper_public_book_authority_wait', 30.0],
            ['okx_paper_public_book_authority_wait', 40.0],
            ['okx_paper_public_book_authority_wait', 50.0],
            ['okx_paper_public_book_authority_wait', 60.0],
            ['okx_paper_public_book_authority_unavailable', 60.0],
        ], $timeline);
        $wait = $logger->first('okx_paper_public_book_authority_wait');
        self::assertSame('BTCUSDT', $wait['symbol']);
        self::assertSame('9003', $wait['rest_snapshot_sequence']);
        self::assertFalse($wait['snapshot_discarded']);
        self::assertFalse($wait['resubscribed']);
        $resubscribe = $logger->first('okx_paper_public_book_resubscribe');
        self::assertSame('BTCUSDT', $resubscribe['symbol']);
        self::assertFalse($resubscribe['snapshot_discarded']);
        self::assertTrue($logger->first('okx_paper_public_book_authority_unavailable')['resubscribed']);
        $books = ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'];
        self::assertContains(['op' => 'unsubscribe', 'args' => [$books]], $public->sent);
        self::assertContains(['op' => 'subscribe', 'args' => [$books]], $public->sent);
        $failure = $logger->first('okx_paper_public_terminal_failure');
        self::assertSame('market_data_gap_unresolved', $failure['public_reason']);
        self::assertStringStartsWith(
            'requireQueuedReconnectBookOverlap@OkxPaperPublicLiveSource.php:',
            $failure['failed_in'],
        );
    }

    public function testHealthyStopDuringReconnectBookWaitPreservesTerminalReason(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $ethApplied = Task7Transport::bookFrame('9003', '9002', '4');
        $ethApplied['arg']['instId'] = 'ETH-USDT-SWAP';
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static function () use ($public, $ethApplied): void {
                $public->message(Task7Transport::bookFrame('9002', '9001', '4'));
                $public->message($ethApplied);
                $public->message(Task7Transport::tradeFrame(['110']));
            },
        ];
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        for ($index = 0; $index < 3; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 2) {
                $events->next();
            }
        }
        $public->disconnect();

        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9003',
        ]];
        $loop->scripts = [
            static fn () => $public->open(attempt: 1),
            static fn () => $business->open(attempt: 1),
            static function () use ($public): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'publicReconnect',
                ) as $acknowledgement) {
                    $public->message($acknowledgement, attempt: 1);
                }
            },
            static function () use ($business): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'businessReconnect',
                ) as $acknowledgement) {
                    $business->message($acknowledgement, attempt: 1);
                }
            },
        ];
        $clock->sleep(1);
        $deterministic->fireTimerInterval(1.0);
        $events->next();
        $reconnecting = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $reconnecting);
        self::assertSame('reconnecting', $reconnecting->payload['state'] ?? null);
        $source->acknowledge($reconnecting->eventId);
        $loop->scripts = [static fn () => $source->requestHealthyOperatorStop()];

        // The stop is deferred until the bounded recovery ends: this recovery never
        // gets its book authority, so the capture fails with the recovery's reason
        // (incomplete), not with a stop requested in the middle of it.
        try {
            $events->next();
            self::fail('A reconnect stop must fail with its authoritative reason.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'market_data_gap_unresolved',
                $exception->getMessage(),
            );
        }
        self::assertSame(
            'market_data_gap_unresolved',
            $source->failureReason(),
        );
    }

    public function testReconnectBookOverlapWaitStopsWhenTheConnectionGenerationChanges(): void
    {
        $source = null;
        $pump = new Task7CountingLoopPump(static function () use (&$source): void {
            self::assertInstanceOf(OkxPaperPublicLiveSource::class, $source);
            $generation = new \ReflectionProperty($source, 'connectionGeneration');
            $generation->setValue($source, $generation->getValue($source) + 1);
        });
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            loopPump: $pump,
        );
        $wait = new \ReflectionMethod($source, 'requireQueuedReconnectBookOverlap');

        self::assertFalse($wait->invoke(
            $source,
            'BTCUSDT',
            'BTC-USDT-SWAP',
            '9001',
        ));
        self::assertNull($source->failureReason());
    }

    #[DataProvider('reconnectCandleRecoveryProvider')]
    public function testReconnectAcceptsNonAdjacentExactCandleOverlapAndEmitsEveryLaterRow(
        bool $interruptAfterFirstChunk,
    ): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $public = new FakeOkxPaperPublicWebSocketTransport();
        $business = new FakeOkxPaperPublicWebSocketTransport();
        $deterministic = new DeterministicLoop();
        $loop = new Task7ScriptedLoop($deterministic);
        $ethApplied = Task7Transport::bookFrame('9003', '9002', '4');
        $ethApplied['arg']['instId'] = 'ETH-USDT-SWAP';
        $loop->scripts = [
            static fn () => $public->open(),
            static fn () => $business->open(),
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
            static function () use ($public, $ethApplied): void {
                $public->message(Task7Transport::bookFrame('9002', '9001', '4'));
                $public->message($ethApplied);
            },
        ];
        $interruptPump = false;
        $phaseObservedDuringInterruption = null;
        $source = null;
        $pump = new Task7CountingLoopPump(function () use (
            &$interruptPump,
            &$phaseObservedDuringInterruption,
            &$source,
            $public,
        ): void {
            if (!$interruptPump) {
                return;
            }
            $interruptPump = false;
            $public->disconnect();
            $phaseObservedDuringInterruption = $this->checkpointState()['phase'] ?? null;
            $source?->stop();
        });
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            loopPump: $pump,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        foreach ([['BTCUSDT', '9002'], ['ETHUSDT', '9003']] as $position => [$symbol, $seq]) {
            $book = $events->current();
            self::assertSame($symbol, $book->symbol);
            self::assertSame($seq, $book->payload['source_seq_id'] ?? null);
            $source->acknowledge($book->eventId);
            if ($position === 0) {
                $events->next();
            }
        }
        $public->disconnect();

        $rest->candleRows['BTC-USDT-SWAP/1m'] = [
            ['1784970120000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
            ['1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ['1784970060000', '101', '102', '100', '101.5', '11', '1', '1100', '1'],
        ];
        $rest->candleRows['BTC-USDT-SWAP/15m'] = [[
            '1784970900000', '103', '104', '102', '103.5', '13', '1', '1300', '1',
        ]];
        $rest->historyCandlePages = [$interruptAfterFirstChunk
            ? [
                ...array_map(
                    static fn (int $offset): array => [
                        (string) (1784970002000 + $offset),
                        '102', '103', '101', '102.5', '12', '1', '1200', '1',
                    ],
                    range(257, 1),
                ),
                ['1784970002000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ]
            : [
                ['1784970500000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
                ['1784970002000', '100', '101', '99', '100.5', '10', '1', '1000', '1'],
            ]];
        $rest->tradeRows['BTC-USDT-SWAP'] = [[
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => '200',
            'px' => '100.7',
            'sz' => '1',
            'side' => 'buy',
            'source' => '0',
            'ts' => '1784970101000',
        ]];
        $rest->historyTradePages = [[
            [
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => '150',
                'px' => '100.6',
                'sz' => '1',
                'side' => 'buy',
                'source' => '0',
                'ts' => '1784970100500',
            ],
            [
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => '100',
                'px' => '100.5',
                'sz' => '2',
                'side' => 'buy',
                'source' => '0',
                'ts' => '1784970100000',
            ],
        ]];
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9003',
        ]];
        $rest->bookRows['ETH-USDT-SWAP'] = [[
            'asks' => [['202', '2', '0', '1']],
            'bids' => [['201', '3', '0', '2']],
            'ts' => '1784970302000',
            'seqId' => '9004',
        ]];
        $btcQueued = Task7Transport::bookFrame('9004', '9003', '5');
        $ethQueued = Task7Transport::bookFrame('9005', '9004', '5');
        $ethQueued['arg']['instId'] = 'ETH-USDT-SWAP';
        $loop->scripts = [
            static fn () => $public->open(attempt: 1),
            static fn () => $business->open(attempt: 1),
            static function () use ($public, $btcQueued, $ethQueued): void {
                foreach (Task7Transport::acknowledgements(
                    self::publicArguments(),
                    'publicReconnect',
                ) as $acknowledgement) {
                    $public->message($acknowledgement, attempt: 1);
                }
                $public->message($btcQueued, attempt: 1);
                $public->message($ethQueued, attempt: 1);
            },
            static function () use ($business): void {
                foreach (Task7Transport::acknowledgements(
                    self::businessArguments(),
                    'businessReconnect',
                ) as $acknowledgement) {
                    $business->message($acknowledgement, attempt: 1);
                }
            },
        ];
        $clock->sleep(1);
        $deterministic->fireTimerInterval(1.0);

        $events->next();
        $reconnecting = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $reconnecting);
        self::assertSame('reconnecting', $reconnecting->payload['state'] ?? null);
        $source->acknowledge($reconnecting->eventId);
        $events->next();
        $recoveredBook = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $recoveredBook);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $recoveredBook->channel);
        self::assertSame('rest_resync_snapshot', $recoveredBook->payload['origin'] ?? null);
        self::assertSame('9003', $recoveredBook->payload['source_seq_id'] ?? null);
        $source->acknowledge($recoveredBook->eventId);
        $events->next();
        if ($interruptAfterFirstChunk) {
            $interruptPump = true;
            for ($index = 0; $index < 256; ++$index) {
                $recovered = $events->current();
                self::assertInstanceOf(PaperMarketEvent::class, $recovered);
                $source->acknowledge($recovered->eventId);
                $events->next();
            }

            self::assertSame('reconnecting', $phaseObservedDuringInterruption);
            self::assertFalse($events->valid());

            return;
        }
        $firstRecoveredCandle = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstRecoveredCandle);
        self::assertSame('1784970500000', $firstRecoveredCandle->exchangeTimestamp->format('Uv'));
        $source->acknowledge($firstRecoveredCandle->eventId);
        $events->next();
        $secondRecoveredCandle = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $secondRecoveredCandle);
        self::assertSame('1784970900000', $secondRecoveredCandle->exchangeTimestamp->format('Uv'));
        $source->acknowledge($secondRecoveredCandle->eventId);
        self::assertContains(
            ['historyCandles', ['BTC-USDT-SWAP', '15m', '1784970900000', 300]],
            $rest->calls,
        );

        $events->next();
        $firstLater = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstLater);
        self::assertSame('1784970060000', $firstLater->exchangeTimestamp->format('Uv'));
        self::assertSame('rest_warmup', $firstLater->payload['origin'] ?? null);
        $source->acknowledge($firstLater->eventId);
        $events->next();
        $secondLater = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $secondLater);
        self::assertSame('1784970120000', $secondLater->exchangeTimestamp->format('Uv'));
        $source->acknowledge($secondLater->eventId);
        self::assertSame(
            '1m|1784970120000',
            $this->checkpointState()['stream_frontiers']['BTCUSDT/rest/candle_1m'][
                'source_identity'
            ],
        );

        $events->next();
        $firstRecoveredTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $firstRecoveredTrade);
        self::assertSame('150', $firstRecoveredTrade->payload['trade_id'] ?? null);
        $source->acknowledge($firstRecoveredTrade->eventId);
        $history = $this->checkpointState()['acknowledged_identity_history'][
            'BTCUSDT/public_trade'
        ] ?? [];
        $history = array_map(OkxPaperAcknowledgedIdentityEntry::expand(...), $history);
        self::assertIsString(
            array_column($history, 2, 0)[hash(
                'sha256',
                'okx|BTC-USDT-SWAP|public_trade|150',
            )] ?? null,
        );
        $events->next();
        $secondRecoveredTrade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $secondRecoveredTrade);
        self::assertSame('200', $secondRecoveredTrade->payload['trade_id'] ?? null);
        // The first history page includes the oldest recent millisecond, so trades
        // sharing it with the recent snapshot cannot fall between the two pages.
        self::assertContains(
            ['historyTrades', ['BTC-USDT-SWAP', 2, '1784970101001', 100]],
            $rest->calls,
        );
    }

    public function testFirstHistoryTradePageIncludesTheOldestRetainedMillisecondWithoutDuplicates(): void
    {
        $source = $this->source(new Task7RestClient(), new Task7Transport(), new Task7Transport());
        $cursor = new \ReflectionMethod($source, 'inclusiveHistoryTradeCursor');
        self::assertSame('1784970101001', $cursor->invoke(null, '1784970101000'));

        // A burst shares the oldest retained millisecond: the recent snapshot kept
        // trades 205..206, the inclusive page returns 203..206 of that millisecond.
        $merge = new \ReflectionMethod($source, 'mergeRetainedTradePage');
        $retained = [
            OkxPaperRetainedTradeRow::compact(self::restTrade('205', '1784970101000')),
            OkxPaperRetainedTradeRow::compact(self::restTrade('206', '1784970101000')),
            OkxPaperRetainedTradeRow::compact(self::restTrade('207', '1784970101002')),
        ];
        $page = [
            self::restTrade('206', '1784970101000'),
            self::restTrade('205', '1784970101000'),
            self::restTrade('204', '1784970101000'),
            self::restTrade('203', '1784970101000'),
        ];
        $merged = $merge->invoke($source, $page, $retained);
        self::assertSame(
            ['203', '204', '205', '206', '207'],
            array_map(
                static fn (string $row): string => OkxPaperRetainedTradeRow::expand($row)['tradeId'],
                $merged,
            ),
        );

        $conflicting = $page;
        $conflicting[0]['sz'] = '3';
        $failure = null;
        try {
            $merge->invoke($source, $conflicting, $retained);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        // A retained trade conflicting with a history row must fail closed.
        self::assertInstanceOf(OkxPaperLiveIntegrityException::class, $failure);
        self::assertSame('market_event_identity_conflict', $failure->getMessage());

        // A limit-sized time-ordered response may hold only part of its oldest
        // millisecond: that millisecond is dropped, never a newer one.
        $trim = new \ReflectionMethod($source, 'withoutPartialOldestMillisecond');
        $full = array_map(
            static fn (int $index): array => self::restTrade(
                (string) (5000 + $index),
                (string) (1784970200000 + max(0, $index - 1)),
            ),
            range(0, 499),
        );
        $kept = $trim->invoke(null, $full, 500);
        self::assertCount(498, $kept);
        self::assertSame('5002', $kept[0]['tradeId']);
        $partialResponse = \array_slice($full, 0, 499);
        self::assertSame($partialResponse, $trim->invoke(null, $partialResponse, 500));
        $sameMillisecond = array_map(
            static fn (int $index): array => self::restTrade((string) (6000 + $index), '1784970300000'),
            range(0, 499),
        );
        self::assertSame($sameMillisecond, $trim->invoke(null, $sameMillisecond, 500));
    }

    /** @return iterable<string, array{bool}> */
    public static function reconnectCandleRecoveryProvider(): iterable
    {
        yield 'complete recovery' => [false];
        yield 'disconnect between durable chunks' => [true];
    }

    #[DataProvider('queuedTradeOverlapProvider')]
    public function testReconnectRequiresExactRawTradeOverlapBeforeQueuedRowsResume(
        string $kind,
        bool $includeExactOverlap,
    ): void {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $initialEthBook = Task7Transport::bookFrame('9003', '9002', '4');
        $initialEthBook['arg']['instId'] = 'ETH-USDT-SWAP';
        $initialCandle = [
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                '1784970460000', '101', '102', '100', '101.5', '11', '1', '1100', '1',
            ]],
        ];
        $initialTrade = Task7Transport::tradeFrame(['9500']);
        $initialTrade['data'][0]['sz'] = '4';
        $initialTrade['data'][0]['count'] = '7';
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $initialTrade,
            Task7Transport::bookFrame('9002', '9001', '4'),
            $initialEthBook,
        ];
        $business->responses = [
            ...Task7Transport::acknowledgements(
                self::businessArguments(),
                'business',
            ),
            $initialCandle,
        ];
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $initialChannels = [];
        for ($position = 0; $position < 4; ++$position) {
            $initial = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $initial);
            $initialChannels[] = $initial->channel;
            $source->acknowledge($initial->eventId);
            if ($position < 3) {
                $events->next();
            }
        }
        self::assertSame([
            PaperMarketDataChannel::PUBLIC_TRADE,
            PaperMarketDataChannel::TOP_OF_BOOK,
            PaperMarketDataChannel::TOP_OF_BOOK,
            PaperMarketDataChannel::CANDLE_1M,
        ], $initialChannels);
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $state = $this->checkpointState();
        foreach ($state['stream_frontiers'] as $stream => $_frontier) {
            $targetStream = $kind === 'trade'
                ? 'BTCUSDT/ws/public_trade'
                : 'BTCUSDT/ws/candle_1m';
            if (!\in_array($stream, [
                $targetStream,
                'BTCUSDT/ws/top_of_book',
                'BTCUSDT/control/snapshot_boundary',
                'ETHUSDT/ws/top_of_book',
                'ETHUSDT/control/snapshot_boundary',
            ], true)) {
                $state['stream_frontiers'][$stream] = null;
            }
        }
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $state['pending_transition'] = [
            'kind' => 'subscription_send',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'subscribe',
        ];
        file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        );
        unset($store);
        gc_collect_cycles();

        $rest = Task7RestClient::withInitialDataset();
        if ($kind === 'trade') {
            $rest->tradeRows['BTC-USDT-SWAP'] = [
                Task7Transport::tradeFrame(['9500'])['data'][0],
            ];
        } else {
            $rest->candleRows['BTC-USDT-SWAP/1m'] = $initialCandle['data'];
        }
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9003',
        ]];
        $rest->bookRows['ETH-USDT-SWAP'] = [[
            'asks' => [['202', '2', '0', '1']],
            'bids' => [['201', '3', '0', '2']],
            'ts' => '1784970302000',
            'seqId' => '9004',
        ]];
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartEthBook = Task7Transport::bookFrame('9005', '9004', '5');
        $restartEthBook['arg']['instId'] = 'ETH-USDT-SWAP';
        $queuedTrade = Task7Transport::tradeFrame(
            $includeExactOverlap ? ['9500', '12'] : ['12'],
        );
        if ($includeExactOverlap) {
            $queuedTrade['data'][0]['sz'] = '4';
            $queuedTrade['data'][0]['count'] = '7';
        }
        $queuedCandle = [
            'arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
            'data' => $includeExactOverlap
                ? [
                    $initialCandle['data'][0],
                    ['1784970520000', '102', '103', '101', '102.5', '12', '1', '1200', '1'],
                ]
                : [[
                    '1784970520000', '102', '103', '101', '102.5', '12', '1', '1200', '1',
                ]],
        ];
        $restartPublic->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'restartPublic'),
            ...($kind === 'trade' ? [$queuedTrade] : []),
            Task7Transport::bookFrame('9004', '9003', '5'),
            $restartEthBook,
        ];
        $restartBusiness->responses = [
            ...Task7Transport::acknowledgements(
                self::businessArguments(),
                'restartBusiness',
            ),
            ...($kind === 'candle' ? [$queuedCandle] : []),
        ];
        $resumed = $this->source(
            $rest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                clock: $clock,
            ),
            clock: $clock,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $reconnecting = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $reconnecting);
        self::assertSame('reconnecting', $reconnecting->payload['state'] ?? null);
        $resumed->acknowledge($reconnecting->eventId);
        $resumedEvents->next();
        $replacement = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replacement);
        self::assertSame('rest_resync_snapshot', $replacement->payload['origin'] ?? null);
        $resumed->acknowledge($replacement->eventId);
        $resumedEvents->next();
        $boundary = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame('reconnect', $boundary->payload['reason'] ?? null);
        $resumed->acknowledge($boundary->eventId);
        $resumedEvents->next();
        $ethReconnecting = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethReconnecting);
        self::assertSame('ETHUSDT', $ethReconnecting->symbol);
        $resumed->acknowledge($ethReconnecting->eventId);
        $resumedEvents->next();
        $ethReplacement = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethReplacement);
        self::assertSame('ETHUSDT', $ethReplacement->symbol);
        self::assertSame('rest_resync_snapshot', $ethReplacement->payload['origin'] ?? null);
        $resumed->acknowledge($ethReplacement->eventId);
        $resumedEvents->next();
        $ethBoundary = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethBoundary);
        self::assertSame('ETHUSDT', $ethBoundary->symbol);
        self::assertSame('reconnect', $ethBoundary->payload['reason'] ?? null);
        $resumed->acknowledge($ethBoundary->eventId);

        if (!$includeExactOverlap) {
            try {
                for ($attempt = 0; $attempt < 100; ++$attempt) {
                    $resumedEvents->next();
                    $queued = $resumedEvents->current();
                    self::assertInstanceOf(PaperMarketEvent::class, $queued);
                    self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $queued->channel);
                    $resumed->acknowledge($queued->eventId);
                }
                self::fail('A queued stream without exact raw overlap must fail closed.');
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('market_data_gap_unresolved', $exception->getMessage());
            }
            self::assertSame('failed', $this->checkpointState()['phase']);

            return;
        }

        do {
            $resumedEvents->next();
            $later = $resumedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $later);
            if ($later->channel === PaperMarketDataChannel::TOP_OF_BOOK) {
                $resumed->acknowledge($later->eventId);
            }
        } while ($later->channel === PaperMarketDataChannel::TOP_OF_BOOK);
        self::assertInstanceOf(PaperMarketEvent::class, $later);
        if ($kind === 'trade') {
            self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $later->channel);
            self::assertSame('12', $later->payload['trade_id'] ?? null);
        } else {
            self::assertSame(PaperMarketDataChannel::CANDLE_1M, $later->channel);
            self::assertSame('1784970520000', $later->exchangeTimestamp->format('Uv'));
        }
        $resumed->acknowledge($later->eventId);
        self::assertSame(
            $kind === 'trade' ? '12' : '1m|1784970520000',
            $this->checkpointState()[
                'stream_frontiers'
            ][$kind === 'trade'
                ? 'BTCUSDT/ws/public_trade'
                : 'BTCUSDT/ws/candle_1m'][
                'source_identity'
            ],
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function queuedTradeOverlapProvider(): iterable
    {
        yield 'later-only trade queue is unresolved' => ['trade', false];
        yield 'exact trade duplicate anchors the suffix' => ['trade', true];
        yield 'later-only candle queue is unresolved' => ['candle', false];
        yield 'exact candle duplicate anchors the suffix' => ['candle', true];
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
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $loop = new DeterministicLoop();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            loop: $loop,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
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
        self::assertSame('failed', $after['phase']);
        self::assertSame('okx_paper_public_message_invalid', $after['failure_reason']);
        self::assertNull($after['pending_transition']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame(0, $publicQueue->count());
        self::assertSame(0, $businessQueue->count());
        self::assertSame([], $loop->timerIntervals());
        self::assertTrue($loop->stopped);
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

    /** @param array<string, mixed> $frame */
    #[DataProvider('terminalBookMaterializerFailureProvider')]
    public function testBookMaterializerFailurePersistsStableFailureAndCleansUp(
        array $frame,
        string $expectedReason,
        bool $discardWarmupSnapshot,
    ): void {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $frame,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $businessQueue = new OkxPaperPublicFrameQueue();
        $loop = new DeterministicLoop();
        $source = $this->source(
            Task7RestClient::withInitialDataset(),
            $public,
            $business,
            loop: $loop,
            publicQueue: $publicQueue,
            businessQueue: $businessQueue,
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
        if ($discardWarmupSnapshot) {
            $books = new \ReflectionProperty($source, 'books');
            $materializers = $books->getValue($source);
            self::assertIsArray($materializers);
            $materializers['BTC-USDT-SWAP'] = new OkxPaperOrderBookMaterializer();
            $books->setValue($source, $materializers);
        }

        try {
            $events->next();
            self::fail('A terminal materializer failure must escape the source.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame($expectedReason, $exception->getMessage());
        }

        $state = $this->checkpointState();
        self::assertSame('failed', $state['phase']);
        self::assertSame($expectedReason, $state['failure_reason']);
        self::assertNull($state['pending_transition']);
        self::assertSame(1, $public->closeCount);
        self::assertSame(1, $business->closeCount);
        self::assertSame(0, $publicQueue->count());
        self::assertSame(0, $businessQueue->count());
        self::assertSame([], $loop->timerIntervals());
        self::assertTrue($loop->stopped);
    }

    public function testObservedFrontiersAndAcknowledgedLedgerStayBoundedAfterLongLivedStreaming(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $rest = Task7RestClient::withInitialDataset();
        $rest->tradeRows['BTC-USDT-SWAP'] = array_map(
            static fn (int $index): array => self::restTrade(
                (string) (1_000 + $index),
                (string) (1784970100000 + $index),
            ),
            range(0, 499),
        );
        $firstWebSocketTradeIds = array_map(
            static fn (int $index): string => (string) (6_001 + $index),
            range(0, 512),
        );
        $remainingWebSocketTradeIds = array_map(
            static fn (int $index): string => (string) (6_514 + $index),
            range(0, 99),
        );
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame($firstWebSocketTradeIds),
            Task7Transport::tradeFrame($remainingWebSocketTradeIds),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);

        $warmupEventCount = 4 + 500 + 1 + 1 + 4 + 1 + 1 + 1;
        for ($index = 0; $index < $warmupEventCount; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $observedFrontiers = new \ReflectionProperty($source, 'observedFrontiers');
        $observed = $observedFrontiers->getValue($source);
        self::assertIsArray($observed);
        self::assertCount(
            OkxPaperLivePolicy::MAX_TRADE_ACKNOWLEDGED_IDENTITIES,
            $observed['BTCUSDT/rest/public_trade'] ?? [],
        );
        self::assertCount(
            OkxPaperLivePolicy::MAX_TRADE_ACKNOWLEDGED_IDENTITIES,
            $observed['BTCUSDT/ws/public_trade'] ?? [],
        );

        foreach ($firstWebSocketTradeIds as $index => $tradeId) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame($tradeId, $event->payload['trade_id'] ?? null);
            $source->acknowledge($event->eventId);
            if ($index < \count($firstWebSocketTradeIds) - 1) {
                $events->next();
            }
        }
        $observed = $observedFrontiers->getValue($source);
        self::assertIsArray($observed);
        self::assertCount(
            OkxPaperLivePolicy::MAX_TRADE_ACKNOWLEDGED_IDENTITIES,
            $observed['BTCUSDT/ws/public_trade'] ?? [],
        );

        $events->next();
        foreach ($remainingWebSocketTradeIds as $index => $tradeId) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame($tradeId, $event->payload['trade_id'] ?? null);
            $source->acknowledge($event->eventId);
            if ($index < \count($remainingWebSocketTradeIds) - 1) {
                $events->next();
            }
        }
        $observed = $observedFrontiers->getValue($source);
        self::assertIsArray($observed);
        self::assertCount(
            OkxPaperLivePolicy::MAX_TRADE_ACKNOWLEDGED_IDENTITIES,
            $observed['BTCUSDT/ws/public_trade'] ?? [],
        );

        $state = $this->checkpointState();
        self::assertSame('streaming', $state['phase']);
        $history = $state['acknowledged_identity_history']['BTCUSDT/public_trade'] ?? null;
        self::assertIsArray($history);
        self::assertCount(500, $history);
        $history = array_map(OkxPaperAcknowledgedIdentityEntry::expand(...), $history);
        self::assertNotContains(
            hash('sha256', 'okx|BTC-USDT-SWAP|public_trade|1000'),
            array_column($history, 0),
        );
        self::assertContains(
            hash('sha256', 'okx|BTC-USDT-SWAP|public_trade|6602'),
            array_column($history, 0),
        );
        $checkpointBytes = strlen(
            json_encode($state, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        ) + 1;
        self::assertLessThan(OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES, $checkpointBytes);

        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $invoke = function (OkxPaperPublicLiveSource $resumed, array $candidateRows): array {
            $frontier = new \ReflectionMethod($resumed, 'tradeFrontier');
            $accepted = new \ReflectionMethod($resumed, 'acceptedEvents');

            return $accepted->invoke(
                $resumed,
                'BTCUSDT/rest/public_trade',
                $candidateRows,
                static fn (array $row, $normalizer): PaperMarketEvent => $normalizer
                    ->recoveryTrade($row),
                static fn (array $row) => $frontier->invoke($resumed, $row),
                true,
            );
        };
        $rows = [
            self::restTrade('1499', '1784970100499'),
            self::restTrade('6602', '1784970306602'),
            self::restTrade('6603', '1784970306603'),
            self::restTrade('6613', '1784970306613'),
            self::restTrade('6614', '1784970306614'),
        ];
        $resumed = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $accepted = $invoke($resumed, $rows);
        self::assertCount(1, $accepted);
        self::assertSame('6614', $accepted[0]['event']->payload['trade_id'] ?? null);

        $rows[1]['px'] = '999.9';
        $conflictSource = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        try {
            $invoke($conflictSource, $rows);
            self::fail('A retained historical identity with another digest must remain fatal.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
    }

    public function testRequiredOverlapRefreshesObservedIdentityBeforeBoundedCompaction(): void
    {
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
        );
        $stream = 'BTCUSDT/rest/public_trade';
        $rows = array_map(
            static fn (int $tradeId): array => self::restTrade(
                (string) $tradeId,
                (string) (1784970100000 + $tradeId),
            ),
            range(1, 501),
        );
        $tradeFrontier = new \ReflectionMethod($source, 'tradeFrontier');
        $rememberObserved = new \ReflectionMethod($source, 'rememberObservedFrontier');
        $acceptedEvents = new \ReflectionMethod($source, 'acceptedEvents');
        $frontiers = [];
        foreach (array_slice($rows, 0, 500) as $row) {
            $frontier = $tradeFrontier->invoke($source, $row);
            self::assertNotNull($frontier);
            $frontiers[] = $frontier;
            $rememberObserved->invoke($source, $stream, 'rest', $frontier);
        }

        $checkpointProperty = new \ReflectionProperty($source, 'checkpoint');
        $checkpoint = $checkpointProperty->getValue($source);
        self::assertInstanceOf(OkxPaperLiveCheckpoint::class, $checkpoint);
        $state = $checkpoint->toArray();
        // A trade recovery overlaps its newest frontier only (see
        // requiredRecoveryOverlaps()): make the oldest observed identity required.
        $state['stream_frontiers'][$stream] = $frontiers[0]->toArray();
        $checkpointProperty->setValue($source, OkxPaperLiveCheckpoint::fromArray($state));
        $requiresOverlap = new \ReflectionProperty($source, 'requiresOverlap');
        $overlapState = $requiresOverlap->getValue($source);
        self::assertIsArray($overlapState);
        $overlapState[$stream] = true;
        $requiresOverlap->setValue($source, $overlapState);

        $invoke = static function (
            array $candidateRows,
            bool $requireSiblingOverlap,
        ) use ($source, $stream, $tradeFrontier, $acceptedEvents): array {
            return $acceptedEvents->invoke(
                $source,
                $stream,
                $candidateRows,
                static fn (array $row, $normalizer): PaperMarketEvent => $normalizer
                    ->recoveryTrade($row),
                static fn (array $row) => $tradeFrontier->invoke($source, $row),
                $requireSiblingOverlap,
            );
        };

        self::assertSame([], $invoke([$rows[0], $rows[499]], true));
        $inserted = $invoke([$rows[500]], false);
        self::assertCount(1, $inserted);
        self::assertSame('501', $inserted[0]['event']->payload['trade_id'] ?? null);

        $observedProperty = new \ReflectionProperty($source, 'observedFrontiers');
        $observed = $observedProperty->getValue($source);
        self::assertIsArray($observed);
        $retained = $observed[$stream] ?? [];
        self::assertCount(OkxPaperLivePolicy::MAX_TRADE_ACKNOWLEDGED_IDENTITIES, $retained);
        self::assertArrayHasKey('rest/1', $retained);
        self::assertArrayNotHasKey('rest/2', $retained);
        self::assertSame(
            ['rest/500', 'rest/1', 'rest/501'],
            array_slice(array_keys($retained), -3),
        );

        $changed = $rows[0];
        $changed['px'] = '999.9';
        try {
            $invoke([$changed], false);
            self::fail('A refreshed observed identity must retain its original digest.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
        self::assertSame([], $invoke([$rows[0]], false));
    }

    public function testCrossOriginTradeObservationPersistsBothCanonicalDigestsAcrossRestart(): void
    {
        $seedStore = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seedStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $frontierSource = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $seedStore,
        );
        $tradeFrontier = new \ReflectionMethod($frontierSource, 'tradeFrontier');
        $restRow = self::restTrade('777', '1784970100777');
        $webSocketRow = [...$restRow, 'sz' => '4', 'count' => '7', 'seqId' => 123];
        $restFrontier = $tradeFrontier->invoke($frontierSource, $restRow);
        $webSocketFrontier = $tradeFrontier->invoke($frontierSource, $webSocketRow);
        self::assertNotNull($restFrontier);
        self::assertNotNull($webSocketFrontier);
        self::assertSame($restFrontier->overlapDigest, $webSocketFrontier->overlapDigest);
        self::assertNotSame($restFrontier->canonicalDigest, $webSocketFrontier->canonicalDigest);
        unset($frontierSource, $seedStore);
        gc_collect_cycles();

        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $identityHash = hash('sha256', $restFrontier->naturalIdentity);
        $state['acknowledged_identity_history'] = [
            'BTCUSDT/public_trade' => [[
                $identityHash,
                $restFrontier->overlapDigest,
                $restFrontier->canonicalDigest,
                OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST,
            ]],
        ];
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode($state) . "\n",
        ));

        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $acceptedEvents = new \ReflectionMethod($source, 'acceptedEvents');
        $tradeFrontier = new \ReflectionMethod($source, 'tradeFrontier');
        self::assertSame([], $acceptedEvents->invoke(
            $source,
            'BTCUSDT/ws/public_trade',
            [$webSocketRow],
            static fn (array $row, $normalizer): PaperMarketEvent => $normalizer
                ->webSocketTrade($row),
            static fn (array $row) => $tradeFrontier->invoke($source, $row),
        ));
        $persisted = $this->checkpointState()['acknowledged_identity_history'][
            'BTCUSDT/public_trade'
        ][0];
        self::assertSame(
            $webSocketFrontier->canonicalDigest,
            OkxPaperAcknowledgedIdentityEntry::expand($persisted)[3],
        );
        unset($source, $store);
        gc_collect_cycles();

        $conflictingRow = [...$webSocketRow, 'sz' => '5'];
        $resumed = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: new OkxPaperLiveCheckpointStore($this->testRoot),
        );
        $acceptedEvents = new \ReflectionMethod($resumed, 'acceptedEvents');
        $tradeFrontier = new \ReflectionMethod($resumed, 'tradeFrontier');
        $invoke = static function (array $rows) use (
            $resumed,
            $acceptedEvents,
            $tradeFrontier,
        ): array {
            return $acceptedEvents->invoke(
                $resumed,
                'BTCUSDT/ws/public_trade',
                $rows,
                static fn (array $row, $normalizer): PaperMarketEvent => $normalizer
                    ->webSocketTrade($row),
                static fn (array $row) => $tradeFrontier->invoke($resumed, $row),
            );
        };
        try {
            $invoke([$conflictingRow]);
            self::fail('A changed same-origin aggregate must remain fatal after restart.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
    }

    public function testAcknowledgedIdentityHistoryWindowRejectsUnsupportedLogicalStreams(): void
    {
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            self::assertSame(
                OkxPaperLivePolicy::MAX_TRADE_ACKNOWLEDGED_IDENTITIES,
                OkxPaperLivePolicy::acknowledgedIdentityHistoryWindow(
                    $symbol . '/public_trade',
                ),
            );
            foreach (['candle_1m', 'candle_5m', 'candle_15m', 'candle_1H'] as $channel) {
                self::assertSame(
                    OkxPaperLivePolicy::MAX_CANDLE_ACKNOWLEDGED_IDENTITIES,
                    OkxPaperLivePolicy::acknowledgedIdentityHistoryWindow(
                        $symbol . '/' . $channel,
                    ),
                );
            }
        }

        foreach (['BTCUSDT/top_of_book', 'garbage'] as $unsupportedStream) {
            try {
                OkxPaperLivePolicy::acknowledgedIdentityHistoryWindow(
                    $unsupportedStream,
                );
                self::fail(sprintf(
                    'Unsupported logical stream "%s" must fail closed.',
                    $unsupportedStream,
                ));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testValidNearLimitFrameIsDurablyAdmittedWithSaturatedIdentityLedger(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $this->seedSaturatedIdentityCheckpoint();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $publicQueue = new OkxPaperPublicFrameQueue();
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            clock: $clock,
            publicQueue: $publicQueue,
        );
        $frame = self::paddedTradeFrame(
            '7101',
            OkxPaperLivePolicy::MAX_FRAME_BYTES - 1,
        );
        $admit = new \ReflectionMethod($source, 'admitSocketFrame');
        $admit->invoke($source, $frame, $publicQueue, 1, 'public');

        self::assertSame(1, $publicQueue->count());
        self::assertSame(OkxPaperLivePolicy::MAX_FRAME_BYTES - 1, $publicQueue->bytes());
        $checkpointContents = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($checkpointContents);
        self::assertLessThanOrEqual(
            OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES,
            \strlen($checkpointContents),
        );
        self::assertStringNotContainsString($frame, $checkpointContents);
        $source->stop();
        $store->__destruct();
        unset($source, $store);
        gc_collect_cycles();

        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $checkpoint = $restartStore->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame(
            ['public' => [$frame], 'business' => []],
            $restartStore->streamingQueues($checkpoint),
        );
    }

    public function testDurableRawQueueHonorsExactFrameAndAggregateByteLimits(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $frameA = self::paddedTradeFrame(
            '7201',
            OkxPaperLivePolicy::MAX_FRAME_BYTES,
        );
        $frameB = self::paddedTradeFrame(
            '7202',
            OkxPaperLivePolicy::MAX_FRAME_BYTES,
        );
        $tooLarge = $frameA . ' ';
        try {
            $store->saveStreamingQueues($checkpoint, [$tooLarge], []);
            self::fail('MAX_FRAME_BYTES + 1 must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_backpressure_exhausted', $exception->getMessage());
        }

        try {
            $checkpoint = $store->saveStreamingQueues($checkpoint, [$frameA], []);
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail(sprintf(
                'A frame at MAX_FRAME_BYTES must be admitted: %s',
                $exception->getMessage(),
            ));
        }
        $checkpoint = $store->saveStreamingQueues(
            $checkpoint,
            [$frameA, $frameB],
            [],
        );
        try {
            $store->saveStreamingQueues(
                $checkpoint,
                [$frameA, $frameB, 'x'],
                [],
            );
            self::fail('MAX_QUEUED_BYTES + 1 must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_backpressure_exhausted', $exception->getMessage());
        }

        $checkpointContents = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($checkpointContents);
        self::assertLessThanOrEqual(
            OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES,
            \strlen($checkpointContents),
        );
        $state = json_decode($checkpointContents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertArrayHasKey('streaming_queue_ref', $state);
        self::assertArrayNotHasKey('streaming_queues', $state);
        self::assertSame(
            OkxPaperLivePolicy::MAX_QUEUED_BYTES,
            $state['streaming_queue_ref']['public']['bytes'] ?? null,
        );
        $store->__destruct();
        unset($store);
        gc_collect_cycles();

        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restored = $restartStore->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame(
            ['public' => [$frameA, $frameB], 'business' => []],
            $restartStore->streamingQueues($restored),
        );
    }

    #[DataProvider('unpreparedQueueTransitionMutationProvider')]
    public function testSaveTransitionRejectsUnpreparedQueueMutation(
        string $mutation,
    ): void {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $frame = json_encode(Task7Transport::tradeFrame(['7251']), \JSON_THROW_ON_ERROR);
        $checkpoint = $store->saveStreamingQueues($checkpoint, [$frame], []);
        $before = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($before);
        $state = $checkpoint->toArray();
        if ($mutation === 'missing_digest') {
            $state['streaming_queue_ref']['sha256'] = str_repeat('b', 64);
        } else {
            unset($state['streaming_queue_ref']);
            $state['streaming_queues'] = [
                'public' => ['raw-secret-downgrade'],
                'business' => [],
            ];
        }
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);

        try {
            $store->saveTransition(
                $candidate,
                $checkpoint->phase,
                $checkpoint->pendingTransition,
            );
            self::fail(sprintf(
                'saveTransition() must reject the unprepared %s queue mutation.',
                $mutation,
            ));
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame(
            $before,
            file_get_contents($this->testRoot . '/checkpoints/okx-live/checkpoint.json'),
        );
        self::assertSame(
            ['public' => [$frame], 'business' => []],
            $store->streamingQueues($checkpoint),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unpreparedQueueTransitionMutationProvider(): iterable
    {
        yield 'missing content-addressed blob' => ['missing_digest'];
        yield 'externalized to embedded downgrade' => ['embedded_downgrade'];
    }

    public function testPreparedBookRecoveryStillPublishesSnapshotAndQueueAtomically(): void
    {
        $gap = $this->sourceAtGapReplacement();
        $state = $this->checkpointState();
        self::assertArrayHasKey('streaming_queue_ref', $state);
        self::assertArrayNotHasKey('streaming_queues', $state);
        self::assertSame(
            '9004',
            $state['resync_by_symbol']['BTCUSDT']['book_snapshot']['seqId'] ?? null,
        );
        $checkpoint = $gap['store']->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $queues = $gap['store']->streamingQueues($checkpoint);
        self::assertNotSame([], $queues['public']);
        self::assertSame(
            '9005',
            json_decode($queues['public'][0], true, 512, \JSON_THROW_ON_ERROR)
                ['data'][0]['seqId'] ?? null,
        );
    }

    #[DataProvider('queuePublicationRetryProvider')]
    public function testFailedQueuePublicationRetriesDoNotReusePriorPublishedState(
        string $savePath,
    ): void {
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $frameA = json_encode(Task7Transport::tradeFrame(['7261']), \JSON_THROW_ON_ERROR);
        $checkpoint = $store->saveStreamingQueues($checkpoint, [$frameA], []);
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $snapshot = [
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ];
        if ($savePath === 'book_recovery') {
            $checkpoint = $this->bookResyncCheckpoint($checkpoint, $transition);
            $store->__destruct();
            unset($store);
            self::assertNotFalse(file_put_contents(
                $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
                CanonicalJson::encode($checkpoint->toArray()) . "\n",
            ));
            $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
            $checkpoint = $store->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            $checkpoint = $store->saveBookRecoverySnapshotAndStreamingQueues(
                $checkpoint,
                'BTCUSDT',
                $snapshot,
                $transition,
                [$frameA],
                [],
            );
        }
        self::assertCount(1, $this->managedQueueBlobPaths());
        $this->replaceCheckpointInodeByteIdentically();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $frame = json_encode(
                Task7Transport::tradeFrame([(string) (7261 + $attempt)]),
                \JSON_THROW_ON_ERROR,
            );
            try {
                if ($savePath === 'streaming_queues') {
                    $store->saveStreamingQueues($checkpoint, [$frameA, $frame], []);
                } else {
                    $store->saveBookRecoverySnapshotAndStreamingQueues(
                        $checkpoint,
                        'BTCUSDT',
                        $snapshot,
                        $transition,
                        [$frameA, $frame],
                        [],
                    );
                }
                self::fail('A byte-identical checkpoint inode replacement must interrupt.');
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
            }
        }

        self::assertCount(
            1,
            $this->managedQueueBlobPaths(),
            'Pre-publication retries must clean their newly prepared queue blob immediately.',
        );
        self::assertSame(
            ['public' => [$frameA], 'business' => []],
            $store->streamingQueues($checkpoint),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function queuePublicationRetryProvider(): iterable
    {
        yield 'streaming queues' => ['streaming_queues'];
        yield 'book recovery snapshot and queues' => ['book_recovery'];
    }

    #[DataProvider('durableRawQueueWriteFaultProvider')]
    public function testDurableRawQueueWriteFaultLeavesOneUnchangedTruth(
        string $fault,
    ): void {
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $frameA = json_encode(Task7Transport::tradeFrame(['7301']), \JSON_THROW_ON_ERROR);
        $frameB = json_encode(Task7Transport::tradeFrame(['7302']), \JSON_THROW_ON_ERROR);
        $checkpoint = $store->saveStreamingQueues($checkpoint, [$frameA], []);
        $before = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($before);
        if ($fault === 'queue') {
            $filesystem->failNextQueueSync = true;
        } else {
            $filesystem->failNextCheckpointSync = true;
        }

        $writeFailure = null;
        try {
            $store->saveStreamingQueues($checkpoint, [$frameA, $frameB], []);
        } catch (OkxPaperLiveIntegrityException $exception) {
            $writeFailure = $exception;
        }
        if (!$writeFailure instanceof OkxPaperLiveIntegrityException) {
            unset($store);
            gc_collect_cycles();
            self::fail(sprintf('The injected %s write fault must interrupt.', $fault));
        }
        self::assertSame(
            'okx_paper_live_checkpoint_write_failed',
            $writeFailure->getMessage(),
        );
        self::assertSame(
            $before,
            file_get_contents($this->testRoot . '/checkpoints/okx-live/checkpoint.json'),
        );
        $store->__destruct();
        unset($store);
        gc_collect_cycles();

        $restartStore = new OkxPaperLiveCheckpointStore(
            $this->testRoot,
            $filesystem,
        );
        $restored = $restartStore->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame(
            ['public' => [$frameA], 'business' => []],
            $restartStore->streamingQueues($restored),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function durableRawQueueWriteFaultProvider(): iterable
    {
        yield 'queue blob sync' => ['queue'];
        yield 'checkpoint sync after blob publication' => ['checkpoint'];
    }

    #[DataProvider('queueCheckpointCommitFaultProvider')]
    public function testQueueCheckpointFaultKeepsTheVisibleCommitRestartable(
        string $savePath,
        string $failedOperation,
        bool $renameCommitted,
    ): void {
        $frameA = json_encode(Task7Transport::tradeFrame(['7401']), \JSON_THROW_ON_ERROR);
        $frameB = json_encode(Task7Transport::tradeFrame(['7402']), \JSON_THROW_ON_ERROR);
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $seed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $checkpoint = $seed->saveStreamingQueues($checkpoint, [$frameA], []);
        $snapshot = [
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ];
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        if ($savePath === 'book_recovery') {
            $frontier = [
                'source_identity' => '9002',
                'natural_identity' => 'okx|BTC-USDT-SWAP|top_of_book|9002',
                'canonical_digest' => str_repeat('b', 64),
                'overlap_digest' => str_repeat('b', 64),
            ];
            $state = $checkpoint->toArray();
            $state['phase'] = 'resyncing';
            $state['remaining_symbols'] = ['BTCUSDT'];
            $state['remaining_boundaries'] = [[
                'symbol' => 'BTCUSDT',
                'reason' => 'sequence_gap',
            ]];
            $state['source_epochs']['BTCUSDT'] = 2;
            $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] = $frontier;
            $state['resync_by_symbol']['BTCUSDT'] = [
                'attempt' => 1,
                'frontier' => $frontier,
                'source_sequence' => '9002',
                'deadline_at' => '2026-07-25T10:00:10.000000Z',
                'policy' => 'book_seq_overlap_v1',
                'book_snapshot' => null,
            ];
            $state['pending_transition'] = $transition;
            $checkpoint = OkxPaperLiveCheckpoint::fromArray($state);
        }
        $seed->__destruct();
        unset($seed);
        if ($savePath === 'book_recovery') {
            self::assertNotFalse(file_put_contents(
                $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
                CanonicalJson::encode($checkpoint->toArray()) . "\n",
            ));
        }

        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $filesystem->failNextOperation = $failedOperation;
        try {
            if ($savePath === 'streaming_queues') {
                $store->saveStreamingQueues($checkpoint, [$frameA, $frameB], []);
            } else {
                $store->saveBookRecoverySnapshotAndStreamingQueues(
                    $checkpoint,
                    'BTCUSDT',
                    $snapshot,
                    $transition,
                    [$frameA, $frameB],
                    [],
                );
            }
            self::fail(sprintf('The injected %s fault must interrupt.', $failedOperation));
        } catch (\Throwable $exception) {
            self::assertInstanceOf(OkxPaperLiveIntegrityException::class, $exception);
            self::assertSame(
                'okx_paper_live_checkpoint_write_failed',
                $exception->getMessage(),
            );
        }

        try {
            $visible = $store->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            $visibleQueues = $store->streamingQueues($visible);
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail(sprintf(
                'The checkpoint visible after %s is not restartable: %s',
                $failedOperation,
                $exception->getMessage(),
            ));
        }
        self::assertSame(
            ['public' => $renameCommitted ? [$frameA, $frameB] : [$frameA], 'business' => []],
            $visibleQueues,
        );
        $visibleRef = $visible->streamingQueueRef;
        self::assertIsArray($visibleRef);
        self::assertFileExists(
            $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
                . $visibleRef['sha256'] . '.bin',
        );
        if ($savePath === 'book_recovery') {
            self::assertEquals(
                $renameCommitted ? $snapshot : null,
                $visible->resyncBySymbol['BTCUSDT']['book_snapshot'] ?? null,
            );
        }

        $store->__destruct();
        unset($store);
        $restart = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restored = $restart->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame($visible->toArray(), $restored->toArray());
        self::assertSame($visibleQueues, $restart->streamingQueues($restored));
    }

    public function testBookResyncDirectorySyncFailureReconcilesSourceAndPreservesWriteError(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore(
            $this->testRoot,
            $filesystem,
            $clock,
        );
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9005', '9004', '5'),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];
        $rest->beforeOrderBook = static function () use ($filesystem): void {
            $filesystem->failNextOperation =
                'okx_paper_live_checkpoint_directory_sync';
        };
        $book = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $book);
        self::assertSame('9002', $book->payload['source_seq_id'] ?? null);
        $source->acknowledge($book->eventId);

        try {
            $events->next();
            self::fail('The post-rename book checkpoint directory sync fault must interrupt.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'okx_paper_live_checkpoint_write_failed',
                $exception->getMessage(),
            );
        }

        $disk = OkxPaperLiveCheckpoint::fromArray($this->checkpointState());
        self::assertSame(
            '9004',
            $disk->resyncBySymbol['BTCUSDT']['book_snapshot']['seqId'] ?? null,
        );
        $adopted = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $sourceCheckpoint = $this->sourceCheckpoint($source);
        self::assertSame(
            $this->materializedCheckpointState($disk),
            $this->materializedCheckpointState($adopted),
        );
        self::assertSame(
            $this->materializedCheckpointState($disk),
            $this->materializedCheckpointState($sourceCheckpoint),
        );
        self::assertSame(
            ['public' => [json_encode(
                Task7Transport::bookFrame('9005', '9004', '5'),
                \JSON_THROW_ON_ERROR,
            )], 'business' => []],
            $store->streamingQueues($adopted),
        );
        self::assertSame(
            2,
            \count(array_filter(
                $rest->calls,
                static fn (array $call): bool => $call === [
                    'orderBook',
                    ['BTC-USDT-SWAP', 400],
                ],
            )),
            'A persistence failure must not start another REST resync attempt.',
        );

        $store->__destruct();
        unset($events, $source, $store, $public, $business, $rest);
        gc_collect_cycles();
        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restart = $restartStore->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame(
            $this->materializedCheckpointState($disk),
            $this->materializedCheckpointState($restart),
        );
        self::assertSame(
            '9004',
            $restart->resyncBySymbol['BTCUSDT']['book_snapshot']['seqId'] ?? null,
        );
    }

    public function testStreamingQueueDirectorySyncFailureReconcilesSourceCheckpoint(): void
    {
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
        $publicQueue = new OkxPaperPublicFrameQueue();
        $source = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
            publicQueue: $publicQueue,
        );
        $frame = json_encode(Task7Transport::tradeFrame(['7451']), \JSON_THROW_ON_ERROR);
        $publicQueue->enqueue($frame);
        $filesystem->failNextOperation =
            'okx_paper_live_checkpoint_directory_sync';
        $persist = new \ReflectionMethod($source, 'persistStreamingQueues');

        try {
            $persist->invoke($source);
            self::fail('The post-rename streaming queue directory sync fault must interrupt.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(OkxPaperLiveIntegrityException::class, $exception);
            self::assertSame(
                'okx_paper_live_checkpoint_write_failed',
                $exception->getMessage(),
            );
        }

        $disk = OkxPaperLiveCheckpoint::fromArray($this->checkpointState());
        $adopted = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame($disk->toArray(), $adopted->toArray());
        self::assertSame(
            $disk->toArray(),
            $this->sourceCheckpoint($source)->toArray(),
        );
        self::assertSame(
            ['public' => [$frame], 'business' => []],
            $store->streamingQueues($adopted),
        );

        $store->__destruct();
        unset($source, $store);
        gc_collect_cycles();
        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restart = $restartStore->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame($disk->toArray(), $restart->toArray());
        self::assertSame(
            ['public' => [$frame], 'business' => []],
            $restartStore->streamingQueues($restart),
        );
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function queueCheckpointCommitFaultProvider(): iterable
    {
        foreach (['streaming_queues', 'book_recovery'] as $savePath) {
            yield $savePath . ' checkpoint file sync' => [
                $savePath,
                'okx_paper_live_checkpoint_sync',
                false,
            ];
            yield $savePath . ' checkpoint publish' => [
                $savePath,
                'okx_paper_live_checkpoint_publish',
                false,
            ];
            yield $savePath . ' checkpoint directory sync' => [
                $savePath,
                'okx_paper_live_checkpoint_directory_sync',
                true,
            ];
        }
    }

    public function testReloadCollectsHardCrashQueueBlobsAndPreservesReferencedBlob(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the hard-crash queue test.');
        }

        $frameA = json_encode(Task7Transport::tradeFrame(['7501']), \JSON_THROW_ON_ERROR);
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $seed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $checkpoint = $seed->saveStreamingQueues($checkpoint, [$frameA], []);
        $referencedSha256 = $checkpoint->streamingQueueRef['sha256'] ?? null;
        self::assertIsString($referencedSha256);
        $seed->__destruct();
        unset($seed);

        $managedDirectory = $this->testRoot . '/checkpoints/okx-live';
        $unmanaged = $managedDirectory . '/streaming-queues-not-managed.bin';
        self::assertSame(9, file_put_contents($unmanaged, 'sentinel!'));
        self::assertTrue(chmod($unmanaged, 0600));
        for ($index = 0; $index < 3; ++$index) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                // Forked child: see ignoreInheritedShutdownErrorsInForkedChild().
                self::ignoreInheritedShutdownErrorsInForkedChild();
                $filesystem = new Task8FailNextCheckpointSyncFilesystem();
                $store = new OkxPaperLiveCheckpointStore(
                    $this->testRoot,
                    $filesystem,
                );
                $childCheckpoint = $store->loadOrCreate(
                    self::DATASET_ID,
                    self::CONFIGURATION_SHA256,
                );
                $frame = json_encode(
                    Task7Transport::tradeFrame([(string) (7502 + $index)]),
                    \JSON_THROW_ON_ERROR,
                );
                $filesystem->crashAtCheckpointSync = true;
                $store->saveStreamingQueues(
                    $childCheckpoint,
                    [$frameA, $frame],
                    [],
                );
                exit(87);
            }
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(86, pcntl_wexitstatus($status));
        }

        $beforeReload = array_values(array_filter(
            glob($managedDirectory . '/streaming-queues-*.bin') ?: [],
            static fn (string $path): bool => preg_match(
                '/\\Astreaming-queues-[a-f0-9]{64}\\.bin\\z/D',
                basename($path),
            ) === 1,
        ));
        $restart = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restored = $restart->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $afterReload = array_values(array_filter(
            glob($managedDirectory . '/streaming-queues-*.bin') ?: [],
            static fn (string $path): bool => preg_match(
                '/\\Astreaming-queues-[a-f0-9]{64}\\.bin\\z/D',
                basename($path),
            ) === 1,
        ));

        self::assertLessThanOrEqual(
            2,
            \count($beforeReload),
            'Each reload under the writer lock must collect the previous crash orphan.',
        );
        self::assertSame(
            [$managedDirectory . '/streaming-queues-' . $referencedSha256 . '.bin'],
            $afterReload,
        );
        self::assertSame(
            ['public' => [$frameA], 'business' => []],
            $restart->streamingQueues($restored),
        );
        self::assertSame('sentinel!', file_get_contents($unmanaged));
    }

    public function testReloadCollectsQueueTemporaryLeftByHardCrashDuringQueueSync(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the hard-crash queue test.');
        }

        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $seed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $seed->__destruct();
        unset($seed);

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Forked child: see ignoreInheritedShutdownErrorsInForkedChild().
            self::ignoreInheritedShutdownErrorsInForkedChild();
            $filesystem = new Task8FailNextCheckpointSyncFilesystem();
            $filesystem->crashAtQueueSync = true;
            $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
            $store->saveStreamingQueues(
                $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256),
                [json_encode(Task7Transport::tradeFrame(['7511']), \JSON_THROW_ON_ERROR)],
                [],
            );
            exit(91);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(90, pcntl_wexitstatus($status));

        $managedDirectory = $this->testRoot . '/checkpoints/okx-live';
        $temporaries = glob($managedDirectory . '/.okx-live-queue-*') ?: [];
        self::assertCount(1, $temporaries);

        (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );

        self::assertSame([], glob($managedDirectory . '/.okx-live-queue-*') ?: []);
    }

    #[DataProvider('unsafeQueueTemporaryProvider')]
    public function testReloadRejectsUnsafeQueueTemporaryWithoutRemovingIt(string $kind): void
    {
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $seed->__destruct();
        unset($seed);

        $temporary = $this->testRoot . '/checkpoints/okx-live/.okx-live-queue-'
            . str_repeat('a', 32);
        if ($kind === 'symlink') {
            $outside = $this->testRoot . '/outside-temporary';
            self::assertSame(8, file_put_contents($outside, 'sentinel'));
            self::assertTrue(symlink($outside, $temporary));
        } else {
            self::assertSame(6, file_put_contents($temporary, 'orphan'));
            self::assertTrue(chmod($temporary, 0644));
        }

        $this->expectException(OkxPaperLiveIntegrityException::class);
        try {
            (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
        } finally {
            self::assertTrue(file_exists($temporary) || is_link($temporary));
            if ($kind === 'symlink') {
                self::assertSame('sentinel', file_get_contents($this->testRoot . '/outside-temporary'));
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeQueueTemporaryProvider(): iterable
    {
        yield 'symlink' => ['symlink'];
        yield 'non-private regular file' => ['permissions'];
    }

    public function testReloadPreservesUnmanagedTemporaryLookalikes(): void
    {
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $seed->__destruct();
        unset($seed);

        $directory = $this->testRoot . '/checkpoints/okx-live';
        $lookalikes = [
            $directory . '/.okx-live-queue-' . str_repeat('b', 31),
            $directory . '/.okx-live-queue-' . str_repeat('c', 32) . '.tmp',
        ];
        foreach ($lookalikes as $path) {
            self::assertSame(8, file_put_contents($path, 'sentinel'));
            self::assertTrue(chmod($path, 0600));
        }

        (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );

        foreach ($lookalikes as $path) {
            self::assertSame('sentinel', file_get_contents($path));
        }
    }

    public function testRepeatedReloadsBoundQueueTemporaryCleanup(): void
    {
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $seed->__destruct();
        unset($seed);

        $directory = $this->testRoot . '/checkpoints/okx-live';
        for ($index = 0; $index < 3; ++$index) {
            $temporary = $directory . '/.okx-live-queue-'
                . str_pad(dechex($index), 32, '0', STR_PAD_LEFT);
            self::assertSame(6, file_put_contents($temporary, 'orphan'));
            self::assertTrue(chmod($temporary, 0600));

            (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );

            self::assertSame([], glob($directory . '/.okx-live-queue-*') ?: []);
        }
    }

    public function testReloadCollectsSafeQueueQuarantineAfterCrashImmediatelyAfterRename(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the quarantine crash test.');
        }

        $frame = json_encode(Task7Transport::tradeFrame(['7505']), \JSON_THROW_ON_ERROR);
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $seed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $checkpoint = $seed->saveStreamingQueues($checkpoint, [$frame], []);
        $referencedSha256 = $checkpoint->streamingQueueRef['sha256'] ?? null;
        self::assertIsString($referencedSha256);
        $referenced = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . $referencedSha256 . '.bin';
        $seed->__destruct();
        unset($seed);

        $orphan = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . str_repeat('b', 64) . '.bin';
        self::assertSame(6, file_put_contents($orphan, 'orphan'));
        self::assertTrue(chmod($orphan, 0600));
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Forked child: see ignoreInheritedShutdownErrorsInForkedChild().
            self::ignoreInheritedShutdownErrorsInForkedChild();
            $filesystem = new Task8FailNextCheckpointSyncFilesystem();
            $filesystem->crashAfterMoveOperation =
                'okx_paper_live_queue_cleanup_quarantine';
            (new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                $filesystem,
            ))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            exit(89);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(88, pcntl_wexitstatus($status));
        self::assertFileDoesNotExist($orphan);
        $afterCrash = $this->managedQueueBlobPaths();
        self::assertCount(2, $afterCrash);
        $quarantines = array_values(array_diff($afterCrash, [$referenced]));
        self::assertCount(1, $quarantines);
        self::assertSame('orphan', file_get_contents($quarantines[0]));
        self::assertSame(0600, fileperms($quarantines[0]) & 0777);
        self::assertSame(1, lstat($quarantines[0])['nlink'] ?? null);

        $restart = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restored = $restart->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame([$referenced], $this->managedQueueBlobPaths());
        self::assertSame(
            ['public' => [$frame], 'business' => []],
            $restart->streamingQueues($restored),
        );
    }

    #[DataProvider('unsafeManagedQueueBlobProvider')]
    public function testReloadRejectsUnsafeManagedQueueBlobWithoutRemovingIt(
        string $kind,
    ): void {
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $seed->__destruct();
        unset($seed);
        $managed = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . str_repeat('c', 64) . '.bin';
        if ($kind === 'symlink') {
            $outside = $this->testRoot . '/outside-queue';
            self::assertSame(8, file_put_contents($outside, 'sentinel'));
            self::assertTrue(symlink($outside, $managed));
        } else {
            self::assertSame(6, file_put_contents($managed, 'orphan'));
            self::assertTrue(chmod($managed, 0644));
        }

        try {
            (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('An unsafe strictly named managed queue blob must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertTrue(file_exists($managed) || is_link($managed));
        if ($kind === 'symlink') {
            self::assertTrue(is_link($managed));
            self::assertSame(
                'sentinel',
                file_get_contents($this->testRoot . '/outside-queue'),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeManagedQueueBlobProvider(): iterable
    {
        yield 'symlink' => ['symlink'];
        yield 'non-private regular file' => ['permissions'];
    }

    public function testQueueCleanupQuarantineRejectsPostStatSubstitutionWithoutDeletingIt(): void
    {
        $frame = json_encode(Task7Transport::tradeFrame(['7551']), \JSON_THROW_ON_ERROR);
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $seed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $checkpoint = $seed->saveStreamingQueues($checkpoint, [$frame], []);
        $referenced = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . ($checkpoint->streamingQueueRef['sha256'] ?? '') . '.bin';
        self::assertFileExists($referenced);
        $seed->__destruct();
        unset($seed);

        $orphan = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . str_repeat('d', 64) . '.bin';
        $displaced = $this->testRoot . '/checkpoints/okx-live/.race-original';
        $sentinel = $this->testRoot . '/race-sentinel';
        self::assertSame(6, file_put_contents($orphan, 'orphan'));
        self::assertTrue(chmod($orphan, 0600));
        self::assertSame(8, file_put_contents($sentinel, 'sentinel'));
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $filesystem->replaceAfterSecondPathStat = $orphan;
        $filesystem->replacementTarget = $sentinel;
        $filesystem->replacementBackup = $displaced;

        try {
            (new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                $filesystem,
            ))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('A post-stat queue pathname substitution must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertTrue($filesystem->raceTriggered);
        self::assertTrue(is_link($orphan));
        self::assertSame($sentinel, readlink($orphan));
        self::assertSame('sentinel', file_get_contents($sentinel));
        self::assertFileExists($displaced);
        self::assertFileExists($referenced);
    }

    public function testQueueCleanupRejectsSubstitutionImmediatelyBeforeUnlink(): void
    {
        $frame = json_encode(Task7Transport::tradeFrame(['7552']), \JSON_THROW_ON_ERROR);
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $seed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $checkpoint = $seed->saveStreamingQueues($checkpoint, [$frame], []);
        $referenced = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . ($checkpoint->streamingQueueRef['sha256'] ?? '') . '.bin';
        $referencedContents = file_get_contents($referenced);
        self::assertIsString($referencedContents);
        $seed->__destruct();
        unset($seed);

        $orphan = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . str_repeat('f', 64) . '.bin';
        $displaced = $this->testRoot . '/checkpoints/okx-live/.race-quarantined-original';
        $sentinel = $this->testRoot . '/race-unlink-sentinel';
        self::assertSame(6, file_put_contents($orphan, 'orphan'));
        self::assertTrue(chmod($orphan, 0600));
        self::assertSame(8, file_put_contents($sentinel, 'sentinel'));
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $filesystem->replaceBeforeRemoveOperation =
            'okx_paper_live_queue_cleanup_unlink';
        $filesystem->replacementTarget = $sentinel;
        $filesystem->replacementBackup = $displaced;

        try {
            (new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                $filesystem,
            ))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('A last-moment quarantine substitution must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'okx_paper_live_checkpoint_write_failed',
                $exception->getMessage(),
            );
        }

        self::assertTrue($filesystem->raceTriggered);
        self::assertIsString($filesystem->replacementPath);
        self::assertTrue(is_link($filesystem->replacementPath));
        self::assertSame($sentinel, readlink($filesystem->replacementPath));
        self::assertSame('sentinel', file_get_contents($sentinel));
        self::assertSame('orphan', file_get_contents($displaced));
        self::assertSame($referencedContents, file_get_contents($referenced));
    }

    #[DataProvider('queueCleanupFaultProvider')]
    public function testQueueCleanupFaultPreservesTheReferencedBlobAndFailsClosed(
        string $fault,
    ): void {
        $frame = json_encode(Task7Transport::tradeFrame(['7561']), \JSON_THROW_ON_ERROR);
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $checkpoint = $seed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $checkpoint = $seed->saveStreamingQueues($checkpoint, [$frame], []);
        $referenced = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . ($checkpoint->streamingQueueRef['sha256'] ?? '') . '.bin';
        $seed->__destruct();
        unset($seed);
        $orphan = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . str_repeat('e', 64) . '.bin';
        self::assertSame(6, file_put_contents($orphan, 'orphan'));
        self::assertTrue(chmod($orphan, 0600));
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        if ($fault === 'rename') {
            $filesystem->failNextOperation =
                'okx_paper_live_queue_cleanup_quarantine';
        } elseif ($fault === 'unlink') {
            $filesystem->failNextRemoveOperation =
                'okx_paper_live_queue_cleanup_unlink';
        } else {
            $filesystem->failNextOperation =
                'okx_paper_live_queue_cleanup_directory_sync';
        }

        try {
            (new OkxPaperLiveCheckpointStore(
                $this->testRoot,
                $filesystem,
            ))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail(sprintf('The injected queue cleanup %s fault must interrupt.', $fault));
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame(
                'okx_paper_live_checkpoint_write_failed',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($referenced);
        if ($fault === 'fsync') {
            self::assertFileDoesNotExist($orphan);
        } else {
            self::assertFileExists($orphan);
        }

        $restart = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restored = $restart->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame(
            ['public' => [$frame], 'business' => []],
            $restart->streamingQueues($restored),
        );
        self::assertSame([$referenced], $this->managedQueueBlobPaths());
    }

    /** @return iterable<string, array{string}> */
    public static function queueCleanupFaultProvider(): iterable
    {
        yield 'quarantine rename' => ['rename'];
        yield 'quarantine unlink' => ['unlink'];
        yield 'cleanup directory fsync' => ['fsync'];
    }

    public function testNormalQueueBlobReplacementDurablyDeletesTheOldBlob(): void
    {
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $frameA = json_encode(Task7Transport::tradeFrame(['7601']), \JSON_THROW_ON_ERROR);
        $frameB = json_encode(Task7Transport::tradeFrame(['7602']), \JSON_THROW_ON_ERROR);
        $checkpoint = $store->saveStreamingQueues($checkpoint, [$frameA], []);
        $oldSha256 = $checkpoint->streamingQueueRef['sha256'] ?? null;
        self::assertIsString($oldSha256);
        $oldPath = $this->testRoot . '/checkpoints/okx-live/streaming-queues-'
            . $oldSha256 . '.bin';
        self::assertFileExists($oldPath);
        $filesystem->operations = [];

        $checkpoint = $store->saveStreamingQueues($checkpoint, [$frameB], []);

        self::assertFileDoesNotExist($oldPath);
        self::assertContains(
            'sync:okx_paper_live_queue_cleanup_directory_sync',
            $filesystem->operations,
        );
        $store->__destruct();
        unset($store);
        $restart = new OkxPaperLiveCheckpointStore($this->testRoot);
        $restored = $restart->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame(
            ['public' => [$frameB], 'business' => []],
            $restart->streamingQueues($restored),
        );
    }

    public function testAcknowledgedCrossOriginIdentityHistoryRoundTripsDurablyAndIsBounded(): void
    {
        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $state['acknowledged_identity_history'] = [
            'BTCUSDT/public_trade' => array_map(
                static fn (int $index): array => [
                    hash(
                        'sha256',
                        'okx|BTC-USDT-SWAP|public_trade|T' . $index,
                    ),
                    str_repeat('a', 64),
                    str_repeat('b', 64),
                    OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST,
                ],
                range(1, 500),
            ),
        ];

        $restored = OkxPaperLiveCheckpoint::fromArray($state);

        self::assertSame(
            $state['acknowledged_identity_history'],
            $restored->toArray()['acknowledged_identity_history'] ?? null,
        );
        self::assertLessThan(
            OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES,
            strlen(CanonicalJson::encode($restored->toArray()) . "\n"),
        );
    }

    public function testMalformedCallbackTerminalizesBeforeReturningAndFreshRestartRemainsFailed(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9970']),
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

        try {
            $public->message('{"arg":{"channel":"candle1m","instId":"BTC-USDT-SWAP"},"data":[]}');
            self::fail('A wrong-socket frame must be terminal before its callback returns.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_message_invalid', $exception->getMessage());
        }

        $state = $this->checkpointState();
        self::assertSame('failed', $state['phase']);
        self::assertSame('okx_paper_public_message_invalid', $state['failure_reason']);
        unset($events, $source);
        gc_collect_cycles();

        $resumed = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        self::assertSame('okx_paper_public_message_invalid', $resumed->failureReason());
    }

    public function testFreshRestartDeduplicatesHistoricalSiblingAndRejectsItsChangedDigest(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9102', '9103']),
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
        foreach (['9102', '9103'] as $index => $tradeId) {
            $event = $events->current();
            self::assertSame($tradeId, $event?->payload['trade_id'] ?? null);
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index === 0) {
                $events->next();
            }
        }
        $source->stop();
        unset($events, $source);

        $rows = [
            self::restTrade('100', '1784970100000'),
            self::restTrade('9102', '1784970309102'),
            self::restTrade('9103', '1784970309103'),
            self::restTrade('9104', '1784970309104'),
        ];
        $invoke = function (OkxPaperPublicLiveSource $resumed, array $candidateRows): array {
            $frontier = new \ReflectionMethod($resumed, 'tradeFrontier');
            $accepted = new \ReflectionMethod($resumed, 'acceptedEvents');

            return $accepted->invoke(
                $resumed,
                'BTCUSDT/rest/public_trade',
                $candidateRows,
                static fn (array $row, $normalizer): PaperMarketEvent => $normalizer
                    ->recoveryTrade($row),
                static fn (array $row) => $frontier->invoke($resumed, $row),
                true,
            );
        };
        $resumed = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        $accepted = $invoke($resumed, $rows);
        self::assertCount(1, $accepted);
        self::assertSame('9104', $accepted[0]['event']->payload['trade_id'] ?? null);

        $conflictingRows = $rows;
        $conflictingRows[1]['px'] = '999.9';
        $conflictSource = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $store,
        );
        try {
            $invoke($conflictSource, $conflictingRows);
            self::fail('A changed historical sibling digest must remain fatal after restart.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
    }

    public function testExactSiblingFrontierPreventsReemissionAfterLedgerCompaction(): void
    {
        $store = new OkxPaperLiveCheckpointStore($this->testRoot);
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::tradeFrame(['9102', '9103']),
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
        foreach (['9102', '9103'] as $position => $tradeId) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame($tradeId, $event->payload['trade_id'] ?? null);
            $source->acknowledge($event->eventId);
            if ($position === 0) {
                $events->next();
            }
        }
        $source->stop();
        $state = $this->checkpointState();
        unset($state['acknowledged_identity_history']['BTCUSDT/public_trade']);
        unset($events, $source, $store);
        gc_collect_cycles();
        $checkpointPath = $this->testRoot . '/checkpoints/okx-live/checkpoint.json';
        self::assertNotFalse(file_put_contents(
            $checkpointPath,
            CanonicalJson::encode($state) . "\n",
        ));

        $restartStore = new OkxPaperLiveCheckpointStore($this->testRoot);
        $resumed = $this->source(
            new Task7RestClient(),
            new Task7Transport(),
            new Task7Transport(),
            checkpointStore: $restartStore,
        );
        $frontier = new \ReflectionMethod($resumed, 'tradeFrontier');
        $accepted = new \ReflectionMethod($resumed, 'acceptedEvents');
        $rows = [
            self::restTrade('100', '1784970100000'),
            self::restTrade('9102', '1784970309102'),
            self::restTrade('9103', '1784970309103'),
            self::restTrade('9104', '1784970309104'),
        ];
        $recovered = $accepted->invoke(
            $resumed,
            'BTCUSDT/rest/public_trade',
            $rows,
            static fn (array $row, $normalizer): PaperMarketEvent => $normalizer
                ->recoveryTrade($row),
            static fn (array $row) => $frontier->invoke($resumed, $row),
            true,
        );

        self::assertCount(1, $recovered);
        self::assertSame('9104', $recovered[0]['event']->payload['trade_id'] ?? null);
    }

    public function testReadinessQueueReplacementCrashKeepsEarlyDataForFreshRestart(): void
    {
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
        $early = Task7Transport::tradeFrame(['9971']);
        $public = new Task7Transport();
        $business = new Task7Transport(
            afterSend: static function () use ($filesystem): void {
                $filesystem->failNextCheckpointSync = true;
            },
        );
        $public->connectResponses = [$early];
        $public->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'public',
        );
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
            self::fail('The atomic readiness queue replacement fault must interrupt.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_write_failed', $exception->getMessage());
        }
        $source->stop();
        unset($events, $source);
        gc_collect_cycles();

        $restartStore = $store;
        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $restartRest = Task7RestClient::withInitialDataset();
        $resumed = $this->source(
            $restartRest,
            $restartPublic,
            $restartBusiness,
            checkpointStore: $restartStore,
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayed = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayed);
        self::assertSame('9971', $replayed->payload['trade_id'] ?? null);
        self::assertSame(Task7RestClient::expectedInitialBridgeCalls(), $restartRest->calls);
    }

    public function testResyncQueueMirrorWriteFaultLeavesOneUnchangedDurableTruth(): void
    {
        $filesystem = new Task8FailNextCheckpointSyncFilesystem();
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, $filesystem);
        $gap = $this->sourceAtGapReplacement($store);
        $before = $this->checkpointState();
        self::assertSame('resyncing', $before['phase']);
        $checkpoint = $gap['store']->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $beforeQueues = $store->streamingQueues($checkpoint);
        $beforeContents = file_get_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($beforeContents);
        $newFrame = json_encode(
            Task7Transport::tradeFrame(['9972']),
            \JSON_THROW_ON_ERROR,
        );
        $filesystem->failNextCheckpointSync = true;
        try {
            $store->saveStreamingQueues(
                $checkpoint,
                [...$beforeQueues['public'], $newFrame],
                $beforeQueues['business'],
            );
            self::fail('The atomic resync queue write fault must interrupt.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_write_failed', $exception->getMessage());
        }
        $restoredState = json_decode(
            file_get_contents(
                $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            ) ?: throw new \RuntimeException('checkpoint_read_failed'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($restoredState);
        $restored = OkxPaperLiveCheckpoint::fromArray($restoredState);
        self::assertSame($beforeContents, CanonicalJson::encode($restored->toArray()) . "\n");
        $restoredQueues = $store->streamingQueues($restored);
        self::assertSame(
            $beforeQueues['public'],
            $restoredQueues['public'],
        );
        self::assertSame(
            $beforeQueues['business'],
            $restoredQueues['business'],
        );
        $resync = $restored->resyncBySymbol['BTCUSDT'] ?? null;
        self::assertIsArray($resync);
        self::assertArrayNotHasKey('queued_public_frames', $resync);
        self::assertArrayNotHasKey('queued_business_frames', $resync);
        self::assertNotContains($newFrame, $restoredQueues['public']);
    }

    public function testFilteredBookOverlapRestartsFromTheOnlyDurableQueueWithoutFalseGap(): void
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $obsoleteThenOverlap = Task7Transport::bookFrame('9002', '9001', '4');
        $overlap = Task7Transport::bookFrame('9005', '9004', '5');
        $obsoleteThenOverlap['data'][] = $overlap['data'][0];
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9004', '9003', '4'),
            $obsoleteThenOverlap,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];
        $probe = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $probe);
        self::assertSame('9002', $probe->payload['source_seq_id'] ?? null);
        $source->acknowledge($probe->eventId);
        $events->next();
        $snapshot = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $snapshot);
        self::assertSame('rest_resync_snapshot', $snapshot->payload['origin'] ?? null);
        self::assertSame('9004', $snapshot->payload['source_seq_id'] ?? null);

        $source->stop();
        unset($events, $source, $public, $business);
        gc_collect_cycles();

        $restartPublic = new Task7Transport();
        $restartBusiness = new Task7Transport();
        $restartPublic->responses = Task7Transport::acknowledgements(
            self::publicArguments(),
            'restartPublic',
        );
        $restartBusiness->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'restartBusiness',
        );
        $resumed = $this->source(
            new Task7RestClient(),
            $restartPublic,
            $restartBusiness,
            checkpointStore: $store,
            clock: $clock,
            loop: new DeterministicLoop(),
        );
        $resumedEvents = $resumed->events();
        self::assertInstanceOf(\Generator::class, $resumedEvents);
        $replayedSnapshot = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayedSnapshot);
        self::assertEquals($snapshot->toArray(), $replayedSnapshot->toArray());
        $resumed->acknowledge($replayedSnapshot->eventId);
        $resumedEvents->next();
        $boundary = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $boundary);
        self::assertSame('sequence_gap', $boundary->payload['reason'] ?? null);
        $resumed->acknowledge($boundary->eventId);
        $resumedEvents->next();
        $retained = $resumedEvents->current();

        self::assertInstanceOf(PaperMarketEvent::class, $retained);
        self::assertSame('9005', $retained->payload['source_seq_id'] ?? null);
        self::assertSame('9004', $retained->payload['source_prev_seq_id'] ?? null);
        self::assertSame('streaming', $this->checkpointState()['phase']);
    }

    public function testCheckpointRejectsExplicitResyncQueueDivergenceAndRestoresLegacyMirrors(): void
    {
        $gap = $this->sourceAtGapReplacement();
        $state = $this->checkpointState();
        $checkpoint = $gap['store']->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $queues = $gap['store']->streamingQueues($checkpoint);
        self::assertArrayHasKey('streaming_queue_ref', $state);
        self::assertArrayNotHasKey('streaming_queues', $state);
        self::assertArrayNotHasKey(
            'queued_public_frames',
            $state['resync_by_symbol']['BTCUSDT'],
        );
        $source = $gap['source'];
        $events = $gap['events'];
        $source->stop();
        unset($events, $source, $gap);
        gc_collect_cycles();

        $legacy = $state;
        unset($legacy['streaming_queue_ref']);
        $legacy['streaming_queues'] = $queues;
        $legacy['resync_by_symbol']['BTCUSDT']['queued_public_frames'] = $queues['public'];
        $legacy['resync_by_symbol']['BTCUSDT']['queued_business_frames'] = $queues['business'];
        $divergent = $legacy;
        $divergent['resync_by_symbol']['BTCUSDT']['queued_public_frames'] = [];
        try {
            OkxPaperLiveCheckpoint::fromArray($divergent);
            self::fail('An explicit resync queue mirror may not diverge from streaming_queues.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        unset($legacy['streaming_queues']);
        $restored = OkxPaperLiveCheckpoint::fromArray($legacy);
        self::assertSame(
            $legacy['resync_by_symbol']['BTCUSDT']['queued_public_frames'],
            $restored->streamingQueues['public'],
        );
        self::assertSame(
            $legacy['resync_by_symbol']['BTCUSDT']['queued_business_frames'],
            $restored->streamingQueues['business'],
        );
        self::assertArrayNotHasKey('streaming_queues', $restored->toArray());
        self::assertSame(
            CanonicalJson::encode($legacy),
            CanonicalJson::encode($restored->toArray()),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function terminalBookMaterializerFailureProvider(): iterable
    {
        yield 'update without a materialized snapshot' => [
            Task7Transport::bookFrame('9002', '9001', '4'),
            'okx_paper_book_snapshot_required',
            true,
        ];
        yield 'non-increasing sequence' => [
            Task7Transport::bookFrame('9001', '9001', '4'),
            'okx_paper_book_sequence_invalid',
            false,
        ];
        $invalidBook = Task7Transport::bookFrame('9002', '9001', '4');
        $invalidBook['data'][0]['bids'][0][1] = '-1';
        yield 'invalid materialized book' => [
            $invalidBook,
            'okx_paper_materialized_order_book_invalid',
            false,
        ];
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
    /**
     * A forked child inherits the parent's shutdown functions (for instance the
     * Symfony XmlFileLoader temporary-file cleanup registered by an earlier kernel
     * test) and PHPUnit's error handler. When the child exits, such a function can
     * warn because a previous child already removed the file; outside a test the
     * PHPUnit handler then throws and the child exits with 255 instead of the exit
     * status under test. Errors of a child are never reported to the parent anyway.
     */
    private static function ignoreInheritedShutdownErrorsInForkedChild(): void
    {
        set_error_handler(static fn (): bool => true);
    }

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

    /**
     * A reconnecting checkpoint whose pending transition recovers $stream from its
     * recent trades, with the given BTCUSDT trade frontiers.
     *
     * @param array<string, \App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier> $frontiers
     */
    private function seedTradeRecoveryCheckpoint(string $stream, array $frontiers): void
    {
        (new OkxPaperLiveCheckpointStore($this->testRoot))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        gc_collect_cycles();
        $state = $this->checkpointState();
        $state['phase'] = 'reconnecting';
        $state['connection_epoch'] = 2;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-25T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        foreach ($frontiers as $frontierStream => $frontier) {
            $state['stream_frontiers'][$frontierStream] = $frontier->toArray();
        }
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontiers[$stream]->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => $stream,
            'stage' => 'recent_trades',
        ];
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            CanonicalJson::encode(OkxPaperLiveCheckpoint::fromArray($state)->toArray()) . "\n",
        ));
    }

    private function reconnectSource(Task7RestClient $rest, MockClock $clock): OkxPaperPublicLiveSource
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = Task7Transport::acknowledgements(self::publicArguments(), 'public');
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );

        return $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock),
            clock: $clock,
        );
    }

    /**
     * Acknowledges trade events until another event, the end or a failure.
     *
     * @return array{list<string>, ?\Throwable}
     */
    private function acknowledgeTradeEvents(OkxPaperPublicLiveSource $source, \Generator $events): array
    {
        $tradeIds = [];
        try {
            for ($step = 0; $step < 2_000 && $events->valid(); ++$step) {
                $event = $events->current();
                self::assertInstanceOf(PaperMarketEvent::class, $event);
                $tradeId = $event->payload['trade_id'] ?? null;
                if (!\is_string($tradeId)) {
                    break;
                }
                $tradeIds[] = $tradeId;
                $source->acknowledge($event->eventId);
                $events->next();
            }
        } catch (\Throwable $exception) {
            return [$tradeIds, $exception];
        }

        return [$tradeIds, null];
    }

    /** @param array<string, mixed> $tradeFrame */
    private function junctionSource(Task7RestClient $rest, array $tradeFrame): OkxPaperPublicLiveSource
    {
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            $tradeFrame,
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );

        return $this->source($rest, $public, $business, anchoredTradeJunctions: true);
    }

    /** @return list<array{string, string}> */
    private function nextTradeEvents(OkxPaperPublicLiveSource $source, \Generator $events, int $count): array
    {
        $trades = [];
        for ($index = 0; $index < $count; ++$index) {
            if ($index > 0) {
                $events->next();
            }
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $trades[] = [
                (string) ($event->payload['trade_id'] ?? ''),
                (string) ($event->payload['origin'] ?? ''),
            ];
            $source->acknowledge($event->eventId);
        }

        return $trades;
    }

    private function seedSaturatedIdentityCheckpoint(): void
    {
        $seed = new OkxPaperLiveCheckpointStore($this->testRoot);
        $seed->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($seed);
        gc_collect_cycles();

        $state = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            foreach (['public_trade', 'candle_1m', 'candle_5m', 'candle_15m', 'candle_1H'] as $channel) {
                $stream = $symbol . '/' . $channel;
                $window = OkxPaperLivePolicy::acknowledgedIdentityHistoryWindow($stream);
                $state['acknowledged_identity_history'][$stream] = array_map(
                    static fn (int $index): string => OkxPaperAcknowledgedIdentityEntry::compact([
                        hash(
                            'sha256',
                            $stream . '|identity|' . $index,
                        ),
                        hash(
                            'sha256',
                            $stream . '|overlap|' . $index,
                        ),
                        hash(
                            'sha256',
                            $stream . '|rest|' . $index,
                        ),
                        OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST,
                    ]),
                    range(1, $window),
                );
            }
        }
        $encoded = CanonicalJson::encode(
            OkxPaperLiveCheckpoint::fromArray($state)->toArray(),
        ) . "\n";
        self::assertLessThan(700_000, \strlen($encoded));
        self::assertLessThanOrEqual(OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES, \strlen($encoded));
        self::assertNotFalse(file_put_contents(
            $this->testRoot . '/checkpoints/okx-live/checkpoint.json',
            $encoded,
        ));
    }

    private static function paddedTradeFrame(string $tradeId, int $bytes): string
    {
        $frame = json_encode(
            Task7Transport::tradeFrame([$tradeId]),
            \JSON_THROW_ON_ERROR,
        );
        if (\strlen($frame) > $bytes) {
            throw new \LogicException('task8_test_frame_target_too_small');
        }

        return $frame . str_repeat(' ', $bytes - \strlen($frame));
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
        ?OkxPaperInstrumentMetadataClientInterface $metadataClient = null,
        ?OkxPaperFundingRateClientInterface $fundingClient = null,
        int $initialHourlyCandleTarget = 1,
        ?OkxPaperLoopPumpInterface $loopPump = null,
        ?bool $anchoredTradeJunctions = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?int $inboundBufferMaxBytes = null,
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
            metadataClient: $metadataClient,
            fundingClient: $fundingClient,
            initialHourlyCandleTarget: $initialHourlyCandleTarget,
            loopPump: $loopPump,
            // OKX_PAPER_TEST_ANCHORED_JUNCTIONS=1 runs the legacy-path tests with the
            // production junction (measurement for retiring the legacy path).
            anchoredTradeJunctions: $anchoredTradeJunctions
                ?? getenv('OKX_PAPER_TEST_ANCHORED_JUNCTIONS') === '1',
            logger: $logger,
            inboundBufferMaxBytes: $inboundBufferMaxBytes,
        );
    }

    /** @return list<array{string, string, string, string, string, string, string, string, string}> */
    private static function contiguousHourlyRows(int $count): array
    {
        $start = new \DateTimeImmutable('2026-06-01T00:00:00Z');
        $rows = [];
        for ($index = 0; $index < $count; ++$index) {
            $rows[] = [
                $start->modify(sprintf('+%d hours', $index))->format('Uv'),
                '100', '101', '99', '100.5', '10', '1', '1000', '1',
            ];
        }

        return $rows;
    }

    private function acknowledgeWarmupEvents(
        OkxPaperPublicLiveSource $source,
        \Generator $events,
        int $count,
        bool $advanceAfterLast = true,
    ): void {
        for ($index = 0; $index < $count; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < $count - 1 || $advanceAfterLast) {
                $events->next();
            }
        }
    }

    /**
     * @return array{
     *     source: OkxPaperPublicLiveSource,
     *     events: \Generator,
     *     replacement: PaperMarketEvent,
     *     loop: DeterministicLoop,
     *     public_queue: OkxPaperPublicFrameQueue,
     *     store: OkxPaperLiveCheckpointStore,
     *     public: Task7Transport,
     *     business: Task7Transport
     * }
     */
    private function sourceAtGapReplacement(
        ?OkxPaperLiveCheckpointStore $checkpointStore = null,
    ): array
    {
        $clock = new MockClock('2026-07-25T10:00:00.000000Z');
        $store = $checkpointStore
            ?? new OkxPaperLiveCheckpointStore($this->testRoot, clock: $clock);
        $rest = Task7RestClient::withInitialDataset();
        $public = new Task7Transport();
        $business = new Task7Transport();
        $public->responses = [
            ...Task7Transport::acknowledgements(self::publicArguments(), 'public'),
            Task7Transport::bookFrame('9002', '9001', '4'),
            Task7Transport::bookFrame('9005', '9004', '5'),
        ];
        $business->responses = Task7Transport::acknowledgements(
            self::businessArguments(),
            'business',
        );
        $publicQueue = new OkxPaperPublicFrameQueue();
        $loop = new DeterministicLoop();
        $source = $this->source(
            $rest,
            $public,
            $business,
            checkpointStore: $store,
            clock: $clock,
            loop: $loop,
            publicQueue: $publicQueue,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $this->acknowledgeWarmup($source, $events);
        $rest->bookRows['BTC-USDT-SWAP'] = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970301000',
            'seqId' => '9004',
        ]];
        $applied = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $applied);
        $source->acknowledge($applied->eventId);
        $events->next();
        $replacement = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replacement);

        return [
            'source' => $source,
            'events' => $events,
            'replacement' => $replacement,
            'loop' => $loop,
            'public_queue' => $publicQueue,
            'store' => $store,
            'public' => $public,
            'business' => $business,
        ];
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
        $identityRef = $state['acknowledged_identity_history_ref'] ?? null;
        if (\is_array($identityRef)) {
            $blob = file_get_contents(
                $this->testRoot . '/checkpoints/okx-live/acknowledged-identities-'
                    . $identityRef['sha256'] . '.bin',
            );
            self::assertIsString($blob);
            self::assertStringStartsWith("OKXI1\0", $blob);
            self::assertSame($identityRef['sha256'], hash('sha256', $blob));
            $history = json_decode(substr($blob, 6), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($history);
            unset($state['acknowledged_identity_history_ref']);
            $state['acknowledged_identity_history'] = $history;
        }

        return $state;
    }

    private function sourceCheckpoint(
        OkxPaperPublicLiveSource $source,
    ): OkxPaperLiveCheckpoint {
        $property = new \ReflectionProperty($source, 'checkpoint');
        $checkpoint = $property->getValue($source);
        self::assertInstanceOf(OkxPaperLiveCheckpoint::class, $checkpoint);

        return $checkpoint;
    }

    /** @return array<string, mixed> */
    private function materializedCheckpointState(OkxPaperLiveCheckpoint $checkpoint): array
    {
        $state = $checkpoint->toArray();
        unset($state['acknowledged_identity_history_ref']);
        if ($checkpoint->acknowledgedIdentityHistory !== []) {
            $state['acknowledged_identity_history'] =
                $checkpoint->acknowledgedIdentityHistory;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $transition
     */
    private function bookResyncCheckpoint(
        OkxPaperLiveCheckpoint $checkpoint,
        array $transition,
    ): OkxPaperLiveCheckpoint {
        $frontier = [
            'source_identity' => '9002',
            'natural_identity' => 'okx|BTC-USDT-SWAP|top_of_book|9002',
            'canonical_digest' => str_repeat('b', 64),
            'overlap_digest' => str_repeat('b', 64),
        ];
        $state = $checkpoint->toArray();
        $state['phase'] = 'resyncing';
        $state['remaining_symbols'] = ['BTCUSDT'];
        $state['remaining_boundaries'] = [[
            'symbol' => 'BTCUSDT',
            'reason' => 'sequence_gap',
        ]];
        $state['source_epochs']['BTCUSDT'] = 2;
        $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] = $frontier;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => '9002',
            'deadline_at' => '2026-07-25T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
            'book_snapshot' => null,
        ];
        $state['pending_transition'] = $transition;

        return OkxPaperLiveCheckpoint::fromArray($state);
    }

    private function replaceCheckpointInodeByteIdentically(): void
    {
        $checkpointPath = $this->testRoot . '/checkpoints/okx-live/checkpoint.json';
        $contents = file_get_contents($checkpointPath);
        self::assertIsString($contents);
        $before = fileinode($checkpointPath);
        self::assertIsInt($before);
        $replacement = dirname($checkpointPath) . '/.byte-identical-replacement';
        self::assertSame(\strlen($contents), file_put_contents($replacement, $contents));
        self::assertTrue(chmod($replacement, 0600));
        self::assertTrue(rename($replacement, $checkpointPath));
        clearstatcache(true, $checkpointPath);
        $after = fileinode($checkpointPath);
        self::assertIsInt($after);
        self::assertNotSame($before, $after);
    }

    /** @return list<string> */
    private function managedQueueBlobPaths(): array
    {
        return array_values(array_filter(
            glob($this->testRoot . '/checkpoints/okx-live/streaming-queues-*.bin')
                ?: [],
            static fn (string $path): bool => preg_match(
                '/\\Astreaming-queues-[a-f0-9]{64}\\.bin\\z/D',
                basename($path),
            ) === 1,
        ));
    }
}

final class StaticOkxPaperMetadataClient implements OkxPaperInstrumentMetadataClientInterface
{
    public function instrumentMetadata(string $instrumentId): array
    {
        $base = str_starts_with($instrumentId, 'BTC') ? 'BTC' : 'ETH';

        return [
            'instId' => $instrumentId, 'instType' => 'SWAP', 'ctType' => 'linear',
            'ctVal' => '0.01', 'ctMult' => '1', 'ctValCcy' => $base,
            'settleCcy' => 'USDT', 'tickSz' => '0.1', 'lotSz' => '1',
            'minSz' => '1', 'maxMktSz' => '10000', 'maxLmtSz' => '20000',
            'lever' => '100',
            'state' => 'live',
        ];
    }
}

final class StaticOkxPaperFundingClient implements OkxPaperFundingRateClientInterface
{
    public function fundingRate(string $instrumentId): array
    {
        return [
            'instId' => $instrumentId, 'instType' => 'SWAP', 'fundingRate' => '0.0001',
            'fundingTime' => '1784995200000', 'nextFundingTime' => '1785024000000',
            'method' => 'current_period', 'formulaType' => 'withRate',
            'settState' => 'settled', 'ts' => '1784969999000',
        ];
    }
}

final class Task7RestClient implements OkxPaperPublicRestClientInterface
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    public array $candleRows = [];

    /** @var array<string, list<list<array<array-key, mixed>>>> */
    public array $candleResponsePages = [];

    public ?\Closure $beforeCurrentCandles = null;

    /** @var array<string, list<array<array-key, mixed>>> */
    public array $tradeRows = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    public array $bookRows = [];

    /** @var list<list<array<array-key, mixed>>> */
    public array $historyTradePages = [];

    public ?\Closure $beforeHistoryTrades = null;

    private int $historyTradeCalls = 0;

    /** @var list<list<array<array-key, mixed>>> */
    public array $historyCandlePages = [];

    /** @var array<string, list<list<array<array-key, mixed>>>> */
    public array $bookResponsePages = [];

    public ?\Closure $beforeOrderBook = null;

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

    /** @return list<array{string, list<mixed>}> */
    public static function expectedReconnectCalls(): array
    {
        $calls = [];
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentId) {
            $calls[] = ['orderBook', [$instrumentId, 400]];
            foreach (['15m', '1H', '1m', '5m'] as $bar) {
                $calls[] = ['currentCandles', [$instrumentId, $bar, null, null, 300]];
            }
            $calls[] = ['recentTrades', [$instrumentId, 500]];
        }

        return $calls;
    }

    /**
     * REST calls made once subscribed: the initial trade bridge (one recent-trade
     * snapshot per symbol, whose rows already contain the warmup frontier here),
     * then the initial candle bridge.
     *
     * @return list<array{string, list<mixed>}>
     */
    public static function expectedInitialBridgeCalls(): array
    {
        return self::expectedInitialCandleBridgeCalls();
    }

    /** @return list<array{string, list<mixed>}> */
    public static function expectedInitialCandleBridgeCalls(): array
    {
        $calls = [];
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentId) {
            foreach (['1m', '5m', '15m', '1H'] as $bar) {
                $calls[] = ['currentCandles', [$instrumentId, $bar, null, null, 300]];
            }
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

        return array_shift($this->historyCandlePages) ?? [];
    }

    public function currentCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        ?string $before = null,
        int $limit = 300,
    ): array {
        $this->calls[] = ['currentCandles', func_get_args()];

        $key = $instrumentId . '/' . $bar;
        $call = count(array_filter(
            $this->calls,
            static fn (array $entry): bool => $entry[0] === 'currentCandles'
                && ($entry[1][0] ?? null) === $instrumentId
                && ($entry[1][1] ?? null) === $bar,
        ));
        ($this->beforeCurrentCandles ?? static function (): void {
        })($instrumentId, $bar, $call);
        if (($this->candleResponsePages[$key] ?? []) !== []) {
            return array_shift($this->candleResponsePages[$key]);
        }

        return $this->candleRows[$key] ?? [];
    }

    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array {
        $this->calls[] = ['historyTrades', func_get_args()];
        ++$this->historyTradeCalls;
        if ($this->beforeHistoryTrades !== null) {
            ($this->beforeHistoryTrades)($this->historyTradeCalls);
        }

        return array_shift($this->historyTradePages) ?? [];
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        $this->calls[] = ['recentTrades', func_get_args()];

        return $this->tradeRows[$instrumentId] ?? [];
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        $this->calls[] = ['orderBook', func_get_args()];
        ($this->beforeOrderBook ?? static function (): void {
        })();

        if (($this->bookResponsePages[$instrumentId] ?? []) !== []) {
            return array_shift($this->bookResponsePages[$instrumentId]);
        }

        return $this->bookRows[$instrumentId] ?? [];
    }
}

final class Task7Transport implements OkxPaperPausableWebSocketTransportInterface
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
    public int $pauseCount = 0;
    public int $resumeCount = 0;
    public ?\Closure $afterResume = null;

    private ?\Closure $onMessage = null;
    private ?\Closure $onClose = null;
    private bool $connected = false;

    public function __construct(
        private readonly ?string $name = null,
        private readonly ?Task7ActionLog $actionLog = null,
        private readonly ?string $failOn = null,
        private readonly ?\Closure $beforeAction = null,
        private readonly ?\Closure $afterSend = null,
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
        $this->onClose = \Closure::fromCallable($onClose);
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
        ($this->afterSend ?? static function (): void {
        })();
    }

    /** @param array<array-key, mixed>|string $message */
    public function message(array|string $message): void
    {
        ($this->onMessage ?? throw new \LogicException('transport_not_connected'))(
            \is_string($message) ? $message : json_encode($message, \JSON_THROW_ON_ERROR),
        );
    }

    public function close(): void
    {
        $this->recordAndMaybeFail('close');
        ++$this->closeCount;
        $this->connected = false;
        $this->onMessage = null;
        $this->onClose = null;
    }

    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }
        $this->connected = false;
        ($this->onClose ?? throw new \LogicException('transport_not_connected'))();
    }

    public function pause(): void
    {
        ++$this->pauseCount;
    }

    public function resume(): void
    {
        ++$this->resumeCount;
        ($this->afterResume ?? static function (): void {})();
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
        ($this->beforeAction ?? static function (): void {
        })($operation);
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

final class Task8FailNextCheckpointSyncFilesystem extends \App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem
{
    public bool $failNextCheckpointSync = false;
    public bool $failNextQueueSync = false;
    public ?string $failNextOperation = null;
    public bool $crashAtCheckpointSync = false;
    public bool $crashAtQueueSync = false;
    public ?string $crashAfterMoveOperation = null;
    public ?string $failNextRemoveOperation = null;
    public ?string $replaceAfterSecondPathStat = null;
    public ?string $replaceBeforeRemoveOperation = null;
    public ?string $replacementTarget = null;
    public ?string $replacementBackup = null;
    public ?string $replacementPath = null;
    public bool $raceTriggered = false;

    /** @var list<string> */
    public array $operations = [];

    /** @var array<string, int> */
    private array $pathStatCounts = [];

    public function move(string $source, string $destination, string $operation): bool
    {
        $this->operations[] = 'move:' . $operation;
        if ($this->failNextOperation === $operation) {
            $this->failNextOperation = null;

            return false;
        }

        $moved = parent::move($source, $destination, $operation);
        if ($moved && $this->crashAfterMoveOperation === $operation) {
            exit(88);
        }

        return $moved;
    }

    public function removeFile(
        string $path,
        array $expectedStatistics,
        string $operation,
    ): bool {
        $this->operations[] = 'remove:' . $operation;
        if ($this->failNextRemoveOperation === $operation) {
            $this->failNextRemoveOperation = null;

            return false;
        }
        if ($this->replaceBeforeRemoveOperation === $operation) {
            $this->replaceBeforeRemoveOperation = null;
            $backup = $this->replacementBackup
                ?? throw new \LogicException('task8_race_backup_missing');
            $target = $this->replacementTarget
                ?? throw new \LogicException('task8_race_target_missing');
            if (!rename($path, $backup) || !symlink($target, $path)) {
                throw new \RuntimeException('task8_race_injection_failed');
            }
            $this->replacementPath = $path;
            $this->raceTriggered = true;
        }

        return parent::removeFile($path, $expectedStatistics, $operation);
    }

    public function pathStat(string $path, string $operation): array|false
    {
        $statistics = parent::pathStat($path, $operation);
        if ($path !== $this->replaceAfterSecondPathStat) {
            return $statistics;
        }
        $count = ($this->pathStatCounts[$path] ?? 0) + 1;
        $this->pathStatCounts[$path] = $count;
        if ($count === 2 && $statistics !== false) {
            $backup = $this->replacementBackup
                ?? throw new \LogicException('task8_race_backup_missing');
            $target = $this->replacementTarget
                ?? throw new \LogicException('task8_race_target_missing');
            if (!rename($path, $backup) || !symlink($target, $path)) {
                throw new \RuntimeException('task8_race_injection_failed');
            }
            $this->raceTriggered = true;
        }

        return $statistics;
    }

    public function sync($handle, string $operation): bool
    {
        $this->operations[] = 'sync:' . $operation;
        if ($this->crashAtCheckpointSync
            && $operation === 'okx_paper_live_checkpoint_sync'
        ) {
            exit(86);
        }
        if ($this->crashAtQueueSync
            && $operation === 'okx_paper_live_queue_sync'
        ) {
            exit(90);
        }
        if ($this->failNextOperation === $operation) {
            $this->failNextOperation = null;

            return false;
        }
        if ($this->failNextQueueSync
            && $operation === 'okx_paper_live_queue_sync'
        ) {
            $this->failNextQueueSync = false;

            return false;
        }
        if ($this->failNextCheckpointSync
            && $operation === 'okx_paper_live_checkpoint_sync'
        ) {
            $this->failNextCheckpointSync = false;

            return false;
        }

        return parent::sync($handle, $operation);
    }
}

final class Task7ActionLog
{
    /** @var list<string> */
    public array $actions = [];
}

final class Task7CountingLoopPump implements OkxPaperLoopPumpInterface
{
    public int $count = 0;

    public function __construct(private ?\Closure $callback = null)
    {
    }

    public function onPump(?\Closure $callback): void
    {
        $this->callback = $callback;
    }

    public function pump(): void
    {
        ++$this->count;
        ($this->callback ?? static function (): void {
        })();
    }
}

final class Task7ScriptedLoop implements LoopInterface
{
    /** @var list<callable> */
    public array $scripts = [];

    private bool $running = false;

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
        if ($this->running) {
            throw new \RuntimeException('loop_reentrant');
        }
        $this->running = true;
        try {
            $script = array_shift($this->scripts);
            if ($script !== null) {
                $script();
            }
            $this->delegate->run();
        } finally {
            $this->running = false;
        }
    }

    public function invokeWhileRunning(callable $callback): void
    {
        if ($this->running) {
            throw new \RuntimeException('loop_reentrant');
        }
        $this->running = true;
        try {
            $callback();
        } finally {
            $this->running = false;
        }
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

final class OkxRecordingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /** @param array<array-key, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->records, 'message');
    }

    /** @return array<array-key, mixed> */
    public function first(string $message): array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                \PHPUnit\Framework\Assert::assertSame('warning', $record['level']);

                return $record['context'];
            }
        }
        \PHPUnit\Framework\Assert::fail('No ' . $message . ' log record.');
    }
}
