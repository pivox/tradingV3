<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetAppendResult;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Dataset\PaperLiveDatasetCapture;
use App\Trading\Paper\Dataset\PaperLiveEventConsumerInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Http\OkxPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperFundingRateClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpointStore;
use App\Trading\Paper\Okx\Live\OkxPaperPublicLiveSource;
use App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxPaperPublicLiveSource::class)]
#[CoversClass(PaperLiveDatasetCapture::class)]
#[CoversClass(PaperReplayReader::class)]
final class OkxPaperLiveCaptureReplayEqualityTest extends TestCase
{
    private const DATASET_ID = 'okx-live-fixture-replay-equality';
    private const CONFIGURATION_SHA256 = '9999999999999999999999999999999999999999999999999999999999999999';

    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'okx-live-equality-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create Task 9 test directory.');
        }
        $resolved = realpath($path);
        self::assertIsString($resolved);
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testHealthyPublicFixtureCaptureReplaysAsTheExactNormalizedSequence(): void
    {
        $clock = new MockClock('2026-07-25T08:59:59.999998Z');
        $recorder = new PaperDatasetRecorder(
            $this->testRoot . '/paper-market-data',
            self::manifest(),
        );
        $rest = Task9RestClient::completeFixture($clock);
        $public = new Task9Transport(self::publicFrames());
        $business = new Task9Transport(self::businessFrames());
        $loop = new Task9Loop();
        $store = new OkxPaperLiveCheckpointStore(
            $recorder->datasetDirectory(),
            clock: $clock,
        );
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        $source = new OkxPaperPublicLiveSource(
            $rest,
            $public,
            $business,
            new OkxPaperPublicConfig(
                acquisitionEnabled: true,
                restBaseUri: OkxPaperPublicConfig::REST_BASE_URI,
                webSocketUri: OkxPaperPublicConfig::WEB_SOCKET_URI,
                dataRoot: $this->testRoot,
            ),
            $clock,
            $store,
            $checkpoint,
            $loop,
            metadataClient: new Task9MetadataClient(),
            fundingClient: new Task9FundingClient(),
        );
        $consumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            function (PaperMarketEvent $event) use ($clock): void {
                if ($event->channel === PaperMarketDataChannel::INSTRUMENT_METADATA
                    && $event->symbol === 'BTCUSDT'
                ) {
                    $clock->modify('2026-07-25T08:59:59.999999Z');
                } elseif ($event->channel === PaperMarketDataChannel::INSTRUMENT_METADATA
                    || $event->channel === PaperMarketDataChannel::FUNDING_RATE
                    || ($event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                        && $event->symbol === 'BTCUSDT'
                        && ($event->payload['reason'] ?? null) === 'initial')
                ) {
                    $clock->sleep(0.000001);
                }
                if ($event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                    && $event->symbol === 'ETHUSDT'
                    && ($event->payload['reason'] ?? null) === 'initial'
                ) {
                    $clock->modify('2026-07-25T09:00:14.000000Z');
                }
                if ($event->channel === PaperMarketDataChannel::CANDLE_1H
                    && $event->symbol === 'ETHUSDT'
                    && ($event->payload['origin'] ?? null) === 'ws_candle'
                ) {
                    $clock->modify('2026-07-25T09:00:28.000000Z');
                }
                if ($event->channel === PaperMarketDataChannel::CONNECTION_STATE
                    && $event->symbol === 'BTCUSDT'
                    && ($event->payload['state'] ?? null) === 'stopped'
                ) {
                    $clock->modify('2026-07-25T09:00:29.000000Z');
                }
            },
        );
        $loop->onRun = static function () use ($source): void {
            $source->requestHealthyOperatorStop();
        };

        $manifest = (new PaperLiveDatasetCapture())->run(
            $recorder,
            $source,
            $consumer,
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(
            PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            $manifest->quality,
        );
        self::assertNotNull($manifest->eventsFileSha256);
        self::assertSame(
            $manifest->eventsFileSha256,
            hash_file('sha256', $recorder->datasetDirectory() . '/events.ndjson'),
        );
        self::assertSame(
            array_map(
                static fn (PaperMarketDataChannel $channel): string => $channel->value,
                PaperMarketDataChannel::cases(),
            ),
            array_values(array_intersect(
                array_map(
                    static fn (PaperMarketDataChannel $channel): string => $channel->value,
                    PaperMarketDataChannel::cases(),
                ),
                $manifest->channels,
            )),
        );
        self::assertSame($manifest->eventCount, $consumer->effectCount);
        self::assertSame($manifest->eventCount, \count($consumer->capturedEvents));
        self::assertSame(
            $manifest->eventCount,
            \count(array_unique(array_map(
                static fn (PaperMarketEvent $event): string => $event->eventId,
                $consumer->capturedEvents,
            ))),
        );
        self::assertCount(1, array_filter(
            $consumer->capturedEvents,
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::PUBLIC_TRADE
                && ($event->payload['trade_id'] ?? null) === '200',
        ), 'The exact duplicate Public trade frame must not create another event.');
        self::assertBtcEthRestAndWebSocketMatrix($consumer->capturedEvents);
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::publicArguments()]],
            $public->sent,
        );
        self::assertSame(
            [['op' => 'subscribe', 'args' => self::businessArguments()]],
            $business->sent,
        );

        $verified = (new PaperDatasetVerifier())->verify($recorder->datasetDirectory());
        self::assertEquals($manifest, $verified);
        $replayClock = new PaperReplayClock(
            new \DateTimeImmutable('2026-07-25T08:59:00.000000Z'),
        );
        $replayedEvents = iterator_to_array(
            (new PaperReplayReader(
                new PaperDatasetVerifier(),
                new PaperReplayCheckpointStore(),
                $replayClock,
            ))->read($recorder->datasetDirectory(), 'task9.equality'),
            false,
        );

        self::assertSame(
            array_map(
                static fn (PaperMarketEvent $event): array => $event->toArray(),
                $consumer->capturedEvents,
            ),
            array_map(
                static fn (PaperMarketEvent $event): array => $event->toArray(),
                $replayedEvents,
            ),
        );
        self::assertEquals(
            $replayedEvents[array_key_last($replayedEvents)]->exchangeTimestamp,
            $replayClock->now(),
        );
    }

    #[DataProvider('durableCrashWindowProvider')]
    public function testRecorderAndConsumerRestartAreIdempotentAcrossDurableCrashWindows(
        string $crashWindow,
        bool $effectCommittedBeforeRestart,
    ): void {
        $datasetId = 'okx-task9-' . $crashWindow;
        $datasetRoot = $this->testRoot . '/' . $crashWindow . '-paper-market-data';
        $consumerCheckpoint = $this->testRoot . '/' . $crashWindow . '-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $recorder = new PaperDatasetRecorder($datasetRoot, self::manifest($datasetId));
        $initialRest = Task9RestClient::multiRowWarmupFixture($clock);
        $initialPublic = new Task9Transport(self::publicFrames());
        $initialBusiness = new Task9Transport(self::businessFrames());
        $initialLoop = new Task9Loop();
        $source = $this->source(
            $recorder,
            $datasetId,
            $clock,
            $initialRest,
            $initialPublic,
            $initialBusiness,
            $initialLoop,
        );
        $consumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            static function (PaperMarketEvent $_event): void {
            },
            $consumerCheckpoint,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($pending));
        if ($effectCommittedBeforeRestart) {
            $consumer->consume($datasetId, $pending);
            self::assertSame(1, $consumer->consumeCount);
            self::assertSame(1, $consumer->effectCount);
        }
        $eventBytesBeforeRestart = self::eventFileBytes($recorder->datasetDirectory());

        unset(
            $events,
            $source,
            $consumer,
            $recorder,
            $initialRest,
            $initialPublic,
            $initialBusiness,
            $initialLoop,
        );
        gc_collect_cycles();

        $restartedRecorder = new PaperDatasetRecorder(
            $datasetRoot,
            self::manifest($datasetId),
        );
        $restartedRest = Task9RestClient::multiRowWarmupFixture($clock);
        $restartedPublic = new Task9Transport(self::publicFrames());
        $restartedBusiness = new Task9Transport(self::businessFrames());
        $restartedLoop = new Task9Loop();
        $restartedSource = $this->source(
            $restartedRecorder,
            $datasetId,
            $clock,
            $restartedRest,
            $restartedPublic,
            $restartedBusiness,
            $restartedLoop,
        );
        $restartedConsumer = new Task9IdempotentConsumer(
            $restartedRecorder->datasetDirectory(),
            static function (PaperMarketEvent $_event): void {
            },
            $consumerCheckpoint,
        );
        $restartedEvents = $restartedSource->events();
        self::assertInstanceOf(\Generator::class, $restartedEvents);
        $replayed = $restartedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayed);

        self::assertSame(
            $pending->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'),
            $replayed->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'),
        );
        self::assertSame($pending->payloadHash, $replayed->payloadHash);
        self::assertSame($pending->eventId, $replayed->eventId);
        self::assertSame($pending->toArray(), $replayed->toArray());
        self::assertSame(
            PaperDatasetAppendResult::REPLAYED,
            $restartedRecorder->append($replayed),
        );
        self::assertSame(
            $eventBytesBeforeRestart,
            self::eventFileBytes($restartedRecorder->datasetDirectory()),
        );

        $restartedConsumer->consume($datasetId, $replayed);
        self::assertSame(1, $restartedConsumer->consumeCount);
        self::assertSame(1, $restartedConsumer->effectCount);
        $restartedSource->acknowledge($replayed->eventId);
        self::assertNull(
            self::checkpointState($restartedRecorder->datasetDirectory())['pending_event'],
        );

        $restartedEvents->next();
        $next = $restartedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $next);
        self::assertSame((string) ((int) $replayed->sequence + 1), $next->sequence);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $restartedRecorder->append($next));
        $restartedConsumer->consume($datasetId, $next);
        $restartedSource->acknowledge($next->eventId);
        self::assertSame(2, $restartedConsumer->effectCount);
        self::assertSame(
            2,
            \count(self::eventLines($restartedRecorder->datasetDirectory())),
        );
        self::assertSame(
            2,
            \count(array_unique(array_column(
                self::eventLines($restartedRecorder->datasetDirectory()),
                'event_id',
            ))),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function durableCrashWindowProvider(): iterable
    {
        yield 'append before downstream effect' => [
            'append-before-effect',
            false,
        ];
        yield 'downstream effect committed before source acknowledgement' => [
            'effect-before-acknowledgement',
            true,
        ];
    }

    public function testWarmupCrashRestartMatrixExecutesSevenExactWindows(): void
    {
        $datasetId = 'okx-task9-warmup-crash-matrix';
        $datasetRoot = $this->testRoot . '/warmup-crash-matrix-data';
        $consumerCheckpoint = $this->testRoot . '/warmup-crash-matrix-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $covered = [];

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
        );
        $pending = $runtime->pending();
        $pendingIdentity = $pending->toArray();
        self::assertSame([], self::eventLines($runtime->datasetDirectory()));
        $covered[] = 'pending checkpoint before recorder append';
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
        );
        self::assertSame($pendingIdentity, $runtime->pending()->toArray());
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $bytesAfterAppend = self::eventFileBytes($runtime->datasetDirectory());
        $covered[] = 'event append before source acknowledgement';
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
        );
        self::assertSame($pendingIdentity, $runtime->pending()->toArray());
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $runtime->appendPending());
        self::assertSame($bytesAfterAppend, self::eventFileBytes($runtime->datasetDirectory()));
        self::assertSame(0, $runtime->effectCount());
        $covered[] = 'event append before downstream effect';
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
        );
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $runtime->appendPending());
        $runtime->consumePending();
        self::assertSame(1, $runtime->effectCount());
        $covered[] = 'downstream key/hash/effect committed before source acknowledgement';
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
        );
        self::assertSame($pendingIdentity, $runtime->pending()->toArray());
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $runtime->appendPending());
        $runtime->consumePending();
        self::assertSame(1, $runtime->effectCount());
        $runtime->acknowledgePending();
        self::assertNull(
            self::checkpointState($runtime->datasetDirectory())['pending_event'],
        );
        $covered[] = 'acknowledgement before next raw frame';
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
        );
        $boundary = $runtime->advanceDurablyUntil(
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                && ($event->payload['reason'] ?? null) === 'initial',
        );
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $boundaryIdentity = $boundary->toArray();
        $covered[] = 'REST initial snapshot before snapshot_boundary acknowledgement';
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
        );
        self::assertSame($boundaryIdentity, $runtime->pending()->toArray());
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $runtime->appendPending());
        $effectsBeforeBoundaryReplay = $runtime->effectCount();
        $runtime->consumePending();
        self::assertSame($effectsBeforeBoundaryReplay, $runtime->effectCount());
        $runtime->acknowledgePending();
        $runtime->advance();
        $firstTrade = $runtime->advanceDurablyUntil(
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::PUBLIC_TRADE
                && ($event->payload['origin'] ?? null) === 'ws_aggregated'
                && ($event->payload['trade_id'] ?? null) === '200',
        );
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        $firstTradeIdentity = $firstTrade->toArray();
        $runtime->advance();
        $secondTradeBeforeCrash = $runtime->pending();
        self::assertSame('202', $secondTradeBeforeCrash->payload['trade_id'] ?? null);
        $secondTradeIdentity = $secondTradeBeforeCrash->toArray();
        $covered[] = 'multi-row WS trades frame after first row acknowledgement';
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            resumeQueuedFrameOnly: true,
        );
        $secondTrade = $runtime->pending();
        self::assertSame('202', $secondTrade->payload['trade_id'] ?? null);
        self::assertSame($secondTradeIdentity, $secondTrade->toArray());
        self::assertSame(
            (string) ((int) $firstTradeIdentity['sequence'] + 1),
            $secondTrade->sequence,
        );
        self::assertNotSame($firstTradeIdentity['event_id'], $secondTrade->eventId);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        self::assertNull(
            self::checkpointState($runtime->datasetDirectory())['pending_event'],
        );
        $lines = self::eventLines($runtime->datasetDirectory());
        self::assertSame(
            \count($lines),
            \count(array_unique(array_column($lines, 'event_id'))),
        );
        self::assertSame(
            1,
            \count(array_filter(
                $lines,
                static fn (array $line): bool =>
                    ($line['event_id'] ?? null) === $secondTrade->eventId,
            )),
        );
        self::assertSame([
            'pending checkpoint before recorder append',
            'event append before source acknowledgement',
            'event append before downstream effect',
            'downstream key/hash/effect committed before source acknowledgement',
            'acknowledgement before next raw frame',
            'REST initial snapshot before snapshot_boundary acknowledgement',
            'multi-row WS trades frame after first row acknowledgement',
        ], $covered);
    }

    public function testPostWarmupCrashRestartMatrixExecutesSevenExactWindows(): void
    {
        $datasetId = 'okx-task9-book-gap-before-rest';
        $datasetRoot = $this->testRoot . '/book-gap-before-rest-data';
        $consumerCheckpoint = $this->testRoot . '/book-gap-before-rest-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $rest = Task9RestClient::bookGapRecoveryFixture($clock);
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $rest,
        );
        $this->advanceCrashRuntimeToStreamingTail($runtime);
        $orderBookCallsBeforeGap = \count(array_filter(
            $rest->calls,
            static fn (array $call): bool => $call[0] === 'orderBook',
        ));
        $rest->beforeOrderBook = static function (): never {
            throw new \RuntimeException('okx_paper_live_checkpoint_write_failed');
        };
        $runtime->publicMessage(self::bookFrame(
            'BTC-USDT-SWAP',
            'update',
            '9103',
            '9102',
            '1784970030000',
        ));
        try {
            $runtime->advance();
            self::fail('Case 8 must crash before the REST resync call.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'okx_paper_live_checkpoint_write_failed',
                $exception->getMessage(),
            );
        }
        self::assertSame($orderBookCallsBeforeGap, \count(array_filter(
            $rest->calls,
            static fn (array $call): bool => $call[0] === 'orderBook',
        )));
        $gapCheckpoint = self::checkpointState($runtime->datasetDirectory());
        self::assertSame('resyncing', $gapCheckpoint['phase']);
        self::assertSame(1, $gapCheckpoint['resync_by_symbol']['BTCUSDT']['attempt']);
        self::assertSame(
            'order_book',
            $gapCheckpoint['pending_transition']['stage'],
        );
        self::assertSame(
            1,
            $gapCheckpoint['streaming_queue_ref']['public']['frames'] ?? null,
        );
        $runtime->crash();

        $restartedRest = Task9RestClient::bookGapRecoveryFixture($clock, true);
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $restartedRest,
            public: new Task9Transport(self::subscriptionAcknowledgements(
                self::publicArguments(),
                'task9case8publicrestart',
            )),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case8businessrestart',
            )),
        );
        $resyncedBook = $runtime->pending();
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $resyncedBook->channel);
        self::assertSame('rest_resync_snapshot', $resyncedBook->payload['origin'] ?? null);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        $runtime->crash();

        // 9. The incremented attempt/deadline survives a crash before REST and timer arming.
        $datasetId = 'okx-task9-resync-attempt-before-effects';
        $datasetRoot = $this->testRoot . '/resync-attempt-before-effects-data';
        $consumerCheckpoint = $this->testRoot . '/resync-attempt-before-effects-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $rest = Task9RestClient::bookGapRecoveryFixture($clock);
        $loop = new Task9Loop();
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $rest,
            loop: $loop,
        );
        $this->advanceCrashRuntimeToStreamingTail($runtime);
        $resyncTimerSchedules = 0;
        $loop->beforeAddTimer = static function (float $interval) use (
            &$resyncTimerSchedules,
        ): void {
            if ($interval !== 10.0 || ++$resyncTimerSchedules !== 2) {
                return;
            }
            throw new \RuntimeException('okx_paper_live_checkpoint_write_failed');
        };
        $rest->beforeOrderBook = static function () use ($loop): void {
            $loop->fireTimer(10.0);
        };
        $runtime->publicMessage(self::bookFrame(
            'BTC-USDT-SWAP',
            'update',
            '9103',
            '9102',
            '1784970030000',
        ));
        try {
            $runtime->advance();
            self::fail('Case 9 must crash before the second REST call and timeout arm.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'okx_paper_live_checkpoint_write_failed',
                $exception->getMessage(),
            );
        }
        $attemptCheckpoint = self::checkpointState($runtime->datasetDirectory());
        $attemptDeadline = $attemptCheckpoint['resync_by_symbol']['BTCUSDT']['deadline_at'];
        self::assertSame(2, $attemptCheckpoint['resync_by_symbol']['BTCUSDT']['attempt']);
        self::assertSame(2, $resyncTimerSchedules);
        $runtime->crash();
        $clock->modify($attemptDeadline);

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::bookGapRecoveryFixture($clock, true),
            public: new Task9Transport(self::subscriptionAcknowledgements(
                self::publicArguments(),
                'task9case9publicrestart',
            )),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case9businessrestart',
            )),
        );
        self::assertSame(
            'rest_resync_snapshot',
            $runtime->pending()->payload['origin'] ?? null,
        );
        $resumedAttempt = self::checkpointState($runtime->datasetDirectory());
        self::assertSame(3, $resumedAttempt['resync_by_symbol']['BTCUSDT']['attempt']);
        self::assertGreaterThan(
            $attemptDeadline,
            $resumedAttempt['resync_by_symbol']['BTCUSDT']['deadline_at'],
        );
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        $runtime->crash();

        // 10. The resync top-of-book event replays before its boundary acknowledgement.
        $datasetId = 'okx-task9-resync-book-before-boundary-ack';
        $datasetRoot = $this->testRoot . '/resync-book-before-boundary-ack-data';
        $consumerCheckpoint = $this->testRoot . '/resync-book-before-boundary-ack-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::bookGapRecoveryFixture($clock),
        );
        $this->advanceCrashRuntimeToStreamingTail($runtime);
        $runtime->publicMessage(self::bookFrame(
            'BTC-USDT-SWAP',
            'update',
            '9103',
            '9102',
            '1784970030000',
        ));
        $runtime->advance();
        $resyncBookBeforeAck = $runtime->pending();
        self::assertSame(
            'rest_resync_snapshot',
            $resyncBookBeforeAck->payload['origin'] ?? null,
        );
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $effectCountBeforeBookReplay = $runtime->effectCount();
        $bookBytesBeforeRestart = self::eventFileBytes($runtime->datasetDirectory());
        $runtime->crash();

        $replayRest = Task9RestClient::bookGapRecoveryFixture($clock, true);
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $replayRest,
            public: new Task9Transport(self::subscriptionAcknowledgements(
                self::publicArguments(),
                'task9case10publicrestart',
            )),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case10businessrestart',
            )),
        );
        self::assertSame($resyncBookBeforeAck->toArray(), $runtime->pending()->toArray());
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $runtime->appendPending());
        self::assertSame(
            $bookBytesBeforeRestart,
            self::eventFileBytes($runtime->datasetDirectory()),
        );
        $runtime->consumePending();
        self::assertSame($effectCountBeforeBookReplay, $runtime->effectCount());
        self::assertSame([], $replayRest->calls);
        $runtime->acknowledgePending();
        $runtime->advance();
        $sequenceGapBoundary = $runtime->pending();
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $sequenceGapBoundary->channel);
        self::assertSame('sequence_gap', $sequenceGapBoundary->payload['reason'] ?? null);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        $runtime->crash();

        // 11. A disconnect after the reconnect timer resumes at the next durable attempt.
        $datasetId = 'okx-task9-disconnect-after-reconnect-timer';
        $datasetRoot = $this->testRoot . '/disconnect-after-reconnect-timer-data';
        $consumerCheckpoint = $this->testRoot . '/disconnect-after-reconnect-timer-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::completeFixture($clock),
            public: new Task9Transport(self::publicFrames()),
        );
        $this->advanceCrashRuntimeToStreamingTail($runtime);
        $runtime->disconnectPublic();
        self::assertSame(
            1,
            self::checkpointState($runtime->datasetDirectory())['reconnect']['attempt'],
        );
        $runtime->fireTimer(1.0);
        $runtime->disconnectPublic();
        $disconnectCheckpoint = self::checkpointState($runtime->datasetDirectory());
        self::assertSame(2, $disconnectCheckpoint['reconnect']['attempt']);
        self::assertSame('reconnect_delay', $disconnectCheckpoint['pending_transition']['stage']);
        $secondReconnectDeadline = $disconnectCheckpoint['reconnect']['deadline_at'];
        $runtime->crash();

        $clock->modify($secondReconnectDeadline);
        $loop = new Task9Loop();
        $loop->scripts[] = static function () use ($loop): void {
            $loop->fireTimer(0.0);
        };
        $timerReconnectRest = Task9RestClient::recoveryFixture($clock);
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $timerReconnectRest,
            public: new Task9Transport(self::recoveryPublicFrames()),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case11businessrestart',
            )),
            loop: $loop,
        );
        $reconnecting = $runtime->pending();
        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $reconnecting->channel);
        self::assertSame('reconnecting', $reconnecting->payload['state'] ?? null);
        self::assertSame(
            2,
            self::checkpointState($runtime->datasetDirectory())['reconnect']['attempt'],
        );
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        $runtime->crash();

        // 12. Overlap pagination budget/deadline are durable before the history request.
        $datasetId = 'okx-task9-pagination-before-history-rest';
        $datasetRoot = $this->testRoot . '/pagination-before-history-rest-data';
        $consumerCheckpoint = $this->testRoot . '/pagination-before-history-rest-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::completeFixture($clock),
            public: new Task9Transport(self::publicFrames()),
        );
        $this->advanceCrashRuntimeToStreamingTail($runtime);
        $runtime->disconnectPublic();
        $paginationReconnect = self::checkpointState($runtime->datasetDirectory());
        $runtime->crash();
        $clock->modify($paginationReconnect['reconnect']['deadline_at']);

        $paginationRest = Task9RestClient::recoveryFixture($clock);
        $paginationRest->observePaginationCheckpoint(
            $datasetRoot . '/' . $datasetId,
        );
        $paginationRest->afterHistoryCandlesCheckpoint = static function (): never {
            throw new \RuntimeException('task9_crash_after_pagination_checkpoint');
        };
        $loop = new Task9Loop();
        $loop->scripts[] = static function () use ($loop): void {
            $loop->fireTimer(0.0);
        };
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $paginationRest,
            public: new Task9Transport(self::recoveryPublicFrames()),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case12business',
            )),
            loop: $loop,
        );
        try {
            for ($index = 0; $index < 100; ++$index) {
                $runtime->appendPending();
                $runtime->consumePending();
                $runtime->acknowledgePending();
                $runtime->advance();
            }
            self::fail('Case 12 must crash at the first history candle request.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'task9_crash_after_pagination_checkpoint',
                $exception->getMessage(),
            );
        }
        $paginationCheckpoint = self::checkpointState($runtime->datasetDirectory());
        $persistedPagination =
            $paginationCheckpoint['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'];
        self::assertSame(0, $persistedPagination['pages_consumed']);
        self::assertSame(10, $persistedPagination['pages_remaining']);
        self::assertSame(
            $paginationCheckpoint['resync_by_symbol']['BTCUSDT']['deadline_at'],
            $persistedPagination['deadline_at'],
        );
        self::assertCount(1, $paginationRest->paginationObservations);
        $runtime->crash();

        $paginationReplayRest = Task9RestClient::recoveryFixture($clock);
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $paginationReplayRest,
            public: new Task9Transport(self::subscriptionAcknowledgements(
                self::publicArguments(),
                'task9case12publicrestart',
            )),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case12businessrestart',
            )),
        );
        self::assertSame(
            $persistedPagination,
            self::checkpointState(
                $runtime->datasetDirectory(),
            )['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'],
        );
        $historyEvent = $runtime->pending();
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $historyEvent->channel);
        self::assertSame('rest_warmup', $historyEvent->payload['origin'] ?? null);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        $runtime->crash();

        // 13. Streaming is durable before the reconnect stability state can reset.
        $datasetId = 'okx-task9-streaming-before-stability-reset';
        $datasetRoot = $this->testRoot . '/streaming-before-stability-reset-data';
        $consumerCheckpoint = $this->testRoot . '/streaming-before-stability-reset-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::completeFixture($clock),
            public: new Task9Transport(self::publicFrames()),
        );
        $this->advanceCrashRuntimeToStreamingTail($runtime);
        $runtime->disconnectPublic();
        $stabilityReconnect = self::checkpointState($runtime->datasetDirectory());
        $runtime->crash();
        $clock->modify($stabilityReconnect['reconnect']['deadline_at']);

        $stabilityRest = Task9RestClient::recoveryFixture($clock);
        $loop = new Task9Loop();
        $loop->scripts[] = static function () use ($loop): void {
            $loop->fireTimer(0.0);
        };
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: $stabilityRest,
            public: new Task9Transport(self::recoveryPublicFrames()),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case13business',
            )),
            loop: $loop,
        );
        for ($index = 0; $index < 100; ++$index) {
            $event = $runtime->pending();
            if (self::checkpointState($runtime->datasetDirectory())['phase'] === 'streaming') {
                $streamingPending = $event;
                break;
            }
            $runtime->appendPending();
            $runtime->consumePending();
            $runtime->acknowledgePending();
            $runtime->advance();
        }
        self::assertInstanceOf(PaperMarketEvent::class, $streamingPending ?? null);
        $streamingCheckpoint = self::checkpointState($runtime->datasetDirectory());
        self::assertSame(1, $streamingCheckpoint['reconnect']['attempt']);
        self::assertIsString($streamingCheckpoint['reconnect']['stable_since']);
        self::assertSame(0, $streamingCheckpoint['reconnect']['accepted_events']);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $streamingEffectCount = $runtime->effectCount();
        $streamingBytes = self::eventFileBytes($runtime->datasetDirectory());
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::recoveryFixture($clock),
            public: new Task9Transport(self::subscriptionAcknowledgements(
                self::publicArguments(),
                'task9case13publicrestart',
            )),
            business: new Task9Transport(self::subscriptionAcknowledgements(
                self::businessArguments(),
                'task9case13businessrestart',
            )),
        );
        self::assertSame($streamingPending->toArray(), $runtime->pending()->toArray());
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $runtime->appendPending());
        self::assertSame($streamingBytes, self::eventFileBytes($runtime->datasetDirectory()));
        $runtime->consumePending();
        self::assertSame($streamingEffectCount, $runtime->effectCount());
        $resumedStreamingCheckpoint = self::checkpointState($runtime->datasetDirectory());
        self::assertSame(1, $resumedStreamingCheckpoint['reconnect']['attempt']);
        self::assertSame(
            $streamingCheckpoint['reconnect']['stable_since'],
            $resumedStreamingCheckpoint['reconnect']['stable_since'],
        );
        $runtime->acknowledgePending();
        $runtime->crash();

        // 14. Healthy stop resumes after the first stopped event and finalizes exactly once.
        $datasetId = 'okx-task9-healthy-stop-after-first-stopped';
        $datasetRoot = $this->testRoot . '/healthy-stop-after-first-stopped-data';
        $consumerCheckpoint = $this->testRoot . '/healthy-stop-after-first-stopped-consumer.json';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::completeFixture($clock),
        );
        $this->advanceCrashRuntimeToStreamingTail($runtime);
        $clock->modify('2026-07-25T09:00:28.000000Z');
        $runtime->requestHealthyOperatorStop();
        $runtime->advance();
        $firstStopped = $runtime->pending();
        self::assertSame('BTCUSDT', $firstStopped->symbol);
        self::assertSame('stopped', $firstStopped->payload['state'] ?? null);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        self::assertSame(
            ['ETHUSDT'],
            self::checkpointState(
                $runtime->datasetDirectory(),
            )['healthy_stop']['remaining_symbols'],
        );
        $clock->modify('2026-07-25T09:00:29.000000Z');
        $runtime->crash();

        $runtime = $this->openCrashRuntime(
            $datasetRoot,
            $datasetId,
            $consumerCheckpoint,
            $clock,
            rest: Task9RestClient::completeFixture($clock),
            public: new Task9Transport([]),
            business: new Task9Transport([]),
        );
        $secondStopped = $runtime->pending();
        self::assertSame('ETHUSDT', $secondStopped->symbol);
        self::assertSame('stopped', $secondStopped->payload['state'] ?? null);
        self::assertNotSame($firstStopped->eventId, $secondStopped->eventId);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
        $runtime->advance();
        self::assertFalse($runtime->valid());
        $manifest = $runtime->completeDataset();
        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        $healthyStopDataset = $runtime->datasetDirectory();
        $runtime->crash();

        $replayed = iterator_to_array(
            (new PaperReplayReader(
                new PaperDatasetVerifier(),
                new PaperReplayCheckpointStore(),
                new PaperReplayClock(
                    new \DateTimeImmutable('2026-07-25T08:59:00.000000Z'),
                ),
            ))->read($healthyStopDataset, 'task9.case14'),
            false,
        );
        $healthyStopLines = self::eventLines($healthyStopDataset);
        self::assertSame(
            array_column($healthyStopLines, 'event_id'),
            array_map(
                static fn (PaperMarketEvent $event): string => $event->eventId,
                $replayed,
            ),
        );
        self::assertEquals(
            $healthyStopLines,
            array_map(
                static fn (PaperMarketEvent $event): array => $event->toArray(),
                $replayed,
            ),
        );
        self::assertSame(
            ['BTCUSDT', 'ETHUSDT'],
            array_values(array_map(
                static fn (PaperMarketEvent $event): string => $event->symbol,
                array_filter(
                    $replayed,
                    static fn (PaperMarketEvent $event): bool =>
                        $event->channel === PaperMarketDataChannel::CONNECTION_STATE
                        && ($event->payload['state'] ?? null) === 'stopped',
                ),
            )),
        );
    }

    public function testRecorderDownstreamAndFrontierConflictsFailClosedWithoutExtraWork(): void
    {
        $this->assertRecorderIdentityConflictFailsClosed();
        $this->assertDownstreamIdentityConflictFailsClosed();
        $this->assertFrontierIdentityConflictFailsClosed();
    }

    public function testDisconnectRestartHistoricalOverlapAndHealthyStopReplayExactly(): void
    {
        $datasetId = 'okx-live-reconnect-replay-equality';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $datasetRoot = $this->testRoot . '/reconnect-paper-market-data';
        $recorder = new PaperDatasetRecorder(
            $datasetRoot,
            self::manifest($datasetId),
        );
        $consumerCheckpoint = $this->testRoot . '/reconnect-consumer.json';
        $initialRest = Task9RestClient::completeFixture($clock);
        $initialPublic = new Task9Transport(self::publicFrames());
        $initialBusiness = new Task9Transport(self::businessFrames());
        $initialLoop = new Task9Loop();
        $initialSource = $this->source(
            $recorder,
            $datasetId,
            $clock,
            $initialRest,
            $initialPublic,
            $initialBusiness,
            $initialLoop,
        );
        $initialConsumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            self::captureClockAdvancer($clock),
            $consumerCheckpoint,
        );
        $events = $initialSource->events();
        self::assertInstanceOf(\Generator::class, $events);
        $capturedBeforeRestart = [];
        for ($index = 0; $index < 28; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($event));
            $initialConsumer->consume($datasetId, $event);
            $capturedBeforeRestart[] = $event;
            $initialSource->acknowledge($event->eventId);
            if ($index < 27) {
                $events->next();
            }
        }
        self::assertSame(28, $recorder->manifest()->eventCount);
        self::assertSame(28, $initialConsumer->effectCount);

        $initialPublic->disconnect();
        $scheduled = self::checkpointState($recorder->datasetDirectory());
        self::assertSame('reconnecting', $scheduled['phase']);
        self::assertSame(1, $scheduled['reconnect']['attempt']);
        self::assertSame(
            '2026-07-25T09:00:29.000000Z',
            $scheduled['reconnect']['deadline_at'],
        );
        self::assertSame('reconnect_delay', $scheduled['pending_transition']['stage']);
        unset(
            $events,
            $initialSource,
            $initialPublic,
            $initialBusiness,
            $initialLoop,
            $initialRest,
            $initialConsumer,
            $recorder,
        );
        gc_collect_cycles();

        $clock->modify('2026-07-25T09:00:29.000000Z');
        [
            'event' => $finalizationCrashEvent,
            'event_bytes' => $eventsBeforeFinalizationRestart,
            'captured_events' => $capturedBeforeFinalizationRestart,
            'rest_calls' => $callsBeforeFinalizationRestart,
            'pagination_observations' => $paginationObservations,
        ] = $this->captureUntilFinalizedCandleOverlapCrash(
            $datasetRoot,
            $datasetId,
            $clock,
            $consumerCheckpoint,
        );
        gc_collect_cycles();

        $replayRecorder = new PaperDatasetRecorder(
            $datasetRoot,
            self::manifest($datasetId),
        );
        $replayRest = Task9RestClient::recoveryFixture($clock);
        $replayRest->observePaginationCheckpoint($replayRecorder->datasetDirectory());
        $replayPublic = new Task9Transport(self::subscriptionAcknowledgements(
            self::publicArguments(),
            'task9publicfinalizationreplay',
        ));
        $replayBusiness = new Task9Transport(self::subscriptionAcknowledgements(
            self::businessArguments(),
            'task9businessfinalizationreplay',
        ));
        $replayLoop = new Task9Loop();
        $replaySource = $this->source(
            $replayRecorder,
            $datasetId,
            $clock,
            $replayRest,
            $replayPublic,
            $replayBusiness,
            $replayLoop,
        );
        $replayConsumer = new Task9IdempotentConsumer(
            $replayRecorder->datasetDirectory(),
            self::recoveryClockAdvancer($clock, $replayPublic, $replayBusiness),
            $consumerCheckpoint,
        );
        $replayedEvents = $replaySource->events();
        self::assertInstanceOf(\Generator::class, $replayedEvents);
        $replayedFinalizationEvent = $replayedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $replayedFinalizationEvent);
        self::assertSame(
            $finalizationCrashEvent->toArray(),
            $replayedFinalizationEvent->toArray(),
        );
        self::assertSame(
            PaperDatasetAppendResult::REPLAYED,
            $replayRecorder->append($replayedFinalizationEvent),
        );
        self::assertSame(
            $eventsBeforeFinalizationRestart,
            self::eventFileBytes($replayRecorder->datasetDirectory()),
        );
        $effectCountBeforeReplay = $replayConsumer->effectCount;
        $replayConsumer->consume($datasetId, $replayedFinalizationEvent);
        self::assertSame($effectCountBeforeReplay, $replayConsumer->effectCount);
        self::assertSame(1, $replayConsumer->consumeCount);
        self::assertSame([], $replayRest->calls);
        $replaySource->acknowledge($replayedFinalizationEvent->eventId);
        self::assertSame(
            [
                'kind' => 'emit_boundary',
                'stage' => 'reconnect',
                'stream' => 'BTCUSDT/control/snapshot_boundary',
                'symbol' => 'BTCUSDT',
            ],
            self::checkpointState($replayRecorder->datasetDirectory())['pending_transition'],
        );
        $replayLoop->onRun = static function () use ($replaySource): void {
            $replaySource->requestHealthyOperatorStop();
        };
        for ($index = 0; $index < 100; ++$index) {
            $replayedEvents->next();
            if (!$replayedEvents->valid()) {
                break;
            }
            $event = $replayedEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame(
                PaperDatasetAppendResult::APPENDED,
                $replayRecorder->append($event),
            );
            $replayConsumer->consume($datasetId, $event);
            $replaySource->acknowledge($event->eventId);
        }
        self::assertFalse($replayedEvents->valid(), 'Healthy stop must terminate the source.');
        $manifest = $replayRecorder->complete();
        $completedRecorder = $replayRecorder;
        $completedConsumer = $replayConsumer;
        $recoveryCalls = [...$callsBeforeFinalizationRestart, ...$replayRest->calls];

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame($manifest->eventCount, $completedConsumer->effectCount);
        self::assertContains(
            ['historyTrades', ['BTC-USDT-SWAP', 2, '1784970038000', 100]],
            $recoveryCalls,
        );
        self::assertContains(
            ['historyTrades', ['BTC-USDT-SWAP', 1, '220', 100]],
            $recoveryCalls,
        );
        self::assertContains(
            ['historyCandles', ['BTC-USDT-SWAP', '1m', '1784970034000', 300]],
            $recoveryCalls,
        );
        self::assertContains(
            ['historyCandles', ['BTC-USDT-SWAP', '1m', '1784970032000', 300]],
            $recoveryCalls,
        );
        self::assertSame([], $replayRest->paginationObservations);
        $btcRestCandlePages = array_values(array_filter(
            $paginationObservations,
            static fn (array $observation): bool =>
                $observation['stream'] === 'BTCUSDT/rest/candle_1m',
        ));
        self::assertCount(2, $btcRestCandlePages);
        self::assertSame(
            [
                [0, 10, '1784970034000'],
                [1, 9, '1784970032000'],
            ],
            array_map(
                static fn (array $observation): array => [
                    $observation['pagination']['pages_consumed'],
                    $observation['pagination']['pages_remaining'],
                    $observation['pagination']['next_cursor'],
                ],
                $btcRestCandlePages,
            ),
        );
        self::assertSame(
            $btcRestCandlePages[0]['pagination']['deadline_at'],
            $btcRestCandlePages[1]['pagination']['deadline_at'],
        );
        $btcRestTradePages = array_values(array_filter(
            $paginationObservations,
            static fn (array $observation): bool =>
                $observation['stream'] === 'BTCUSDT/rest/public_trade',
        ));
        self::assertCount(2, $btcRestTradePages);
        self::assertSame(
            [
                [0, 10, 2, '1784970038000'],
                [1, 9, 1, '220'],
            ],
            array_map(
                static fn (array $observation): array => [
                    $observation['pagination']['pages_consumed'],
                    $observation['pagination']['pages_remaining'],
                    $observation['pagination']['pagination_type'],
                    $observation['pagination']['next_cursor'],
                ],
                $btcRestTradePages,
            ),
        );
        self::assertSame(
            $btcRestTradePages[0]['pagination']['deadline_at'],
            $btcRestTradePages[1]['pagination']['deadline_at'],
        );
        $completeCheckpoint = self::checkpointState(
            $completedRecorder->datasetDirectory(),
        );
        self::assertSame('complete', $completeCheckpoint['phase']);
        self::assertSame(0, $completeCheckpoint['reconnect']['attempt']);
        self::assertNull($completeCheckpoint['reconnect']['deadline_at']);
        self::assertNull($completeCheckpoint['reconnect']['stable_since']);
        self::assertSame(0, $completeCheckpoint['reconnect']['accepted_events']);

        $capturedEvents = [
            ...$capturedBeforeRestart,
            ...$capturedBeforeFinalizationRestart,
            ...$completedConsumer->capturedEvents,
        ];
        self::assertSame(
            ['210', '220', '225', '250'],
            array_values(array_map(
                static fn (PaperMarketEvent $event): string =>
                    (string) $event->payload['trade_id'],
                array_filter(
                    $capturedEvents,
                    static fn (PaperMarketEvent $event): bool =>
                        $event->symbol === 'BTCUSDT'
                        && $event->channel === PaperMarketDataChannel::PUBLIC_TRADE
                        && ($event->payload['origin'] ?? null) === 'rest_recovery'
                        && \in_array(
                            $event->payload['trade_id'] ?? null,
                            ['210', '220', '225', '250'],
                            true,
                        ),
                ),
            )),
            'Historical trade recovery must emit the exact post-overlap suffix.',
        );
        self::assertSame(
            [
                '1784970000000',
                '1784970031000',
                '1784970032000',
                '1784970033000',
                '1784970034000',
            ],
            array_values(array_map(
                static fn (PaperMarketEvent $event): string =>
                    $event->exchangeTimestamp->format('Uv'),
                array_filter(
                    $capturedEvents,
                    static fn (PaperMarketEvent $event): bool =>
                        $event->symbol === 'BTCUSDT'
                        && $event->channel === PaperMarketDataChannel::CANDLE_1M
                        && ($event->payload['origin'] ?? null) === 'rest_warmup',
                ),
            )),
            'Historical candle recovery must emit the exact post-overlap suffix.',
        );
        self::assertSame($manifest->eventCount, \count($capturedEvents));
        self::assertSame(
            $manifest->eventCount,
            \count(array_unique(array_map(
                static fn (PaperMarketEvent $event): string => $event->eventId,
                $capturedEvents,
            ))),
        );
        $verified = (new PaperDatasetVerifier())->verify(
            $completedRecorder->datasetDirectory(),
        );
        self::assertEquals($manifest, $verified);
        $replayed = iterator_to_array(
            (new PaperReplayReader(
                new PaperDatasetVerifier(),
                new PaperReplayCheckpointStore(),
                new PaperReplayClock(
                    new \DateTimeImmutable('2026-07-25T08:59:00.000000Z'),
                ),
            ))->read(
                $completedRecorder->datasetDirectory(),
                'task9.reconnect-equality',
            ),
            false,
        );
        self::assertSame(
            array_map(
                static fn (PaperMarketEvent $event): array => $event->toArray(),
                $capturedEvents,
            ),
            array_map(
                static fn (PaperMarketEvent $event): array => $event->toArray(),
                $replayed,
            ),
        );
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            self::assertEventExists(
                $capturedEvents,
                $symbol,
                PaperMarketDataChannel::TOP_OF_BOOK,
                'rest_resync_snapshot',
            );
            self::assertCount(1, array_filter(
                $capturedEvents,
                static fn (PaperMarketEvent $event): bool =>
                    $event->symbol === $symbol
                    && $event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                    && ($event->payload['reason'] ?? null) === 'reconnect',
            ), sprintf('%s must retain its exact reconnect snapshot boundary.', $symbol));
        }
    }

    /**
     * @return array{
     *     event: PaperMarketEvent,
     *     event_bytes: string,
     *     captured_events: list<PaperMarketEvent>,
     *     rest_calls: list<array{string, list<mixed>}>,
     *     pagination_observations: list<array{
     *         endpoint: string,
     *         stream: string,
     *         pagination: array<string, mixed>
     *     }>
     * }
     */
    private function captureUntilFinalizedCandleOverlapCrash(
        string $datasetRoot,
        string $datasetId,
        MockClock $clock,
        string $consumerCheckpoint,
    ): array {
        $recorder = new PaperDatasetRecorder(
            $datasetRoot,
            self::manifest($datasetId),
        );
        $rest = Task9RestClient::recoveryFixture($clock);
        $rest->observePaginationCheckpoint($recorder->datasetDirectory());
        $public = new Task9Transport(self::recoveryPublicFrames());
        $business = new Task9Transport(self::subscriptionAcknowledgements(
            self::businessArguments(),
            'task9businessreconnect',
        ));
        $loop = new Task9Loop();
        $source = $this->source(
            $recorder,
            $datasetId,
            $clock,
            $rest,
            $public,
            $business,
            $loop,
            $checkpointStore,
        );
        $loop->scripts[] = static function () use ($loop): void {
            $loop->fireTimer(0.0);
        };
        $consumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            self::recoveryClockAdvancer($clock, $public, $business),
            $consumerCheckpoint,
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $crashEvent = null;
        for ($index = 0; $index < 50; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($event));
            $consumer->consume($datasetId, $event);
            if ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK
                && $event->symbol === 'BTCUSDT'
                && ($event->payload['origin'] ?? null) === 'rest_resync_snapshot'
            ) {
                $crashEvent = $event;
                break;
            }
            $source->acknowledge($event->eventId);
            $events->next();
        }
        self::assertInstanceOf(PaperMarketEvent::class, $crashEvent);
        $state = self::checkpointState($recorder->datasetDirectory());
        self::assertNull(
            $state['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'],
            'Finalized overlap must be durable before the next stream event.',
        );
        self::assertSame(
            $crashEvent->eventId,
            $state['pending_event']['event_id'] ?? null,
        );

        $result = [
            'event' => $crashEvent,
            'event_bytes' => self::eventFileBytes($recorder->datasetDirectory()),
            'captured_events' => $consumer->capturedEvents,
            'rest_calls' => $rest->calls,
            'pagination_observations' => $rest->paginationObservations,
        ];
        $public->release();
        $business->release();
        $loop->release();
        $checkpointStore->__destruct();
        unset($events, $source, $consumer, $recorder, $rest, $public, $business, $loop);
        gc_collect_cycles();

        return $result;
    }

    private function assertRecorderIdentityConflictFailsClosed(): void
    {
        $datasetId = 'okx-task9-recorder-conflict';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $recorder = new PaperDatasetRecorder(
            $this->testRoot . '/recorder-conflict-paper-market-data',
            self::manifest($datasetId),
        );
        $source = $this->source(
            $recorder,
            $datasetId,
            $clock,
            Task9RestClient::completeFixture($clock),
            new Task9Transport(self::publicFrames()),
            new Task9Transport(self::businessFrames()),
            new Task9Loop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        $conflicting = self::conflictingEvent($pending);
        self::assertSame($pending->eventId, $conflicting->eventId);
        self::assertNotSame($pending->payloadHash, $conflicting->payloadHash);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($conflicting));
        $consumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            static function (PaperMarketEvent $_event): void {
            },
        );
        unset($events);

        try {
            (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);
            self::fail('Recorder identity conflict must fail capture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        self::assertSame(1, \count(self::eventLines($recorder->datasetDirectory())));
        self::assertSame(0, $consumer->consumeCount);
        self::assertSame(0, $consumer->effectCount);
        $checkpoint = self::checkpointState($recorder->datasetDirectory());
        self::assertNull($checkpoint['stream_frontiers']['BTCUSDT/rest/candle_1m'] ?? null);
    }

    private function assertDownstreamIdentityConflictFailsClosed(): void
    {
        $datasetId = 'okx-task9-downstream-conflict';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $recorder = new PaperDatasetRecorder(
            $this->testRoot . '/downstream-conflict-paper-market-data',
            self::manifest($datasetId),
        );
        $source = $this->source(
            $recorder,
            $datasetId,
            $clock,
            Task9RestClient::completeFixture($clock),
            new Task9Transport(self::publicFrames()),
            new Task9Transport(self::businessFrames()),
            new Task9Loop(),
        );
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        $consumerCheckpoint = $this->testRoot . '/downstream-conflict-consumer.json';
        self::writeConsumerCheckpoint(
            $consumerCheckpoint,
            [$datasetId . '/' . $pending->eventId => str_repeat('a', 64)],
            1,
        );
        $consumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            static function (PaperMarketEvent $_event): void {
            },
            $consumerCheckpoint,
        );
        unset($events);

        try {
            (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);
            self::fail('Downstream key/hash conflict must fail capture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        self::assertSame(1, \count(self::eventLines($recorder->datasetDirectory())));
        self::assertSame(1, $consumer->consumeCount);
        self::assertSame(1, $consumer->effectCount);
        $checkpoint = self::checkpointState($recorder->datasetDirectory());
        self::assertNull($checkpoint['stream_frontiers']['BTCUSDT/rest/candle_1m'] ?? null);
    }

    private function assertFrontierIdentityConflictFailsClosed(): void
    {
        $datasetId = 'okx-task9-frontier-conflict';
        $clock = new MockClock('2026-07-25T09:00:00.000000Z');
        $recorder = new PaperDatasetRecorder(
            $this->testRoot . '/frontier-conflict-paper-market-data',
            self::manifest($datasetId),
        );
        $publicFrames = self::publicFrames();
        foreach ($publicFrames as &$frame) {
            if (($frame['arg']['channel'] ?? null) !== 'trades'
                || ($frame['arg']['instId'] ?? null) !== 'BTC-USDT-SWAP'
                || !isset($frame['data'][0])
            ) {
                continue;
            }
            $frame['data'][0]['tradeId'] = '100';
            $frame['data'][0]['px'] = '999';
            break;
        }
        unset($frame);
        $source = $this->source(
            $recorder,
            $datasetId,
            $clock,
            Task9RestClient::completeFixture($clock),
            new Task9Transport($publicFrames),
            new Task9Transport(self::businessFrames()),
            new Task9Loop(),
        );
        $consumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            static function (PaperMarketEvent $_event): void {
            },
        );

        try {
            (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);
            self::fail('Stream frontier identity/digest conflict must fail capture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        $lines = self::eventLines($recorder->datasetDirectory());
        self::assertSame(\count($lines), $consumer->consumeCount);
        self::assertSame(\count($lines), $consumer->effectCount);
        self::assertSame(
            \count($lines),
            \count(array_unique(array_column($lines, 'event_id'))),
        );
        self::assertSame(
            [],
            array_values(array_filter(
                $lines,
                static fn (array $line): bool =>
                    ($line['payload']['price'] ?? null) === '999',
            )),
        );
        self::assertSame(
            [],
            array_values(array_filter(
                $consumer->capturedEvents,
                static fn (PaperMarketEvent $event): bool =>
                    ($event->payload['price'] ?? null) === '999',
            )),
        );
        $checkpoint = self::checkpointState($recorder->datasetDirectory());
        self::assertSame('failed', $checkpoint['phase']);
        self::assertSame('market_event_identity_conflict', $checkpoint['failure_reason']);
        self::assertNull($checkpoint['pending_event']);
    }

    private function source(
        PaperDatasetRecorder $recorder,
        string $datasetId,
        MockClock $clock,
        OkxPaperPublicRestClientInterface $rest,
        OkxPaperPublicWebSocketTransportInterface $public,
        OkxPaperPublicWebSocketTransportInterface $business,
        LoopInterface $loop,
        ?OkxPaperLiveCheckpointStore &$checkpointStore = null,
    ): OkxPaperPublicLiveSource {
        $checkpointStore = new OkxPaperLiveCheckpointStore(
            $recorder->datasetDirectory(),
            clock: $clock,
        );

        return new OkxPaperPublicLiveSource(
            $rest,
            $public,
            $business,
            new OkxPaperPublicConfig(
                acquisitionEnabled: true,
                restBaseUri: OkxPaperPublicConfig::REST_BASE_URI,
                webSocketUri: OkxPaperPublicConfig::WEB_SOCKET_URI,
                dataRoot: $this->testRoot,
            ),
            $clock,
            $checkpointStore,
            $checkpointStore->loadOrCreate($datasetId, self::CONFIGURATION_SHA256),
            $loop,
        );
    }

    private function openCrashRuntime(
        string $datasetRoot,
        string $datasetId,
        string $consumerCheckpoint,
        MockClock $clock,
        bool $resumeQueuedFrameOnly = false,
        ?Task9RestClient $rest = null,
        ?Task9Transport $public = null,
        ?Task9Transport $business = null,
        ?Task9Loop $loop = null,
    ): Task9CrashRuntime {
        $recorder = new PaperDatasetRecorder(
            $datasetRoot,
            self::manifest($datasetId),
        );
        $rest ??= Task9RestClient::multiRowWarmupFixture($clock);
        $public ??= new Task9Transport(
            $resumeQueuedFrameOnly
                ? self::subscriptionAcknowledgements(
                    self::publicArguments(),
                    'task9publicwarmuprestart',
                )
                : self::publicFramesWithMultiRowTrades(),
        );
        $business ??= new Task9Transport(
            $resumeQueuedFrameOnly
                ? self::subscriptionAcknowledgements(
                    self::businessArguments(),
                    'task9businesswarmuprestart',
                )
                : self::businessFrames(),
        );
        $loop ??= new Task9Loop();
        $source = $this->source(
            $recorder,
            $datasetId,
            $clock,
            $rest,
            $public,
            $business,
            $loop,
            $checkpointStore,
        );
        $consumer = new Task9IdempotentConsumer(
            $recorder->datasetDirectory(),
            static function (PaperMarketEvent $_event): void {
            },
            $consumerCheckpoint,
        );

        return new Task9CrashRuntime(
            $datasetId,
            $recorder,
            $source,
            $consumer,
            $public,
            $business,
            $loop,
            $checkpointStore,
        );
    }

    private function advanceCrashRuntimeToStreamingTail(Task9CrashRuntime $runtime): void
    {
        $runtime->advanceDurablyUntil(
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::CANDLE_1H
                && $event->symbol === 'ETHUSDT'
                && ($event->payload['origin'] ?? null) === 'ws_candle',
        );
        self::assertSame(PaperDatasetAppendResult::APPENDED, $runtime->appendPending());
        $runtime->consumePending();
        $runtime->acknowledgePending();
    }

    private static function conflictingEvent(PaperMarketEvent $event): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            venue: PaperMarketDataVenue::OKX,
            symbol: $event->symbol,
            channel: $event->channel,
            exchangeTimestamp: $event->exchangeTimestamp,
            receivedTimestamp: $event->receivedTimestamp,
            sequence: $event->sequence,
            payload: [...$event->payload, 'task9_conflict' => true],
        );
    }

    /** @param list<PaperMarketEvent> $events */
    private static function assertBtcEthRestAndWebSocketMatrix(array $events): void
    {
        $matrix = [
            [PaperMarketDataChannel::CANDLE_1M, 'rest_warmup'],
            [PaperMarketDataChannel::CANDLE_5M, 'rest_warmup'],
            [PaperMarketDataChannel::CANDLE_15M, 'rest_warmup'],
            [PaperMarketDataChannel::CANDLE_1H, 'rest_warmup'],
            [PaperMarketDataChannel::PUBLIC_TRADE, 'rest_recovery'],
            [PaperMarketDataChannel::TOP_OF_BOOK, 'rest_initial_snapshot'],
            [PaperMarketDataChannel::CANDLE_1M, 'ws_candle'],
            [PaperMarketDataChannel::CANDLE_5M, 'ws_candle'],
            [PaperMarketDataChannel::CANDLE_15M, 'ws_candle'],
            [PaperMarketDataChannel::CANDLE_1H, 'ws_candle'],
            [PaperMarketDataChannel::PUBLIC_TRADE, 'ws_aggregated'],
            [PaperMarketDataChannel::TOP_OF_BOOK, 'ws_books'],
        ];
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            foreach ($matrix as [$channel, $origin]) {
                self::assertEventExists($events, $symbol, $channel, $origin);
            }
            self::assertCount(1, array_filter(
                $events,
                static fn (PaperMarketEvent $event): bool =>
                    $event->symbol === $symbol
                    && $event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                    && ($event->payload['reason'] ?? null) === 'initial',
            ), sprintf('%s must retain its exact initial snapshot boundary.', $symbol));
        }
    }

    /** @param list<PaperMarketEvent> $events */
    private static function assertEventExists(
        array $events,
        string $symbol,
        PaperMarketDataChannel $channel,
        string $origin,
    ): void {
        self::assertNotEmpty(array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool =>
                $event->symbol === $symbol
                && $event->channel === $channel
                && ($event->payload['origin'] ?? null) === $origin,
        ), sprintf(
            'Missing Task 9 matrix cell %s / %s / %s. Observed: %s',
            $symbol,
            $origin,
            $channel->value,
            json_encode(array_map(
                static fn (PaperMarketEvent $event): array => [
                    $event->symbol,
                    $event->channel->value,
                    $event->payload['origin'] ?? null,
                ],
                $events,
            ), \JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * @param array<string, string> $checkpoints
     */
    private static function writeConsumerCheckpoint(
        string $path,
        array $checkpoints,
        int $effectCount,
    ): void {
        $written = file_put_contents(
            $path,
            json_encode([
                'checkpoints' => $checkpoints,
                'effect_count' => $effectCount,
            ], \JSON_THROW_ON_ERROR),
            \LOCK_EX,
        );
        self::assertIsInt($written);
    }

    private static function eventFileBytes(string $datasetDirectory): string
    {
        $bytes = file_get_contents($datasetDirectory . '/events.ndjson');
        self::assertIsString($bytes);

        return $bytes;
    }

    /** @return list<array<string, mixed>> */
    private static function eventLines(string $datasetDirectory): array
    {
        $bytes = self::eventFileBytes($datasetDirectory);
        if ($bytes === '') {
            return [];
        }
        self::assertStringEndsWith("\n", $bytes);

        return array_map(
            static function (string $line): array {
                $decoded = json_decode(
                    $line,
                    true,
                    512,
                    \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING,
                );
                self::assertIsArray($decoded);

                return $decoded;
            },
            explode("\n", substr($bytes, 0, -1)),
        );
    }

    private static function captureClockAdvancer(MockClock $clock): \Closure
    {
        return static function (PaperMarketEvent $event) use ($clock): void {
            if ($event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                && $event->symbol === 'ETHUSDT'
                && ($event->payload['reason'] ?? null) === 'initial'
            ) {
                $clock->modify('2026-07-25T09:00:14.000000Z');
            }
            if ($event->channel === PaperMarketDataChannel::CANDLE_1H
                && $event->symbol === 'ETHUSDT'
                && ($event->payload['origin'] ?? null) === 'ws_candle'
            ) {
                $clock->modify('2026-07-25T09:00:28.000000Z');
            }
        };
    }

    private static function recoveryClockAdvancer(
        MockClock $clock,
        Task9Transport $public,
        Task9Transport $business,
    ): \Closure {
        return static function (PaperMarketEvent $event) use (
            $clock,
            $public,
            $business,
        ): void {
            if ($event->channel === PaperMarketDataChannel::CANDLE_15M
                && $event->symbol === 'BTCUSDT'
                && $event->exchangeTimestamp->format('Uv') === '1784970030000'
            ) {
                $clock->modify('2026-07-25T09:00:31.000000Z');
            }
            if ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK
                && ($event->payload['origin'] ?? null) === 'rest_resync_snapshot'
            ) {
                $clock->modify(
                    $event->symbol === 'BTCUSDT'
                        ? '2026-07-25T09:00:42.000000Z'
                        : '2026-07-25T09:00:45.000000Z',
                );
            }
            if ($event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                && ($event->payload['reason'] ?? null) === 'reconnect'
            ) {
                $clock->modify(
                    $event->symbol === 'BTCUSDT'
                        ? '2026-07-25T09:00:43.000000Z'
                        : '2026-07-25T09:00:46.000000Z',
                );
            }
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE
                && ($event->payload['trade_id'] ?? null) === '312'
            ) {
                $clock->modify('2026-07-25T09:01:16.000000Z');
                $public->message('pong');
                $business->message('pong');
            }
            if ($event->channel === PaperMarketDataChannel::CONNECTION_STATE
                && $event->symbol === 'BTCUSDT'
                && ($event->payload['state'] ?? null) === 'stopped'
            ) {
                $clock->modify('2026-07-25T09:01:17.000000Z');
            }
        };
    }

    /** @return array<string, mixed> */
    private static function checkpointState(string $datasetDirectory): array
    {
        $contents = file_get_contents(
            $datasetDirectory . '/checkpoints/okx-live/checkpoint.json',
        );
        self::assertIsString($contents);
        $state = json_decode(
            $contents,
            true,
            512,
            \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING,
        );
        self::assertIsArray($state);

        return $state;
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
        $arguments = [];
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentId) {
            foreach (['candle1m', 'candle5m', 'candle15m', 'candle1H'] as $channel) {
                $arguments[] = ['channel' => $channel, 'instId' => $instrumentId];
            }
        }

        return $arguments;
    }

    /**
     * @param list<array{channel: string, instId: string}> $arguments
     * @return list<array<string, mixed>>
     */
    private static function subscriptionAcknowledgements(
        array $arguments,
        string $connectionId,
    ): array {
        return array_map(
            static fn (array $argument): array => [
                'event' => 'subscribe',
                'arg' => $argument,
                'connId' => $connectionId,
            ],
            $arguments,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function recoveryPublicFrames(): array
    {
        $trades = [[
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => '200',
            'px' => '101',
            'sz' => '3',
            'side' => 'buy',
            'source' => '0',
            'ts' => '1784970014000',
            'count' => '1',
            'seqId' => '200',
        ]];
        foreach (range(301, 312) as $tradeId) {
            $trades[] = [
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => (string) $tradeId,
                'px' => '101',
                'sz' => '1',
                'side' => 'buy',
                'source' => '0',
                'ts' => (string) (1784970000000 + (47 + $tradeId - 300) * 1_000),
                'count' => '1',
                'seqId' => (string) $tradeId,
            ];
        }

        return [
            ...self::subscriptionAcknowledgements(
                self::publicArguments(),
                'task9publicreconnect',
            ),
            self::bookFrame(
                'BTC-USDT-SWAP',
                'update',
                '9301',
                '9300',
                '1784970046000',
                '103',
                '102',
            ),
            self::bookFrame(
                'ETH-USDT-SWAP',
                'update',
                '9401',
                '9400',
                '1784970047000',
                '104',
                '103',
            ),
            [
                'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
                'data' => $trades,
            ],
        ];
    }

    private static function manifest(string $datasetId = self::DATASET_ID): PaperDatasetManifest
    {
        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: $datasetId,
            venue: PaperMarketDataVenue::OKX,
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
    }

    /** @return list<array<string, mixed>> */
    private static function publicFrames(): array
    {
        $acks = self::subscriptionAcknowledgements(
            self::publicArguments(),
            'task9public',
        );
        $trade = [
            'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'tradeId' => '200',
                'px' => '101',
                'sz' => '3',
                'side' => 'buy',
                'source' => '0',
                'ts' => '1784970014000',
                'count' => '1',
                'seqId' => '200',
            ]],
        ];

        return [
            ...$acks,
            $trade,
            $trade,
            [
                'arg' => ['channel' => 'trades', 'instId' => 'ETH-USDT-SWAP'],
                'data' => [[
                    'instId' => 'ETH-USDT-SWAP',
                    'tradeId' => '201',
                    'px' => '101',
                    'sz' => '3',
                    'side' => 'sell',
                    'source' => '0',
                    'ts' => '1784970015000',
                    'count' => '1',
                    'seqId' => '201',
                ]],
            ],
            self::bookFrame('BTC-USDT-SWAP', 'snapshot', '9100', null, '1784970016000'),
            self::bookFrame('BTC-USDT-SWAP', 'update', '9101', '9100', '1784970017000'),
            self::bookFrame('ETH-USDT-SWAP', 'snapshot', '9200', null, '1784970018000'),
            self::bookFrame('ETH-USDT-SWAP', 'update', '9201', '9200', '1784970019000'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function publicFramesWithMultiRowTrades(): array
    {
        $frames = self::publicFrames();
        foreach ($frames as &$frame) {
            if (($frame['arg']['channel'] ?? null) !== 'trades'
                || ($frame['arg']['instId'] ?? null) !== 'BTC-USDT-SWAP'
                || !isset($frame['data'][0])
            ) {
                continue;
            }
            $first = $frame['data'][0];
            $second = $first;
            $second['tradeId'] = '202';
            $second['seqId'] = '202';
            $second['ts'] = '1784970014100';
            $third = $first;
            $third['tradeId'] = '203';
            $third['seqId'] = '203';
            $third['ts'] = '1784970014200';
            $frame['data'] = [$first, $second, $third];
            break;
        }
        unset($frame);

        return $frames;
    }

    /** @return list<array<string, mixed>> */
    private static function businessFrames(): array
    {
        $frames = [];
        $second = 20;
        $frames = self::subscriptionAcknowledgements(
            self::businessArguments(),
            'task9business',
        );
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentId) {
            foreach (['candle1m', 'candle5m', 'candle15m', 'candle1H'] as $channel) {
                $timestamp = (string) (1784970000000 + $second * 1_000);
                ++$second;
                $frames[] = [
                    'arg' => ['channel' => $channel, 'instId' => $instrumentId],
                    'data' => [[
                        $timestamp,
                        '100',
                        '102',
                        '99',
                        '101',
                        '10',
                        '1',
                        '1000',
                        '1',
                    ]],
                ];
            }
        }

        return $frames;
    }

    /** @return array<string, mixed> */
    private static function bookFrame(
        string $instrumentId,
        string $action,
        string $sequence,
        ?string $previousSequence,
        string $timestamp,
        string $askPrice = '102',
        string $bidPrice = '101',
    ): array {
        $row = [
            'asks' => [[$askPrice, '2', '0', '1']],
            'bids' => [[$bidPrice, '3', '0', '2']],
            'ts' => $timestamp,
            'seqId' => $sequence,
        ];
        if ($previousSequence !== null) {
            $row['prevSeqId'] = $previousSequence;
        }

        return [
            'arg' => ['channel' => 'books', 'instId' => $instrumentId],
            'action' => $action,
            'data' => [$row],
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
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
}

final class Task9CrashRuntime
{
    private ?\Generator $events = null;

    private ?PaperDatasetRecorder $recorder;
    private ?OkxPaperPublicLiveSource $source;
    private ?Task9IdempotentConsumer $consumer;
    private ?Task9Transport $public;
    private ?Task9Transport $business;
    private ?Task9Loop $loop;
    private ?OkxPaperLiveCheckpointStore $checkpointStore;

    public function __construct(
        private readonly string $datasetId,
        PaperDatasetRecorder $recorder,
        OkxPaperPublicLiveSource $source,
        Task9IdempotentConsumer $consumer,
        Task9Transport $public,
        Task9Transport $business,
        Task9Loop $loop,
        OkxPaperLiveCheckpointStore $checkpointStore,
    ) {
        $this->recorder = $recorder;
        $this->source = $source;
        $this->consumer = $consumer;
        $this->public = $public;
        $this->business = $business;
        $this->loop = $loop;
        $this->checkpointStore = $checkpointStore;
    }

    public function datasetDirectory(): string
    {
        return $this->requireRecorder()->datasetDirectory();
    }

    public function pending(): PaperMarketEvent
    {
        if (!$this->events instanceof \Generator) {
            $events = $this->requireSource()->events();
            if (!$events instanceof \Generator) {
                throw new \LogicException('task9_crash_runtime_generator_expected');
            }
            $this->events = $events;
        }
        $event = $this->events->current();
        if (!$event instanceof PaperMarketEvent) {
            throw new \LogicException('task9_crash_runtime_event_expected');
        }

        return $event;
    }

    public function appendPending(): PaperDatasetAppendResult
    {
        return $this->requireRecorder()->append($this->pending());
    }

    public function consumePending(): void
    {
        $consumer = $this->consumer;
        if (!$consumer instanceof Task9IdempotentConsumer) {
            throw new \LogicException('task9_crash_runtime_consumer_closed');
        }
        $consumer->consume($this->datasetId, $this->pending());
    }

    public function acknowledgePending(): void
    {
        $this->requireSource()->acknowledge($this->pending()->eventId);
    }

    public function effectCount(): int
    {
        $consumer = $this->consumer;
        if (!$consumer instanceof Task9IdempotentConsumer) {
            throw new \LogicException('task9_crash_runtime_consumer_closed');
        }

        return $consumer->effectCount;
    }

    public function advance(): void
    {
        if (!$this->events instanceof \Generator) {
            throw new \LogicException('task9_crash_runtime_generator_missing');
        }
        $this->events->next();
    }

    /** @param array<array-key, mixed>|string $message */
    public function publicMessage(array|string $message): void
    {
        $public = $this->public;
        if (!$public instanceof Task9Transport) {
            throw new \LogicException('task9_crash_runtime_public_closed');
        }
        $public->message($message);
    }

    public function disconnectPublic(): void
    {
        $public = $this->public;
        if (!$public instanceof Task9Transport) {
            throw new \LogicException('task9_crash_runtime_public_closed');
        }
        $public->disconnect();
    }

    public function fireTimer(float $interval): void
    {
        $loop = $this->loop;
        if (!$loop instanceof Task9Loop) {
            throw new \LogicException('task9_crash_runtime_loop_closed');
        }
        $loop->fireTimer($interval);
    }

    public function requestHealthyOperatorStop(): void
    {
        $this->requireSource()->requestHealthyOperatorStop();
    }

    public function valid(): bool
    {
        return $this->events instanceof \Generator && $this->events->valid();
    }

    public function completeDataset(): PaperDatasetManifest
    {
        return $this->requireRecorder()->complete();
    }

    public function advanceDurablyUntil(\Closure $predicate): PaperMarketEvent
    {
        for ($index = 0; $index < 100; ++$index) {
            $event = $this->pending();
            if ($predicate($event)) {
                return $event;
            }
            $result = $this->appendPending();
            if (!\in_array(
                $result,
                [PaperDatasetAppendResult::APPENDED, PaperDatasetAppendResult::REPLAYED],
                true,
            )) {
                throw new \LogicException('task9_crash_runtime_append_invalid');
            }
            $this->consumePending();
            $this->acknowledgePending();
            $this->advance();
        }

        throw new \LogicException('task9_crash_runtime_target_not_found');
    }

    public function crash(): void
    {
        $this->public?->release();
        $this->business?->release();
        $this->loop?->release();
        $this->checkpointStore?->__destruct();
        $this->events = null;
        $this->source = null;
        $this->consumer = null;
        $this->recorder = null;
        $this->public = null;
        $this->business = null;
        $this->loop = null;
        $this->checkpointStore = null;
        gc_collect_cycles();
    }

    private function requireRecorder(): PaperDatasetRecorder
    {
        if (!$this->recorder instanceof PaperDatasetRecorder) {
            throw new \LogicException('task9_crash_runtime_recorder_closed');
        }

        return $this->recorder;
    }

    private function requireSource(): OkxPaperPublicLiveSource
    {
        if (!$this->source instanceof OkxPaperPublicLiveSource) {
            throw new \LogicException('task9_crash_runtime_source_closed');
        }

        return $this->source;
    }
}

final class Task9RestClient implements OkxPaperPublicRestClientInterface
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    private array $candles = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    private array $trades = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    private array $books = [];

    /** @var array<string, list<list<array<array-key, mixed>>>> */
    private array $bookPages = [];

    /** @var list<list<array<array-key, mixed>>> */
    private array $historyCandlePages = [];

    /** @var list<list<array<array-key, mixed>>> */
    private array $historyTradePages = [];

    public ?\Closure $beforeOrderBook = null;

    public ?\Closure $afterHistoryCandlesCheckpoint = null;

    private ?string $checkpointDirectory = null;

    /** @var list<array{endpoint: string, stream: string, pagination: array<string, mixed>}> */
    public array $paginationObservations = [];

    private function __construct(
        private readonly MockClock $clock,
        private readonly bool $warmupClockAdvancement,
    )
    {
    }

    public static function completeFixture(MockClock $clock): self
    {
        $client = new self($clock, true);
        $second = 0;
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentIndex => $instrumentId) {
            if ($instrumentIndex > 0) {
                ++$second;
            }
            foreach (['1m', '5m', '15m', '1H'] as $bar) {
                $timestamp = (string) (1784970000000 + $second * 1_000);
                ++$second;
                $client->candles[$instrumentId . '/' . $bar] = [[
                    $timestamp,
                    '100',
                    '101',
                    '99',
                    '100.5',
                    '10',
                    '1',
                    '1000',
                    '1',
                ]];
            }
            $client->trades[$instrumentId] = [[
                'instId' => $instrumentId,
                'tradeId' => $instrumentId === 'BTC-USDT-SWAP' ? '100' : '101',
                'px' => '100.5',
                'sz' => '2',
                'side' => 'buy',
                'source' => '0',
                'ts' => (string) (1784970000000 + $second * 1_000),
            ]];
            ++$second;
            $client->books[$instrumentId] = [[
                'asks' => [['101', '2', '0', '1']],
                'bids' => [['100', '3', '0', '2']],
                'ts' => (string) (1784970000000 + $second * 1_000),
                'seqId' => $instrumentId === 'BTC-USDT-SWAP' ? '9001' : '9002',
            ]];
            ++$second;
        }

        return $client;
    }

    public static function multiRowWarmupFixture(MockClock $clock): self
    {
        $client = self::completeFixture($clock);
        $client->candles['BTC-USDT-SWAP/1m'][] = [
            '1784970000500',
            '100.5',
            '101.5',
            '99.5',
            '101',
            '11',
            '1',
            '1100',
            '1',
        ];

        return $client;
    }

    public static function bookGapRecoveryFixture(
        MockClock $clock,
        bool $resumed = false,
    ): self
    {
        $fixture = self::completeFixture($clock);
        $client = new self($clock, false);
        $client->candles = $fixture->candles;
        $client->trades = $fixture->trades;
        $client->books = $fixture->books;
        $resyncBook = [[
            'asks' => [['102', '2', '0', '1']],
            'bids' => [['101', '3', '0', '2']],
            'ts' => '1784970029000',
            'seqId' => '9102',
        ]];
        $client->bookPages['BTC-USDT-SWAP'] = $resumed
            ? [$resyncBook]
            : [$client->books['BTC-USDT-SWAP'], $resyncBook];
        if (!$resumed) {
            $client->bookPages['ETH-USDT-SWAP'] = [
                $client->books['ETH-USDT-SWAP'],
            ];
        }

        return $client;
    }

    public static function recoveryFixture(MockClock $clock): self
    {
        $client = new self($clock, false);
        $initial = self::completeFixture($clock);
        $client->candles = $initial->candles;
        $client->trades = $initial->trades;
        $client->books = [
            'BTC-USDT-SWAP' => [[
                'asks' => [['103', '2', '0', '1']],
                'bids' => [['102', '3', '0', '2']],
                'ts' => '1784970040000',
                'seqId' => '9300',
            ]],
            'ETH-USDT-SWAP' => [[
                'asks' => [['104', '2', '0', '1']],
                'bids' => [['103', '3', '0', '2']],
                'ts' => '1784970043000',
                'seqId' => '9400',
            ]],
        ];
        $second = 20;
        foreach (['BTC-USDT-SWAP', 'ETH-USDT-SWAP'] as $instrumentId) {
            foreach (['1m', '5m', '15m', '1H'] as $bar) {
                $client->candles[$instrumentId . '/' . $bar][] = [
                    (string) (1784970000000 + $second * 1_000),
                    '100',
                    '102',
                    '99',
                    '101',
                    '10',
                    '1',
                    '1000',
                    '1',
                ];
                ++$second;
            }
        }
        $client->candles['BTC-USDT-SWAP/15m'][] = [
            '1784970030000',
            '101',
            '103',
            '100',
            '102',
            '11',
            '2',
            '1100',
            '1',
        ];
        $restBtcOneMinute = $client->candles['BTC-USDT-SWAP/1m'][0];
        $webSocketBtcOneMinute = $client->candles['BTC-USDT-SWAP/1m'][1];
        $client->candles['BTC-USDT-SWAP/1m'] = [[
            '1784970034000',
            '101',
            '103',
            '100',
            '102',
            '11',
            '2',
            '1100',
            '1',
        ]];
        $client->historyCandlePages = [
            [
                [
                    '1784970033000',
                    '101',
                    '103',
                    '100',
                    '102',
                    '11',
                    '2',
                    '1100',
                    '1',
                ],
                [
                    '1784970032000',
                    '101',
                    '103',
                    '100',
                    '102',
                    '11',
                    '2',
                    '1100',
                    '1',
                ],
            ],
            [
                [
                    '1784970031000',
                    '101',
                    '103',
                    '100',
                    '102',
                    '11',
                    '2',
                    '1100',
                    '1',
                ],
                $webSocketBtcOneMinute,
                $restBtcOneMinute,
                [
                    '1784969999000',
                    '99',
                    '100',
                    '98',
                    '99.5',
                    '9',
                    '1',
                    '900',
                    '1',
                ],
            ],
        ];
        $client->historyCandlePages = [
            ...$client->historyCandlePages,
            ...$client->historyCandlePages,
        ];
        $restBtcTrade = $initial->trades['BTC-USDT-SWAP'][0];
        $client->trades['BTC-USDT-SWAP'] = [[
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => '250',
            'px' => '102',
            'sz' => '1',
            'side' => 'buy',
            'source' => '0',
            'ts' => '1784970038000',
        ]];
        $client->trades['ETH-USDT-SWAP'][] = [
            'instId' => 'ETH-USDT-SWAP',
            'tradeId' => '201',
            'px' => '101',
            'sz' => '3',
            'side' => 'sell',
            'source' => '0',
            'ts' => '1784970015000',
        ];
        $client->historyTradePages = [
            [
                [
                    'instId' => 'BTC-USDT-SWAP',
                    'tradeId' => '225',
                    'px' => '101',
                    'sz' => '1',
                    'side' => 'buy',
                    'source' => '0',
                    'ts' => '1784970037000',
                ],
                [
                    'instId' => 'BTC-USDT-SWAP',
                    'tradeId' => '220',
                    'px' => '101',
                    'sz' => '1',
                    'side' => 'buy',
                    'source' => '0',
                    'ts' => '1784970036000',
                ],
            ],
            [
                [
                    'instId' => 'BTC-USDT-SWAP',
                    'tradeId' => '210',
                    'px' => '101',
                    'sz' => '1',
                    'side' => 'buy',
                    'source' => '0',
                    'ts' => '1784970035000',
                ],
                [
                    'instId' => 'BTC-USDT-SWAP',
                    'tradeId' => '200',
                    'px' => '101',
                    'sz' => '3',
                    'side' => 'buy',
                    'source' => '0',
                    'ts' => '1784970014000',
                ],
                $restBtcTrade,
                [
                    'instId' => 'BTC-USDT-SWAP',
                    'tradeId' => '90',
                    'px' => '99',
                    'sz' => '1',
                    'side' => 'buy',
                    'source' => '0',
                    'ts' => '1784970003000',
                ],
            ],
        ];
        $client->historyTradePages = [
            ...$client->historyTradePages,
            ...$client->historyTradePages,
        ];

        return $client;
    }

    public function observePaginationCheckpoint(string $datasetDirectory): void
    {
        $this->checkpointDirectory = $datasetDirectory . '/checkpoints/okx-live';
    }

    public function historyCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        int $limit = 300,
    ): array {
        $this->calls[] = ['historyCandles', func_get_args()];
        $this->recordPaginationObservation('history_candles');
        ($this->afterHistoryCandlesCheckpoint ?? static function (): void {
        })();

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
        if ($this->warmupClockAdvancement
            && $instrumentId === 'ETH-USDT-SWAP'
            && $bar === '1m'
        ) {
            $this->clock->modify('2026-07-25T09:00:07.000000Z');
        }

        return $this->candles[$instrumentId . '/' . $bar] ?? [];
    }

    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array {
        $this->calls[] = ['historyTrades', func_get_args()];
        $this->recordPaginationObservation('history_trades');

        return array_shift($this->historyTradePages) ?? [];
    }

    private function recordPaginationObservation(string $endpoint): void
    {
        if ($this->checkpointDirectory === null) {
            return;
        }
        $state = json_decode(
            (string) file_get_contents($this->checkpointDirectory . '/checkpoint.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $stream = $state['pending_transition']['stream'] ?? null;
        $pagination = \is_string($stream)
            ? ($state['overlap_pagination_by_stream'][$stream] ?? null)
            : null;
        if (!\is_string($stream) || !\is_array($pagination)) {
            throw new \LogicException('task9_pagination_checkpoint_missing');
        }
        $this->paginationObservations[] = [
            'endpoint' => $endpoint,
            'stream' => $stream,
            'pagination' => $pagination,
        ];
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        $this->calls[] = ['recentTrades', func_get_args()];

        return $this->trades[$instrumentId] ?? [];
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        ($this->beforeOrderBook ?? static function (): void {
        })();
        $this->calls[] = ['orderBook', func_get_args()];
        if (($this->bookPages[$instrumentId] ?? []) !== []) {
            return array_shift($this->bookPages[$instrumentId]);
        }
        if ($this->warmupClockAdvancement) {
            $this->clock->modify(
                $instrumentId === 'BTC-USDT-SWAP'
                    ? '2026-07-25T09:00:06.000000Z'
                    : '2026-07-25T09:00:13.000000Z',
            );
        }

        return $this->books[$instrumentId] ?? [];
    }
}

final class Task9Transport implements OkxPaperPublicWebSocketTransportInterface
{
    private ?\Closure $onMessage = null;
    private ?\Closure $onClose = null;

    /** @var list<array<array-key, mixed>> */
    public array $sent = [];

    /** @param list<array<string, mixed>> $frames */
    public function __construct(private array $frames)
    {
    }

    public function connect(
        string $uri,
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        $this->onMessage = \Closure::fromCallable($onMessage);
        $this->onClose = \Closure::fromCallable($onClose);
        $onOpen();
    }

    public function send(array $message): void
    {
        $this->sent[] = $message;
        if (($message['op'] ?? null) !== 'subscribe') {
            return;
        }
        foreach ($this->frames as $frame) {
            ($this->onMessage ?? throw new \LogicException('task9_transport_not_connected'))(
                json_encode($frame, \JSON_THROW_ON_ERROR),
            );
        }
        $this->frames = [];
    }

    public function close(): void
    {
    }

    /** @param array<array-key, mixed>|string $message */
    public function message(array|string $message): void
    {
        ($this->onMessage ?? throw new \LogicException('task9_transport_not_connected'))(
            \is_string($message)
                ? $message
                : json_encode($message, \JSON_THROW_ON_ERROR),
        );
    }

    public function disconnect(?int $code = null): void
    {
        ($this->onClose ?? throw new \LogicException('task9_transport_not_connected'))(
            $code,
        );
    }

    public function release(): void
    {
        $this->onMessage = null;
        $this->onClose = null;
    }
}

final class Task9Loop implements LoopInterface
{
    public ?\Closure $onRun = null;

    public ?\Closure $beforeAddTimer = null;

    /** @var list<callable> */
    public array $scripts = [];

    /** @var list<array{timer: Task9Timer, callback: callable}> */
    private array $timers = [];

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        ($this->beforeAddTimer ?? static function (): void {
        })((float) $interval);
        $timer = new Task9Timer((float) $interval, $callback);
        $this->timers[] = ['timer' => $timer, 'callback' => $callback];

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        return $this->addTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (array $entry): bool => $entry['timer'] !== $timer,
        ));
    }

    public function futureTick($listener): void
    {
        $listener();
    }

    public function addSignal($signal, $listener): void
    {
    }

    public function removeSignal($signal, $listener): void
    {
    }

    public function run(): void
    {
        $script = array_shift($this->scripts);
        if ($script !== null) {
            $script();

            return;
        }
        $onRun = $this->onRun;
        ($onRun ?? static function (): void {
        })();
    }

    public function stop(): void
    {
    }

    public function fireTimer(float $interval): void
    {
        foreach ($this->timers as $index => $entry) {
            if ($entry['timer']->getInterval() !== $interval) {
                continue;
            }
            array_splice($this->timers, $index, 1);
            ($entry['callback'])();

            return;
        }

        throw new \LogicException('task9_timer_not_found');
    }

    public function release(): void
    {
        $this->onRun = null;
        $this->scripts = [];
        foreach ($this->timers as $entry) {
            $entry['timer']->release();
        }
        $this->timers = [];
    }
}

final class Task9Timer implements TimerInterface
{
    public function __construct(
        private float $interval,
        private mixed $callback,
    ) {
    }

    public function getInterval(): float
    {
        return $this->interval;
    }

    public function getCallback(): callable
    {
        if (!is_callable($this->callback)) {
            throw new \LogicException('task9_timer_callback_invalid');
        }

        return $this->callback;
    }

    public function isPeriodic(): bool
    {
        return false;
    }

    public function release(): void
    {
        $this->callback = null;
    }
}

final class Task9MetadataClient implements OkxPaperInstrumentMetadataClientInterface
{
    public function instrumentMetadata(string $instrumentId): array
    {
        $baseAsset = match ($instrumentId) {
            'BTC-USDT-SWAP' => 'BTC',
            'ETH-USDT-SWAP' => 'ETH',
            default => throw new \LogicException('task9_instrument_invalid'),
        };

        return [
            'instId' => $instrumentId,
            'instType' => 'SWAP',
            'ctType' => 'linear',
            'ctVal' => '0.01',
            'ctMult' => '1',
            'ctValCcy' => $baseAsset,
            'settleCcy' => 'USDT',
            'tickSz' => '0.1',
            'lotSz' => '1',
            'minSz' => '1',
            'maxMktSz' => '1000',
            'maxLmtSz' => '2000',
            'lever' => '100',
            'state' => 'live',
        ];
    }
}

final class Task9FundingClient implements OkxPaperFundingRateClientInterface
{
    public function fundingRate(string $instrumentId): array
    {
        return [
            'instId' => $instrumentId,
            'instType' => 'SWAP',
            'fundingRate' => '0.0001',
            'fundingTime' => '1784995200000',
            'nextFundingTime' => '1785024000000',
            'method' => 'current_period',
            'formulaType' => 'withRate',
            'settState' => 'settled',
            'ts' => '1784969999000',
        ];
    }
}

final class Task9IdempotentConsumer implements PaperLiveEventConsumerInterface
{
    /** @var list<PaperMarketEvent> */
    public array $capturedEvents = [];

    /** @var array<string, string> */
    private array $checkpoints = [];

    public int $consumeCount = 0;

    public int $effectCount = 0;

    public function __construct(
        private readonly string $datasetDirectory,
        private readonly \Closure $afterConsume,
        private readonly ?string $checkpointPath = null,
    ) {
        if ($checkpointPath === null || !is_file($checkpointPath)) {
            return;
        }
        $contents = file_get_contents($checkpointPath);
        if (!\is_string($contents)) {
            throw new \RuntimeException('task9_consumer_checkpoint_unreadable');
        }
        $state = json_decode(
            $contents,
            true,
            512,
            \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING,
        );
        if (!\is_array($state)
            || !\is_array($state['checkpoints'] ?? null)
            || !\is_int($state['effect_count'] ?? null)
        ) {
            throw new \RuntimeException('task9_consumer_checkpoint_invalid');
        }
        /** @var array<string, string> $checkpoints */
        $checkpoints = $state['checkpoints'];
        $this->checkpoints = $checkpoints;
        $this->effectCount = $state['effect_count'];
    }

    public function consume(string $datasetId, PaperMarketEvent $event): void
    {
        ++$this->consumeCount;
        $events = file_get_contents($this->datasetDirectory . '/events.ndjson');
        $manifest = file_get_contents($this->datasetDirectory . '/manifest.json');
        if (!\is_string($events)
            || !str_contains($events, $event->eventId)
            || !\is_string($manifest)
            || !str_contains($manifest, $event->eventId)
        ) {
            throw new \RuntimeException('task9_event_not_durable_before_effect');
        }
        $key = $datasetId . '/' . $event->eventId;
        if (isset($this->checkpoints[$key])) {
            if (!hash_equals($this->checkpoints[$key], $event->payloadHash)) {
                throw new \RuntimeException('market_event_identity_conflict');
            }

            return;
        }
        $this->checkpoints[$key] = $event->payloadHash;
        ++$this->effectCount;
        if ($this->checkpointPath !== null) {
            file_put_contents(
                $this->checkpointPath,
                json_encode([
                    'checkpoints' => $this->checkpoints,
                    'effect_count' => $this->effectCount,
                ], \JSON_THROW_ON_ERROR),
                \LOCK_EX,
            );
        }
        $this->capturedEvents[] = $event;
        ($this->afterConsume)($event);
    }
}
