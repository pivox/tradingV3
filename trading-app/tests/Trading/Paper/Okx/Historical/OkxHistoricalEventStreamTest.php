<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Historical;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperHistoricalDatasetBuilder;
use App\Trading\Paper\MarketData\AcknowledgedPaperMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Historical\OkxHistoricalCheckpointStore;
use App\Trading\Paper\Okx\Historical\OkxHistoricalEventStream;
use App\Trading\Paper\Okx\Historical\OkxHistoricalIntegrityException;
use App\Trading\Paper\Okx\Historical\OkxHistoricalRequest;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxHistoricalEventStream::class)]
#[CoversClass(PaperHistoricalDatasetBuilder::class)]
final class OkxHistoricalEventStreamTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'okx-history-test-');
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

    public function testReversedPagesProduceChronologicalBoundedDatasetWithoutLosingSameMillisecondTrades(): void
    {
        $request = $this->request('okx-history-happy-001');
        $client = ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7);
        $recorder = $this->recorder($request);
        $source = new OkxHistoricalEventStream(
            restClient: $client,
            clock: new MockClock('2026-07-21T12:00:00.000000Z'),
            request: $request,
            datasetDirectory: $recorder->datasetDirectory(),
        );

        $manifest = (new PaperHistoricalDatasetBuilder())->build($recorder, $source);

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        $events = $this->events($recorder->datasetDirectory());
        self::assertCount(283, $events);
        $sortKeys = array_map(
            static fn (array $event): string => implode('|', [
                $event['exchange_timestamp'],
                $event['channel'],
                $event['symbol'],
                (string) ($event['payload']['trade_id'] ?? $event['payload']['bar']),
            ]),
            $events,
        );
        $sortedKeys = $sortKeys;
        sort($sortedKeys, \SORT_STRING);
        self::assertSame($sortedKeys, $sortKeys);

        $trades = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['channel'] === 'public_trade',
        ));
        self::assertCount(206, $trades);
        self::assertCount(206, array_unique(array_column(array_column($trades, 'payload'), 'trade_id')));
        self::assertCount(205, array_filter(
            $trades,
            static fn (array $event): bool => $event['exchange_timestamp'] === '2026-07-21T10:30:00.123000Z',
        ));
        self::assertContains('2026-07-21T10:00:00.000000Z', array_column($trades, 'exchange_timestamp'));
        self::assertNotContains('2026-07-21T11:00:00.000000Z', array_column($trades, 'exchange_timestamp'));

        $tradeCalls = array_values(array_filter(
            $client->calls,
            static fn (array $call): bool => $call['method'] === 'historyTrades',
        ));
        self::assertSame(2, $tradeCalls[0]['pagination_type']);
        self::assertSame('1784631600000', $tradeCalls[0]['after']);
        self::assertSame(1, $tradeCalls[1]['pagination_type']);
        self::assertSame('1106', $tradeCalls[1]['after']);

        $candleCalls = array_values(array_filter(
            $client->calls,
            static fn (array $call): bool => $call['method'] === 'historyCandles'
                && $call['bar'] === '1m',
        ));
        self::assertGreaterThan(2, \count($candleCalls));
        self::assertSame('1784631600000', $candleCalls[0]['after']);
        self::assertLessThan((int) $candleCalls[0]['after'], (int) $candleCalls[1]['after']);

        $checkpointDirectory = $recorder->datasetDirectory() . '/checkpoints/okx-acquisition';
        self::assertSame(0700, fileperms($checkpointDirectory) & 0777);
        self::assertSame(0700, fileperms($checkpointDirectory . '/pages') & 0777);
        self::assertSame(0600, fileperms($checkpointDirectory . '/checkpoint.json') & 0777);
        foreach (glob($checkpointDirectory . '/pages/*.ndjson') ?: [] as $page) {
            self::assertSame(0600, fileperms($page) & 0777);
        }
        self::assertSame([], glob($checkpointDirectory . '/*.staging') ?: []);
        self::assertSame([], glob($checkpointDirectory . '/pages/*.staging') ?: []);
    }

    public function testMicrosecondBoundsAreExactForMillisecondEvents(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-microsecond-bounds-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000001Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000001Z'),
        );
        $client = ScriptedHistoricalRestClient::completeRange($request);
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(1, $manifest->eventCount);
        self::assertSame(
            ['2026-07-21T10:01:00.000000Z'],
            array_column($this->events($recorder->datasetDirectory()), 'exchange_timestamp'),
        );
        self::assertSame('1784628060001', $client->calls[0]['after']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingCandleProvider(): iterable
    {
        yield 'one minute' => ['1m', '1784629800000'];
        yield 'five minutes' => ['5m', '1784629800000'];
        yield 'fifteen minutes' => ['15m', '1784629800000'];
        yield 'one hour' => ['1H', '1784628000000'];
    }

    #[DataProvider('missingCandleProvider')]
    public function testMissingConfirmedCandleFailsClosedWithTerminalCheckpointEvidence(
        string $bar,
        string $timestamp,
    ): void
    {
        $request = $this->request('okx-history-gap-' . strtolower($bar));
        $client = ScriptedHistoricalRestClient::completeRange($request)
            ->withoutCandle($bar, $timestamp);
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                restClient: $client,
                clock: new MockClock('2026-07-21T12:00:00.000000Z'),
                request: $request,
                datasetDirectory: $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(0, $manifest->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('failed', $this->emission($recorder)['phase']);
        self::assertSame('okx_history_candle_grid_incomplete', $this->emission($recorder)['failure_reason']);
    }

    public function testRepeatedApiPageFailsClosedInsteadOfLoopingOrSilentlyDeduplicating(): void
    {
        $request = $this->request('okx-history-repeat-001');
        $client = ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7)
            ->withRepeatedCandlePage('1m');
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                restClient: $client,
                clock: new MockClock('2026-07-21T12:00:00.000000Z'),
                request: $request,
                datasetDirectory: $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('failed', $this->emission($recorder)['phase']);
        self::assertSame('okx_history_repeated_page', $this->emission($recorder)['failure_reason']);
        self::assertLessThan(4, \count($client->calls));
    }

    public function testTradeResponseForAnotherInstrumentFailsClosedBeforeRecorderAppend(): void
    {
        $request = $this->request('okx-history-inst-001');
        $client = ScriptedHistoricalRestClient::completeRange($request)
            ->withFirstTradeInstrument('ETH-USDT-SWAP');
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                restClient: $client,
                clock: new MockClock('2026-07-21T12:00:00.000000Z'),
                request: $request,
                datasetDirectory: $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(0, $manifest->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('okx_history_trade_instrument_mismatch', $this->emission($recorder)['failure_reason']);
    }

    public function testEmptyTradeResponseBeforeCrossingFromFailsClosedWithStableReason(): void
    {
        $request = $this->request('okx-history-empty-trades-001');
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request)->withoutTrades(),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(0, $manifest->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('failed', $this->emission($recorder)['phase']);
        self::assertSame('okx_history_trade_range_incomplete', $this->emission($recorder)['failure_reason']);
    }

    public function testCrashDuringFetchResumesAtExactCursorAndMatchesFreshHashes(): void
    {
        $request = $this->request('okx-history-fetch-resume-001');
        $freshRecorder = $this->recorder($request, $this->testRoot . '/fresh');
        $freshManifest = (new PaperHistoricalDatasetBuilder())->build(
            $freshRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $freshRecorder->datasetDirectory(),
            ),
        );
        self::assertSame(PaperDatasetState::COMPLETE, $freshManifest->state);

        $resumedRecorder = $this->recorder($request, $this->testRoot . '/resumed');
        $interrupting = new InterruptingHistoricalRestClient(
            ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7),
            failOnCall: 3,
        );
        $interruptedSource = new OkxHistoricalEventStream(
            $interrupting,
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $resumedRecorder->datasetDirectory(),
        );
        $this->assertRuntimeCrash(
            static fn (): array => iterator_to_array($interruptedSource->events()),
            'simulated_fetch_crash',
        );
        $this->simulateProcessExit($interruptedSource);
        unset($interruptedSource);
        gc_collect_cycles();
        self::assertSame(PaperDatasetState::RECORDING, $resumedRecorder->manifest()->state);
        $checkpointPath = $resumedRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json';
        $checkpoint = json_decode((string) file_get_contents($checkpointPath), true, 512, \JSON_THROW_ON_ERROR);
        $resumeCursor = $checkpoint['streams']['BTCUSDT/candle_1m']['next_cursor'];

        $callsBeforeResume = \count($interrupting->calls);
        $resumedManifest = (new PaperHistoricalDatasetBuilder())->build(
            $resumedRecorder,
            new OkxHistoricalEventStream(
                $interrupting,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $resumedManifest->state);
        self::assertSame($resumeCursor, $interrupting->calls[$callsBeforeResume]['after']);
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/events.ndjson'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/manifest.json'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/manifest.json'),
        );
    }

    public function testCrashAfterRecorderAppendReplaysPendingWithoutDuplicateAndMatchesFreshHashes(): void
    {
        $request = $this->request('okx-history-append-resume-001');
        $freshRecorder = $this->recorder($request, $this->testRoot . '/append-fresh');
        (new PaperHistoricalDatasetBuilder())->build(
            $freshRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $freshRecorder->datasetDirectory(),
            ),
        );

        $resumedRecorder = $this->recorder($request, $this->testRoot . '/append-resumed');
        $inner = new OkxHistoricalEventStream(
            ScriptedHistoricalRestClient::completeRange($request),
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $resumedRecorder->datasetDirectory(),
        );
        $this->assertRuntimeCrash(static function () use ($resumedRecorder, $inner): void {
            (new PaperHistoricalDatasetBuilder())->build(
                $resumedRecorder,
                new CrashAfterFirstAppendSource($inner),
            );
        }, 'simulated_post_append_crash');
        $this->simulateProcessExit($inner);
        unset($inner);
        gc_collect_cycles();
        self::assertSame(1, $resumedRecorder->manifest()->eventCount);
        $pendingCheckpoint = json_decode(
            (string) file_get_contents($resumedRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame(0, $this->emission($resumedRecorder)['emit_index']);
        self::assertSame(
            $resumedRecorder->manifest()->lastEventId,
            $this->emission($resumedRecorder)['pending_event']['event']['event_id'],
        );
        self::assertSame(
            '1',
            $this->emission($resumedRecorder)['ordinal_state']['scopes']['okx/BTCUSDT/candle_15m']['last_sequence'],
        );

        $resumedManifest = (new PaperHistoricalDatasetBuilder())->build(
            $resumedRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $resumedManifest->state);
        self::assertSame(283, $resumedManifest->eventCount);
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/events.ndjson'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/manifest.json'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/manifest.json'),
        );
        $completedCheckpoint = json_decode(
            (string) file_get_contents($resumedRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('complete', $this->emission($resumedRecorder)['phase']);
        self::assertSame(283, $this->emission($resumedRecorder)['emit_index']);
        self::assertNull($this->emission($resumedRecorder)['pending_event']);
    }

    public function testPendingEventAndOrdinalAreDurableBeforeRecorderAppend(): void
    {
        $request = $this->request('okx-history-before-append-001');
        $freshRecorder = $this->recorder($request, $this->testRoot . '/before-append-fresh');
        (new PaperHistoricalDatasetBuilder())->build(
            $freshRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $freshRecorder->datasetDirectory(),
            ),
        );

        $resumedRecorder = $this->recorder($request, $this->testRoot . '/before-append-resumed');
        $interrupted = new OkxHistoricalEventStream(
            ScriptedHistoricalRestClient::completeRange($request),
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $resumedRecorder->datasetDirectory(),
        );
        $iterator = $interrupted->events();
        self::assertInstanceOf(\Generator::class, $iterator);
        $iterator->rewind();
        $pendingEvent = $iterator->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pendingEvent);
        self::assertSame(0, $resumedRecorder->manifest()->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($resumedRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame($pendingEvent->eventId, $this->emission($resumedRecorder)['pending_event']['event']['event_id']);
        self::assertSame('1', $this->emission($resumedRecorder)['ordinal_state']['scopes']['okx/BTCUSDT/candle_15m']['last_sequence']);
        unset($iterator, $interrupted);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $resumedRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/events.ndjson'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/manifest.json'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/manifest.json'),
        );
    }

    public function testStopDuringEmissionKeepsDatasetRecordingAndResumable(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-stop-emission-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
        );
        $recorder = $this->recorder($request);

        $interrupted = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new StopAfterFirstAcknowledgementSource(new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            )),
        );

        self::assertSame(PaperDatasetState::RECORDING, $interrupted->state);
        self::assertSame(1, $interrupted->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('emitting', $this->emission($recorder)['phase']);
        self::assertSame(1, $this->emission($recorder)['emit_index']);
        self::assertNull($this->emission($recorder)['pending_event']);

        $completed = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $completed->state);
        self::assertSame(5, $completed->eventCount);
    }

    public function testStopAfterLastAcknowledgementKeepsEmissionResumableAndMatchesFreshHashes(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-stop-last-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
        );
        $freshRecorder = $this->recorder($request, $this->testRoot . '/stop-last-fresh');
        $freshManifest = (new PaperHistoricalDatasetBuilder())->build(
            $freshRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $freshRecorder->datasetDirectory(),
            ),
        );
        self::assertSame(PaperDatasetState::COMPLETE, $freshManifest->state);
        self::assertSame(5, $freshManifest->eventCount);

        $resumedRecorder = $this->recorder($request, $this->testRoot . '/stop-last-resumed');
        $interrupted = (new PaperHistoricalDatasetBuilder())->build(
            $resumedRecorder,
            new StopAfterFirstAcknowledgementSource(new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            ), stopAfterAcknowledgements: 5),
        );

        self::assertSame(PaperDatasetState::RECORDING, $interrupted->state);
        self::assertSame(5, $interrupted->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($resumedRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('emitting', $this->emission($resumedRecorder)['phase']);
        self::assertSame(5, $this->emission($resumedRecorder)['emit_index']);
        self::assertNull($this->emission($resumedRecorder)['pending_event']);

        $resumedManifest = (new PaperHistoricalDatasetBuilder())->build(
            $resumedRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $resumedManifest->state);
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/events.ndjson'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/manifest.json'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/manifest.json'),
        );
    }

    public function testAdvancedClockDoesNotRejectEmittingOrCompleteResumeWithoutNetwork(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-clock-resume-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
        );
        $recorder = $this->recorder($request);
        $initial = new OkxHistoricalEventStream(
            ScriptedHistoricalRestClient::completeRange($request),
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $recorder->datasetDirectory(),
        );
        $iterator = $initial->events();
        self::assertInstanceOf(\Generator::class, $iterator);
        $iterator->rewind();
        unset($iterator, $initial);

        $emittingClient = ScriptedHistoricalRestClient::completeRange($request);
        $completed = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $emittingClient,
                new MockClock('2026-11-01T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $completed->state);
        self::assertSame(5, $completed->eventCount);
        self::assertSame([], $emittingClient->calls);

        $completeClient = ScriptedHistoricalRestClient::completeRange($request);
        $reopened = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $completeClient,
                new MockClock('2026-12-01T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $reopened->state);
        self::assertSame([], $completeClient->calls);
    }

    public function testIdenticalTradeOverlappingAdjacentPagesIsGloballyDeduplicatedByInstrumentAndTradeId(): void
    {
        $request = $this->request('okx-history-overlap-001');
        $client = ScriptedHistoricalRestClient::completeRange($request)->withTradeBoundaryOverlap();
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        $trades = array_values(array_filter(
            $this->events($recorder->datasetDirectory()),
            static fn (array $event): bool => $event['channel'] === 'public_trade',
        ));
        self::assertCount(206, $trades);
        self::assertCount(206, array_unique(array_map(
            static fn (array $event): string => $event['payload']['native_symbol'] . '|' . $event['payload']['trade_id'],
            $trades,
        )));

        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            '999',
            $checkpoint['streams']['BTCUSDT/public_trade']['durable_frontier']['source_identity'],
        );
        self::assertArrayHasKey('source_digest', $checkpoint['streams']['BTCUSDT/public_trade']['durable_frontier']);
    }

    public function testTradePageSpanningBothSidesOfPreviousTemporalBoundaryFailsClosed(): void
    {
        $request = $this->request('okx-history-straddling-trade-page-001');
        $client = new StraddlingTradePageRestClient(ScriptedHistoricalRestClient::completeRange($request));
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame('okx_history_trade_cursor_not_progressing', $this->emission($recorder)['failure_reason']);
    }

    public function testNonAdjacentTradeOverlapIsRejectedByTheDurableFrontier(): void
    {
        $request = $this->request('okx-history-non-adjacent-overlap-001');
        $client = new NonAdjacentTradeOverlapRestClient(ScriptedHistoricalRestClient::completeRange($request));
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $emission = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/emission.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('1006', $checkpoint['streams']['BTCUSDT/public_trade']['durable_frontier']['source_identity']);
        self::assertSame('okx_history_trade_cursor_regression', $emission['failure_reason']);
    }

    public function testConflictingTradeDuplicateAcrossPagesFailsClosed(): void
    {
        $request = $this->request('okx-history-conflict-001');
        $client = ScriptedHistoricalRestClient::completeRange($request)->withConflictingTradeBoundaryOverlap();
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(0, $manifest->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('okx_history_natural_identity_conflict', $this->emission($recorder)['failure_reason']);
    }

    public function testSameTradeIdAndTimestampOnTwoInstrumentsRemainDistinctNaturalIdentities(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-two-symbols-001',
            symbols: ['ETHUSDT', 'BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
        );
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(566, $manifest->eventCount);
        $matching = array_values(array_filter(
            $this->events($recorder->datasetDirectory()),
            static fn (array $event): bool => $event['channel'] === 'public_trade'
                && $event['payload']['trade_id'] === '1000',
        ));
        self::assertSame(['BTCUSDT', 'ETHUSDT'], array_column($matching, 'symbol'));
    }

    public function testPageBoundFailsClosedBeforeAnotherNetworkPageCanBeAccepted(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-page-bound-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
            maximumPages: 1,
        );
        $client = ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7);
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertCount(1, $client->calls);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('okx_history_page_bound_exceeded', $this->emission($recorder)['failure_reason']);
    }

    public function testAcceptedEmptyCandlePageCountsDurablyAcrossResume(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-empty-page-count-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
            maximumPages: 8,
        );
        $freshClient = ScriptedHistoricalRestClient::completeRange($request)
            ->withoutCandlesBeforeFrom('1m');
        $freshRecorder = $this->recorder($request, $this->testRoot . '/empty-page-fresh');

        $freshManifest = (new PaperHistoricalDatasetBuilder())->build(
            $freshRecorder,
            new OkxHistoricalEventStream(
                $freshClient,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $freshRecorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $freshManifest->state);
        $freshCheckpoint = json_decode(
            (string) file_get_contents($freshRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame(8, $freshCheckpoint['page_count']);
        self::assertCount(8, $freshClient->calls);

        $resumedRecorder = $this->recorder($request, $this->testRoot . '/empty-page-resumed');
        $resumedClient = new InterruptingHistoricalRestClient(
            ScriptedHistoricalRestClient::completeRange($request)->withoutCandlesBeforeFrom('1m'),
            failOnCall: 3,
        );
        $interruptedSource = new OkxHistoricalEventStream(
            $resumedClient,
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $resumedRecorder->datasetDirectory(),
        );
        $this->assertRuntimeCrash(static function () use ($resumedRecorder, $interruptedSource): void {
            (new PaperHistoricalDatasetBuilder())->build(
                $resumedRecorder,
                $interruptedSource,
            );
        }, 'simulated_fetch_crash');
        $this->simulateProcessExit($interruptedSource);
        unset($interruptedSource);
        gc_collect_cycles();
        $interruptedCheckpoint = json_decode(
            (string) file_get_contents($resumedRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame(2, $interruptedCheckpoint['page_count']);
        self::assertTrue($interruptedCheckpoint['streams']['BTCUSDT/candle_1m']['complete']);

        $resumedManifest = (new PaperHistoricalDatasetBuilder())->build(
            $resumedRecorder,
            new OkxHistoricalEventStream(
                $resumedClient,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            ),
        );
        $completedCheckpoint = json_decode(
            (string) file_get_contents($resumedRecorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertSame(PaperDatasetState::COMPLETE, $resumedManifest->state);
        self::assertSame(8, $completedCheckpoint['page_count']);
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/events.ndjson'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/manifest.json'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/manifest.json'),
        );
    }

    public function testEventBoundFailsBeforeAnyEventIsAppended(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-event-bound-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
            maximumEvents: 282,
        );
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(0, $manifest->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('okx_history_event_bound_exceeded', $this->emission($recorder)['failure_reason']);
    }

    public function testMaximumEventsFailsBeforePersistingTheOverflowingPageOrRequestingAnotherPage(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-event-bound-early-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
            maximumEvents: 1,
        );
        $client = ScriptedHistoricalRestClient::completeRange($request);
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertCount(2, $client->calls);
        self::assertCount(
            1,
            glob($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/pages/*.ndjson') ?: [],
        );
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame(1, $checkpoint['event_count']);
    }

    public function testOversizedCandleResponseFailsBeforePersistenceOrAnotherRequest(): void
    {
        $request = $this->request('okx-history-oversized-candles-001');
        $client = new OversizedHistoricalRestClient(
            ScriptedHistoricalRestClient::completeRange($request),
            oversizedCandles: true,
        );
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(1, $client->callCount);
        self::assertSame([], glob($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/pages/*.ndjson') ?: []);
    }

    public function testOversizedTradeResponseFailsBeforeTradePersistenceOrAnotherRequest(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-oversized-trades-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
        );
        $client = new OversizedHistoricalRestClient(
            ScriptedHistoricalRestClient::completeRange($request),
            oversizedTrades: true,
        );
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(5, $client->callCount);
        self::assertCount(
            4,
            glob($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/pages/*.ndjson') ?: [],
        );
    }

    public function testSameTimestampTradeIdentifiersAreSortedNumerically(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-numeric-trade-order-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
        );
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                new NumericTradeIdentifierRestClient(ScriptedHistoricalRestClient::completeRange($request)),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        $tradeIds = array_map(
            static fn (array $event): string => $event['payload']['trade_id'],
            array_values(array_filter(
                $this->events($recorder->datasetDirectory()),
                static fn (array $event): bool => $event['channel'] === 'public_trade',
            )),
        );
        self::assertSame(['9', '10'], $tradeIds);

        $sortKey = new \ReflectionMethod(OkxHistoricalEventStream::class, 'sortKey');
        $record = [
            'kind' => 'trade',
            'symbol' => 'BTCUSDT',
            'native_symbol' => 'BTC-USDT-SWAP',
            'bar' => null,
            'exchange_timestamp_ms' => '1784628000123',
            'source_identity' => '9',
            'natural_identity' => 'BTC-USDT-SWAP|trade|9',
            'source_digest' => str_repeat('0', 64),
            'row' => [],
        ];
        $nine = $sortKey->invoke($source = new OkxHistoricalEventStream(
            new NumericTradeIdentifierRestClient(ScriptedHistoricalRestClient::completeRange($request)),
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $recorder->datasetDirectory(),
        ), $record);
        $record['source_identity'] = '10';
        $record['natural_identity'] = 'BTC-USDT-SWAP|trade|10';
        $ten = $sortKey->invoke($source, $record);
        self::assertLessThan(0, strcmp($nine, $ten));
    }

    public function testAcknowledgementOnlyAtomicallyRewritesCompactEmissionState(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-compact-emission-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
        );
        $recorder = $this->recorder($request);
        $source = new OkxHistoricalEventStream(
            ScriptedHistoricalRestClient::completeRange($request),
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $recorder->datasetDirectory(),
        );
        $iterator = $source->events();
        self::assertInstanceOf(\Generator::class, $iterator);
        $iterator->rewind();
        $event = $iterator->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);

        $directory = $recorder->datasetDirectory() . '/checkpoints/okx-acquisition';
        $checkpointBefore = file_get_contents($directory . '/checkpoint.json');
        self::assertIsString($checkpointBefore);
        $checkpoint = json_decode($checkpointBefore, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            ['dataset_id', 'event_count', 'page_count', 'request_sha256', 'schema_version', 'streams'],
            array_keys($checkpoint),
        );
        $emissionBefore = json_decode(
            (string) file_get_contents($directory . '/emission.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            ['dataset_id', 'emit_index', 'ordinal_state', 'pending_event', 'phase', 'request_sha256', 'schema_version'],
            array_keys($emissionBefore),
        );
        self::assertSame($event->eventId, $emissionBefore['pending_event']['event']['event_id']);

        $source->acknowledge($event->eventId);

        self::assertSame($checkpointBefore, file_get_contents($directory . '/checkpoint.json'));
        $emissionAfter = json_decode(
            (string) file_get_contents($directory . '/emission.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame(1, $emissionAfter['emit_index']);
        self::assertNull($emissionAfter['pending_event']);
        self::assertSame(0600, fileperms($directory . '/emission.json') & 0777);
        self::assertFileDoesNotExist($directory . '/emission.json.staging');
    }

    public function testChangedRequestRefusesExistingCheckpointWithoutNetworkCall(): void
    {
        $request = $this->request('okx-history-request-change-001');
        $recorder = $this->recorder($request);
        $interrupting = new InterruptingHistoricalRestClient(
            ScriptedHistoricalRestClient::completeRange($request),
            failOnCall: 2,
        );
        try {
            iterator_to_array((new OkxHistoricalEventStream(
                $interrupting,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ))->events());
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated_fetch_crash', $exception->getMessage());
        }
        $callsBeforeMismatch = \count($interrupting->calls);
        $changed = new OkxHistoricalRequest(
            datasetId: $request->datasetId,
            symbols: ['BTCUSDT'],
            from: $request->from,
            to: new \DateTimeImmutable('2026-07-21T11:01:00.000000Z'),
        );

        try {
            new OkxHistoricalEventStream(
                $interrupting,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $changed,
                $recorder->datasetDirectory(),
            );
            self::fail('A changed request must not reuse the old checkpoint.');
        } catch (OkxHistoricalIntegrityException $exception) {
            self::assertSame('okx_acquisition_checkpoint_request_mismatch', $exception->getMessage());
        }
        self::assertSame($callsBeforeMismatch, \count($interrupting->calls));
    }

    public function testRegressingTradeIdCursorFailsClosedWithExplicitEvidence(): void
    {
        $request = $this->request('okx-history-cursor-regression-001');
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                new RegressingTradeCursorRestClient(ScriptedHistoricalRestClient::completeRange($request)),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('okx_history_trade_cursor_regression', $this->emission($recorder)['failure_reason']);
    }

    public function testRepeatedProcessCrashesAcrossFetchPageBoundariesRemainDeterministic(): void
    {
        $request = $this->request('okx-history-all-fetch-boundaries-001');
        $freshRecorder = $this->recorder($request, $this->testRoot . '/all-fetch-fresh');
        (new PaperHistoricalDatasetBuilder())->build(
            $freshRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $freshRecorder->datasetDirectory(),
            ),
        );

        $resumedRecorder = $this->recorder($request, $this->testRoot . '/all-fetch-resumed');
        $client = new CrashBetweenEveryFetchPageRestClient(
            ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7),
        );
        $crashes = 0;
        while ($resumedRecorder->manifest()->state === PaperDatasetState::RECORDING) {
            $source = new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            );
            $failure = $this->runtimeFailureMessage(static function () use ($resumedRecorder, $source): void {
                (new PaperHistoricalDatasetBuilder())->build(
                    $resumedRecorder,
                    $source,
                );
            });
            $this->simulateProcessExit($source);
            unset($source);
            if ($failure !== null) {
                self::assertSame('simulated_fetch_boundary_crash', $failure);
                ++$crashes;
            }
            gc_collect_cycles();
        }

        self::assertGreaterThan(5, $crashes);
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/events.ndjson'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/manifest.json'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/manifest.json'),
        );
    }

    public function testCrashAtEveryAppendBoundaryOnSmallRangeMatchesFreshDataset(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-all-append-boundaries-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
        );
        $freshRecorder = $this->recorder($request, $this->testRoot . '/all-append-fresh');
        $freshManifest = (new PaperHistoricalDatasetBuilder())->build(
            $freshRecorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $freshRecorder->datasetDirectory(),
            ),
        );
        self::assertSame(5, $freshManifest->eventCount);

        $resumedRecorder = $this->recorder($request, $this->testRoot . '/all-append-resumed');
        $crashedEventIds = [];
        $crashes = 0;
        while ($resumedRecorder->manifest()->state === PaperDatasetState::RECORDING) {
            $source = new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $resumedRecorder->datasetDirectory(),
            );
            $failure = $this->runtimeFailureMessage(static function () use (
                $resumedRecorder,
                $source,
                &$crashedEventIds,
            ): void {
                (new PaperHistoricalDatasetBuilder())->build(
                    $resumedRecorder,
                    new CrashOnceAfterEachNewAppendSource(
                        $source,
                        $crashedEventIds,
                    ),
                );
            });
            $this->simulateProcessExit($source);
            unset($source);
            if ($failure !== null) {
                self::assertSame('simulated_post_append_boundary_crash', $failure);
                ++$crashes;
            }
            gc_collect_cycles();
        }

        self::assertSame(5, $crashes);
        self::assertCount(5, $crashedEventIds);
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/events.ndjson'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            hash_file('sha256', $freshRecorder->datasetDirectory() . '/manifest.json'),
            hash_file('sha256', $resumedRecorder->datasetDirectory() . '/manifest.json'),
        );
    }

    public function testCheckpointSymlinkIsRejectedInsteadOfFollowed(): void
    {
        $request = $this->request('okx-history-checkpoint-symlink-001');
        $recorder = $this->recorder($request);
        new OkxHistoricalEventStream(
            ScriptedHistoricalRestClient::completeRange($request),
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $recorder->datasetDirectory(),
        );
        $checkpoint = $recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json';
        $target = $recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint-target.json';
        self::assertTrue(rename($checkpoint, $target));
        self::assertTrue(symlink($target, $checkpoint));

        $this->expectException(OkxHistoricalIntegrityException::class);
        $this->expectExceptionMessage('okx_acquisition_file_invalid');

        new OkxHistoricalEventStream(
            ScriptedHistoricalRestClient::completeRange($request),
            new MockClock('2026-07-21T12:00:00.000000Z'),
            $request,
            $recorder->datasetDirectory(),
        );
    }

    public function testMalformedNormalizedRowFailsClosedBeforeAnyRecorderAppend(): void
    {
        $request = $this->request('okx-history-malformed-row-001');
        $recorder = $this->recorder($request);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                ScriptedHistoricalRestClient::completeRange($request)
                    ->withMalformedCandle('1m', '1784629800000'),
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(0, $manifest->eventCount);
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('okx_history_normalization_failed', $this->emission($recorder)['failure_reason']);
    }

    public function testChangedDurablePageContentMarksDatasetIncompleteBeforeNetworkAccess(): void
    {
        $request = $this->request('okx-history-page-tamper-001');
        $recorder = $this->recorder($request);
        $client = new InterruptingHistoricalRestClient(
            ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7),
            failOnCall: 2,
        );
        try {
            iterator_to_array((new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ))->events());
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated_fetch_crash', $exception->getMessage());
        }
        $pages = glob($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/pages/*.ndjson') ?: [];
        self::assertCount(1, $pages);
        self::assertNotFalse(file_put_contents($pages[0], "{}\n", \FILE_APPEND));
        $callsBeforeResume = \count($client->calls);

        $manifest = (new PaperHistoricalDatasetBuilder())->build(
            $recorder,
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame($callsBeforeResume, \count($client->calls));
        $checkpoint = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame('failed', $this->emission($recorder)['phase']);
        self::assertSame('okx_acquisition_page_hash_mismatch', $this->emission($recorder)['failure_reason']);
    }

    public function testCheckpointPageTraversalIsRejectedAtLoadBeforeNetworkAccess(): void
    {
        $request = $this->request('okx-history-page-traversal-001');
        $recorder = $this->recorder($request);
        $client = new InterruptingHistoricalRestClient(
            ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7),
            failOnCall: 2,
        );
        try {
            iterator_to_array((new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            ))->events());
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated_fetch_crash', $exception->getMessage());
        }
        $checkpointPath = $recorder->datasetDirectory() . '/checkpoints/okx-acquisition/checkpoint.json';
        $checkpoint = json_decode(
            (string) file_get_contents($checkpointPath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $outsidePage = $recorder->datasetDirectory() . '/manifest.json';
        $outsideSha256 = hash_file('sha256', $outsidePage);
        self::assertIsString($outsideSha256);
        $checkpoint['streams']['BTCUSDT/candle_1m']['pages'][0]['file'] = '../../../manifest.json';
        $checkpoint['streams']['BTCUSDT/candle_1m']['pages'][0]['sha256'] = $outsideSha256;
        $checkpoint['streams']['BTCUSDT/candle_1m']['pages'][0]['chain_sha256'] = hash(
            'sha256',
            str_repeat('0', 64) . $outsideSha256,
        );
        self::assertNotFalse(file_put_contents(
            $checkpointPath,
            json_encode($checkpoint, \JSON_THROW_ON_ERROR) . "\n",
        ));
        $callsBeforeResume = \count($client->calls);

        try {
            new OkxHistoricalEventStream(
                $client,
                new MockClock('2026-07-21T12:00:00.000000Z'),
                $request,
                $recorder->datasetDirectory(),
            );
            self::fail('A traversal page descriptor must be rejected while loading the checkpoint.');
        } catch (OkxHistoricalIntegrityException $exception) {
            self::assertSame('okx_acquisition_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($callsBeforeResume, \count($client->calls));
    }

    private function assertRuntimeCrash(\Closure $operation, string $expectedMessage): void
    {
        $actualMessage = $this->runtimeFailureMessage($operation);
        if ($actualMessage === null) {
            self::fail('The scripted process crash must interrupt historical acquisition.');
        }
        self::assertSame($expectedMessage, $actualMessage);
    }

    private function runtimeFailureMessage(\Closure $operation): ?string
    {
        try {
            $operation();
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function simulateProcessExit(OkxHistoricalEventStream $source): void
    {
        $storeProperty = new \ReflectionProperty($source, 'store');
        $store = $storeProperty->getValue($source);
        self::assertInstanceOf(OkxHistoricalCheckpointStore::class, $store);
        $store->__destruct();
    }

    private function request(string $datasetId): OkxHistoricalRequest
    {
        return new OkxHistoricalRequest(
            datasetId: $datasetId,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
        );
    }

    private function recorder(OkxHistoricalRequest $request, ?string $root = null): PaperDatasetRecorder
    {
        $symbols = [];
        foreach ($request->symbols as $symbol) {
            $symbols[$symbol] = match ($symbol) {
                'BTCUSDT' => 'BTC-USDT-SWAP',
                'ETHUSDT' => 'ETH-USDT-SWAP',
                default => throw new \LogicException('unexpected_test_symbol'),
            };
        }

        return new PaperDatasetRecorder($root ?? $this->testRoot, new PaperDatasetManifest(
            schemaVersion: 1,
            recorderVersion: '1.0.0',
            datasetId: $request->datasetId,
            venue: PaperMarketDataVenue::OKX,
            symbols: $symbols,
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
            modelName: 'historical_top_of_book_unavailable',
            modelVersion: '1',
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        ));
    }

    /** @return array<string, mixed> */
    private function emission(PaperDatasetRecorder $recorder): array
    {
        $state = json_decode(
            (string) file_get_contents($recorder->datasetDirectory() . '/checkpoints/okx-acquisition/emission.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($state);

        return $state;
    }

    /** @return list<array<string, mixed>> */
    private function events(string $datasetDirectory): array
    {
        $lines = file($datasetDirectory . '/events.ndjson', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_map(static function (string $line): array {
            $event = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($event);

            return $event;
        }, $lines);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

final class CrashAfterFirstAppendSource implements AcknowledgedPaperMarketDataSourceInterface
{
    private bool $crashed = false;

    public function __construct(private readonly OkxHistoricalEventStream $inner)
    {
    }

    public function venue(): PaperMarketDataVenue
    {
        return $this->inner->venue();
    }

    /** @return iterable<PaperMarketEvent> */
    public function events(): iterable
    {
        yield from $this->inner->events();
    }

    public function acknowledge(string $eventId): void
    {
        if (!$this->crashed) {
            $this->crashed = true;

            throw new \RuntimeException('simulated_post_append_crash');
        }
        $this->inner->acknowledge($eventId);
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function isComplete(): bool
    {
        return $this->inner->isComplete();
    }
}

final class StopAfterFirstAcknowledgementSource implements AcknowledgedPaperMarketDataSourceInterface
{
    private bool $stopped = false;
    private int $acknowledgements = 0;

    public function __construct(
        private readonly OkxHistoricalEventStream $inner,
        private readonly int $stopAfterAcknowledgements = 1,
    ) {
    }

    public function venue(): PaperMarketDataVenue
    {
        return $this->inner->venue();
    }

    /** @return iterable<PaperMarketEvent> */
    public function events(): iterable
    {
        yield from $this->inner->events();
    }

    public function acknowledge(string $eventId): void
    {
        $this->inner->acknowledge($eventId);
        ++$this->acknowledgements;
        if (!$this->stopped && $this->acknowledgements === $this->stopAfterAcknowledgements) {
            $this->stopped = true;
            $this->inner->stop();
        }
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function isComplete(): bool
    {
        return $this->inner->isComplete();
    }
}

final class InterruptingHistoricalRestClient implements OkxPaperPublicRestClientInterface
{
    /** @var list<array<string, int|string|null>> */
    public array $calls = [];
    private int $callCount = 0;
    private bool $failed = false;

    public function __construct(
        private readonly OkxPaperPublicRestClientInterface $inner,
        private readonly int $failOnCall,
    ) {
    }

    public function historyCandles(string $instrumentId, string $bar, ?string $after = null, int $limit = 300): array
    {
        $this->beforeCall(['method' => 'historyCandles', 'instrumentId' => $instrumentId, 'bar' => $bar, 'after' => $after]);

        return $this->inner->historyCandles($instrumentId, $bar, $after, $limit);
    }

    public function historyTrades(string $instrumentId, int $paginationType = 2, ?string $after = null, int $limit = 100): array
    {
        $this->beforeCall(['method' => 'historyTrades', 'instrumentId' => $instrumentId, 'pagination_type' => $paginationType, 'after' => $after]);

        return $this->inner->historyTrades($instrumentId, $paginationType, $after, $limit);
    }

    public function currentCandles(string $instrumentId, string $bar, ?string $after = null, ?string $before = null, int $limit = 300): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    /** @param array<string, int|string|null> $call */
    private function beforeCall(array $call): void
    {
        $this->calls[] = $call;
        ++$this->callCount;
        if (!$this->failed && $this->callCount === $this->failOnCall) {
            $this->failed = true;

            throw new \RuntimeException('simulated_fetch_crash');
        }
    }
}

final class RegressingTradeCursorRestClient implements OkxPaperPublicRestClientInterface
{
    public function __construct(private readonly OkxPaperPublicRestClientInterface $inner)
    {
    }

    public function historyCandles(string $instrumentId, string $bar, ?string $after = null, int $limit = 300): array
    {
        return $this->inner->historyCandles($instrumentId, $bar, $after, $limit);
    }

    public function historyTrades(string $instrumentId, int $paginationType = 2, ?string $after = null, int $limit = 100): array
    {
        $rows = $this->inner->historyTrades($instrumentId, $paginationType, $after, $limit);
        if ($paginationType === 1 && $after !== null && isset($rows[0])) {
            $rows[0]['tradeId'] = (string) ((int) $after + 1);
        }

        return $rows;
    }

    public function currentCandles(string $instrumentId, string $bar, ?string $after = null, ?string $before = null, int $limit = 300): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }
}

final class CrashBetweenEveryFetchPageRestClient implements OkxPaperPublicRestClientInterface
{
    private bool $crashNext = false;

    public function __construct(private readonly OkxPaperPublicRestClientInterface $inner)
    {
    }

    public function historyCandles(string $instrumentId, string $bar, ?string $after = null, int $limit = 300): array
    {
        $this->maybeCrash();
        $rows = $this->inner->historyCandles($instrumentId, $bar, $after, $limit);
        $this->crashNext = true;

        return $rows;
    }

    public function historyTrades(string $instrumentId, int $paginationType = 2, ?string $after = null, int $limit = 100): array
    {
        $this->maybeCrash();
        $rows = $this->inner->historyTrades($instrumentId, $paginationType, $after, $limit);
        $this->crashNext = true;

        return $rows;
    }

    public function currentCandles(string $instrumentId, string $bar, ?string $after = null, ?string $before = null, int $limit = 300): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    private function maybeCrash(): void
    {
        if (!$this->crashNext) {
            return;
        }
        $this->crashNext = false;

        throw new \RuntimeException('simulated_fetch_boundary_crash');
    }
}

final class CrashOnceAfterEachNewAppendSource implements AcknowledgedPaperMarketDataSourceInterface
{
    /** @var array<string, true> */
    private array $crashedEventIds;

    /** @param array<string, true> $crashedEventIds */
    public function __construct(
        private readonly OkxHistoricalEventStream $inner,
        array &$crashedEventIds,
    ) {
        $this->crashedEventIds =& $crashedEventIds;
    }

    public function venue(): PaperMarketDataVenue
    {
        return $this->inner->venue();
    }

    /** @return iterable<PaperMarketEvent> */
    public function events(): iterable
    {
        yield from $this->inner->events();
    }

    public function acknowledge(string $eventId): void
    {
        if (!isset($this->crashedEventIds[$eventId])) {
            $this->crashedEventIds[$eventId] = true;

            throw new \RuntimeException('simulated_post_append_boundary_crash');
        }
        $this->inner->acknowledge($eventId);
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function isComplete(): bool
    {
        return $this->inner->isComplete();
    }
}

final class NonAdjacentTradeOverlapRestClient implements OkxPaperPublicRestClientInterface
{
    private int $tradeCalls = 0;

    public function __construct(private readonly OkxPaperPublicRestClientInterface $inner)
    {
    }

    public function historyCandles(string $instrumentId, string $bar, ?string $after = null, int $limit = 300): array
    {
        return $this->inner->historyCandles($instrumentId, $bar, $after, $limit);
    }

    public function historyTrades(string $instrumentId, int $paginationType = 2, ?string $after = null, int $limit = 100): array
    {
        $rows = $this->inner->historyTrades($instrumentId, $paginationType, $after, $limit);
        ++$this->tradeCalls;
        if ($this->tradeCalls === 3) {
            array_unshift($rows, [
                'instId' => $instrumentId,
                'tradeId' => '1106',
                'px' => '65000.1',
                'sz' => '1',
                'side' => 'buy',
                'source' => '0',
                'ts' => '1784629800123',
            ]);
        }

        return $rows;
    }

    public function currentCandles(string $instrumentId, string $bar, ?string $after = null, ?string $before = null, int $limit = 300): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }
}

final class StraddlingTradePageRestClient implements OkxPaperPublicRestClientInterface
{
    private int $tradeCalls = 0;
    private ?string $previousOldestTimestamp = null;

    public function __construct(private readonly OkxPaperPublicRestClientInterface $inner)
    {
    }

    public function historyCandles(string $instrumentId, string $bar, ?string $after = null, int $limit = 300): array
    {
        return $this->inner->historyCandles($instrumentId, $bar, $after, $limit);
    }

    public function historyTrades(string $instrumentId, int $paginationType = 2, ?string $after = null, int $limit = 100): array
    {
        $rows = $this->inner->historyTrades($instrumentId, $paginationType, $after, $limit);
        ++$this->tradeCalls;
        if ($this->tradeCalls === 1 && $rows !== []) {
            $this->previousOldestTimestamp = min(array_column($rows, 'ts'));
        } elseif ($this->tradeCalls === 2 && \count($rows) >= 2 && $this->previousOldestTimestamp !== null) {
            $rows[0]['ts'] = (string) ((int) $this->previousOldestTimestamp + 1);
            $rows[array_key_last($rows)]['ts'] = (string) ((int) $this->previousOldestTimestamp - 1);
        }

        return $rows;
    }

    public function currentCandles(string $instrumentId, string $bar, ?string $after = null, ?string $before = null, int $limit = 300): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }
}

final class OversizedHistoricalRestClient implements OkxPaperPublicRestClientInterface
{
    public int $callCount = 0;

    public function __construct(
        private readonly OkxPaperPublicRestClientInterface $inner,
        private readonly bool $oversizedCandles = false,
        private readonly bool $oversizedTrades = false,
    ) {
    }

    public function historyCandles(string $instrumentId, string $bar, ?string $after = null, int $limit = 300): array
    {
        ++$this->callCount;
        $rows = $this->inner->historyCandles($instrumentId, $bar, $after, $limit);
        if ($this->oversizedCandles && $rows !== []) {
            return array_pad($rows, 301, $rows[array_key_last($rows)]);
        }

        return $rows;
    }

    public function historyTrades(string $instrumentId, int $paginationType = 2, ?string $after = null, int $limit = 100): array
    {
        ++$this->callCount;
        $rows = $this->inner->historyTrades($instrumentId, $paginationType, $after, $limit);
        if ($this->oversizedTrades && $rows !== []) {
            return array_pad($rows, 101, $rows[array_key_last($rows)]);
        }

        return $rows;
    }

    public function currentCandles(string $instrumentId, string $bar, ?string $after = null, ?string $before = null, int $limit = 300): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }
}

final class NumericTradeIdentifierRestClient implements OkxPaperPublicRestClientInterface
{
    public function __construct(private readonly OkxPaperPublicRestClientInterface $inner)
    {
    }

    public function historyCandles(string $instrumentId, string $bar, ?string $after = null, int $limit = 300): array
    {
        return $this->inner->historyCandles($instrumentId, $bar, $after, $limit);
    }

    public function historyTrades(string $instrumentId, int $paginationType = 2, ?string $after = null, int $limit = 100): array
    {
        return [
            $this->trade($instrumentId, '10', '1784628000123'),
            $this->trade($instrumentId, '9', '1784628000123'),
            $this->trade($instrumentId, '8', '1784627999999'),
        ];
    }

    public function currentCandles(string $instrumentId, string $bar, ?string $after = null, ?string $before = null, int $limit = 300): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    /** @return array<string, string> */
    private function trade(string $instrumentId, string $tradeId, string $timestamp): array
    {
        return [
            'instId' => $instrumentId,
            'tradeId' => $tradeId,
            'px' => '65000.1',
            'sz' => '1',
            'side' => 'buy',
            'source' => '0',
            'ts' => $timestamp,
        ];
    }
}

final class ScriptedHistoricalRestClient implements OkxPaperPublicRestClientInterface
{
    /** @var list<array<string, int|string|null>> */
    public array $calls = [];

    /** @param array<string, list<array<array-key, mixed>>> $candles */
    private function __construct(
        private readonly OkxHistoricalRequest $request,
        private readonly array $candles,
        /** @var list<array<string, mixed>> */
        private readonly array $trades,
        private readonly int $candlePageSize,
        private readonly ?string $repeatedCandleBar = null,
        private readonly bool $tradeBoundaryOverlap = false,
        private readonly bool $conflictingTradeBoundaryOverlap = false,
    ) {
    }

    public static function completeRange(
        OkxHistoricalRequest $request,
        int $candlePageSize = 300,
    ): self {
        $candles = [];
        foreach (['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1H' => 3_600_000] as $bar => $step) {
            $rows = [];
            $from = ((int) $request->from->format('U')) * 1_000_000 + (int) $request->from->format('u');
            $to = ((int) $request->to->format('U')) * 1_000_000 + (int) $request->to->format('u');
            $stepMicroseconds = $step * 1_000;
            $first = intdiv($from + $stepMicroseconds - 1, $stepMicroseconds) * $stepMicroseconds;
            for ($timestamp = $first; $timestamp < $to; $timestamp += $stepMicroseconds) {
                $rows[] = self::candle((string) intdiv($timestamp, 1_000));
            }
            $rows[] = self::candle((string) intdiv($first - $stepMicroseconds, 1_000));
            usort($rows, static fn (array $left, array $right): int => (int) $right[0] <=> (int) $left[0]);
            $candles[$bar] = $rows;
        }

        $trades = [];
        for ($tradeId = 1_205; $tradeId >= 1_001; --$tradeId) {
            $trades[] = self::trade((string) $tradeId, '1784629800123');
        }
        $trades[] = self::trade('1000', '1784628000000');
        $trades[] = self::trade('999', '1784627999999');
        $trades[] = self::trade('1206', '1784631600000');
        usort($trades, static function (array $left, array $right): int {
            $byTimestamp = (int) $right['ts'] <=> (int) $left['ts'];

            return $byTimestamp !== 0 ? $byTimestamp : (int) $right['tradeId'] <=> (int) $left['tradeId'];
        });

        return new self($request, $candles, $trades, $candlePageSize);
    }

    public function withoutCandle(string $bar, string $timestamp): self
    {
        $candles = $this->candles;
        $candles[$bar] = array_values(array_filter(
            $candles[$bar] ?? [],
            static fn (array $row): bool => $row[0] !== $timestamp,
        ));

        return new self(
            $this->request,
            $candles,
            $this->trades,
            $this->candlePageSize,
            $this->repeatedCandleBar,
            $this->tradeBoundaryOverlap,
            $this->conflictingTradeBoundaryOverlap,
        );
    }

    public function withoutTrades(): self
    {
        return new self(
            $this->request,
            $this->candles,
            [],
            $this->candlePageSize,
            $this->repeatedCandleBar,
            $this->tradeBoundaryOverlap,
            $this->conflictingTradeBoundaryOverlap,
        );
    }

    public function withoutCandlesBeforeFrom(string $bar): self
    {
        $fromMicroseconds = ((int) $this->request->from->format('U')) * 1_000_000
            + (int) $this->request->from->format('u');
        $candles = $this->candles;
        $candles[$bar] = array_values(array_filter(
            $candles[$bar] ?? [],
            static fn (array $row): bool => ((int) $row[0]) * 1_000 >= $fromMicroseconds,
        ));

        return new self(
            $this->request,
            $candles,
            $this->trades,
            $this->candlePageSize,
            $this->repeatedCandleBar,
            $this->tradeBoundaryOverlap,
            $this->conflictingTradeBoundaryOverlap,
        );
    }

    public function withMalformedCandle(string $bar, string $timestamp): self
    {
        $candles = $this->candles;
        foreach ($candles[$bar] as &$row) {
            if ($row[0] === $timestamp) {
                $row[1] = '';
                break;
            }
        }
        unset($row);

        return new self(
            $this->request,
            $candles,
            $this->trades,
            $this->candlePageSize,
            $this->repeatedCandleBar,
            $this->tradeBoundaryOverlap,
            $this->conflictingTradeBoundaryOverlap,
        );
    }

    public function withRepeatedCandlePage(string $bar): self
    {
        return new self(
            $this->request,
            $this->candles,
            $this->trades,
            $this->candlePageSize,
            $bar,
            $this->tradeBoundaryOverlap,
            $this->conflictingTradeBoundaryOverlap,
        );
    }

    public function withFirstTradeInstrument(string $instrumentId): self
    {
        $trades = $this->trades;
        $from = ((int) $this->request->from->format('U')) * 1_000;
        $to = ((int) $this->request->to->format('U')) * 1_000;
        foreach ($trades as &$trade) {
            if ((int) $trade['ts'] >= $from && (int) $trade['ts'] < $to) {
                $trade['instId'] = $instrumentId;
                break;
            }
        }
        unset($trade);

        return new self(
            $this->request,
            $this->candles,
            $trades,
            $this->candlePageSize,
            $this->repeatedCandleBar,
            $this->tradeBoundaryOverlap,
            $this->conflictingTradeBoundaryOverlap,
        );
    }

    public function withTradeBoundaryOverlap(): self
    {
        return new self(
            $this->request,
            $this->candles,
            $this->trades,
            $this->candlePageSize,
            $this->repeatedCandleBar,
            true,
            false,
        );
    }

    public function withConflictingTradeBoundaryOverlap(): self
    {
        return new self(
            $this->request,
            $this->candles,
            $this->trades,
            $this->candlePageSize,
            $this->repeatedCandleBar,
            true,
            true,
        );
    }

    public function historyCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        int $limit = 300,
    ): array {
        $this->calls[] = compact('instrumentId', 'bar', 'after') + ['method' => 'historyCandles'];
        $effectiveAfter = $this->repeatedCandleBar === $bar && \count(array_filter(
            $this->calls,
            static fn (array $call): bool => ($call['method'] ?? null) === 'historyCandles'
                && ($call['bar'] ?? null) === $bar,
        )) > 1
            ? ((int) $this->request->to->format('U')) * 1_000
            : $after;
        $rows = array_values(array_filter(
            $this->candles[$bar] ?? [],
            static fn (array $row): bool => $effectiveAfter === null || (int) $row[0] < (int) $effectiveAfter,
        ));

        return array_slice($rows, 0, min($limit, $this->candlePageSize));
    }

    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array {
        $this->calls[] = [
            'method' => 'historyTrades',
            'instrumentId' => $instrumentId,
            'pagination_type' => $paginationType,
            'after' => $after,
        ];
        $availableTrades = $this->trades;
        if ($instrumentId === 'ETH-USDT-SWAP') {
            $availableTrades = array_map(static function (array $trade) use ($instrumentId): array {
                $trade['instId'] = $instrumentId;

                return $trade;
            }, $availableTrades);
        }
        $rows = array_values(array_filter(
            $availableTrades,
            static fn (array $row): bool => $after === null || ($paginationType === 2
                ? (int) $row['ts'] < (int) $after
                : (int) $row['tradeId'] < (int) $after),
        ));

        if ($this->tradeBoundaryOverlap && $paginationType === 1 && $after !== null) {
            foreach ($availableTrades as $trade) {
                if ($trade['tradeId'] === $after) {
                    if ($this->conflictingTradeBoundaryOverlap) {
                        $trade['px'] = '65000.2';
                    }
                    array_unshift($rows, $trade);
                    break;
                }
            }
        }

        return array_slice($rows, 0, $limit);
    }

    public function currentCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        ?string $before = null,
        int $limit = 300,
    ): array {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    /** @return list<string> */
    public function tradeIds(): array
    {
        return array_column($this->trades, 'tradeId');
    }

    /** @return list<string> */
    public function bars(): array
    {
        return $this->request->bars;
    }

    /** @return list<string> */
    private static function candle(string $timestamp): array
    {
        return [$timestamp, '1.0', '2.0', '0.5', '1.5', '10', '10.0', '15.0', '1'];
    }

    /** @return array<string, string> */
    private static function trade(string $tradeId, string $timestamp): array
    {
        return [
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => $tradeId,
            'px' => '65000.1',
            'sz' => '1',
            'side' => 'buy',
            'source' => '0',
            'ts' => $timestamp,
        ];
    }
}
