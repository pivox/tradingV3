<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayCheckpoint;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperReplayReader::class)]
final class PaperReplayReaderTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-replay-reader-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create private replay reader test directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            self::fail('Unable to resolve replay reader test directory.');
        }
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testVerifiesCompleteDatasetBeforeYieldingAnything(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $clock = new PaperReplayClock(new \DateTimeImmutable('2026-07-19T09:00:00Z'));
        $reader = $this->reader($clock);
        $yielded = 0;

        try {
            foreach ($reader->read($recorder->datasetDirectory(), 'paper.worker-01') as $_event) {
                ++$yielded;
            }
            self::fail('A RECORDING dataset must be rejected before replay.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_complete', $exception->getMessage());
        }

        self::assertSame(0, $yielded);
        self::assertNull($reader->currentEventIndex());
        self::assertSame('2026-07-19T09:00:00.000000Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testSortsByExactBusinessKeyAndIgnoresReceivedTimestamp(): void
    {
        $timestamp = '2026-07-19T10:00:00.000000Z';
        $later = $this->event('BTCUSDT', PaperMarketDataChannel::CANDLE_1M, '1', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z');
        $topTen = $this->event('BTCUSDT', PaperMarketDataChannel::TOP_OF_BOOK, '10', $timestamp, '2026-07-19T10:00:00.100000Z');
        $topTwo = $this->event('ETHUSDT', PaperMarketDataChannel::TOP_OF_BOOK, '2', $timestamp, '2026-07-19T10:00:00.200000Z');
        $topNull = $this->event('BTCUSDT', PaperMarketDataChannel::TOP_OF_BOOK, null, $timestamp, '2026-07-19T10:00:00.000001Z', ['kind' => 'null']);
        $tradeOneBtc = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', $timestamp, '2026-07-19T10:00:00.900000Z');
        $tradeOneEth = $this->event('ETHUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', $timestamp, '2026-07-19T10:00:00.800000Z');
        $tradeTwo = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', $timestamp, '2026-07-19T10:00:00.000001Z');
        $candle = $this->event('ETHUSDT', PaperMarketDataChannel::CANDLE_1M, null, $timestamp, '2026-07-19T10:00:00.999999Z');
        $dataset = $this->completeDataset([
            $later,
            $topTen,
            $topTwo,
            $topNull,
            $tradeOneBtc,
            $tradeOneEth,
            $tradeTwo,
            $candle,
        ]);
        $clock = new PaperReplayClock(new \DateTimeImmutable($timestamp));
        $reader = $this->reader($clock);
        $actual = [];

        foreach ($reader->read($dataset['directory'], 'paper.worker-01') as $event) {
            self::assertEquals($event->exchangeTimestamp, $clock->now());
            self::assertSame(count($actual), $reader->currentEventIndex());
            $actual[] = $event->eventId;
        }

        $sequenceOne = [$tradeOneBtc->eventId, $tradeOneEth->eventId];
        sort($sequenceOne, SORT_STRING);
        self::assertSame([
            $candle->eventId,
            ...$sequenceOne,
            $tradeTwo->eventId,
            $topTwo->eventId,
            $topTen->eventId,
            $topNull->eventId,
            $later->eventId,
        ], $actual);
        self::assertSame(7, $reader->currentEventIndex());
    }

    public function testHyperliquidHistoricalReplayPreservesMultiSymbolMultiIntervalCaptureOrder(): void
    {
        $close = '2024-01-01T00:59:59.999000Z';
        $events = [];
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $topSequence = 0;
            foreach ([
                [PaperMarketDataChannel::CANDLE_1M, 60_000],
                [PaperMarketDataChannel::CANDLE_5M, 300_000],
                [PaperMarketDataChannel::CANDLE_15M, 900_000],
                [PaperMarketDataChannel::CANDLE_1H, 3_600_000],
            ] as [$channel, $duration]) {
                $events[] = $this->hyperliquidHistoricalEvent(
                    $symbol,
                    $channel,
                    '1',
                    $close,
                    ['interval' => match ($channel) {
                        PaperMarketDataChannel::CANDLE_1M => '1m',
                        PaperMarketDataChannel::CANDLE_5M => '5m',
                        PaperMarketDataChannel::CANDLE_15M => '15m',
                        PaperMarketDataChannel::CANDLE_1H => '1h',
                    }],
                );
                $events[] = $this->hyperliquidHistoricalEvent(
                    $symbol,
                    PaperMarketDataChannel::TOP_OF_BOOK,
                    (string) ++$topSequence,
                    $close,
                    $this->hyperliquidBookPayload($close, $duration),
                );
            }
        }
        $dataset = $this->completeHyperliquidDataset($events);
        $reader = $this->reader(new PaperReplayClock($events[0]->exchangeTimestamp));

        $replayed = iterator_to_array(
            $reader->read($dataset['directory'], 'hyperliquid.history-order'),
            false,
        );

        self::assertSame(
            array_map(static fn (PaperMarketEvent $event): array => $event->toArray(), $events),
            array_map(static fn (PaperMarketEvent $event): array => $event->toArray(), $replayed),
        );
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidHyperliquidBookIntervalProvider(): iterable
    {
        yield 'missing start' => [null, '2024-01-01T00:59:59.999000Z'];
        yield 'non scalar start' => [['invalid'], '2024-01-01T00:59:59.999000Z'];
        yield 'negative start' => ['-1', '2024-01-01T00:59:59.999000Z'];
        yield 'overflowing start' => [str_repeat('9', 128), '2024-01-01T00:59:59.999000Z'];
        yield 'unsupported duration' => ['1704070739999', '2024-01-01T00:59:59.999000Z'];
        yield 'non millisecond close' => ['1704070740000', '2024-01-01T00:59:59.999001Z'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSingletonHyperliquidBookStartProvider(): iterable
    {
        yield 'missing start' => [null];
        yield 'non scalar start' => [['invalid']];
        yield 'invalid string start' => ['not-an-integer'];
    }

    #[DataProvider('invalidSingletonHyperliquidBookStartProvider')]
    public function testHyperliquidHistoricalReplayValidatesSingletonBookBeforeYield(
        mixed $sourceStart,
    ): void {
        $close = '2024-01-01T00:59:59.999000Z';
        $payload = $this->hyperliquidBookPayload($close, 60_000);
        if ($sourceStart === null) {
            unset($payload['source_candle_start']);
        } else {
            $payload['source_candle_start'] = $sourceStart;
        }
        $this->assertHyperliquidReplayIntervalFailure([
            $this->hyperliquidHistoricalEvent(
                'BTCUSDT',
                PaperMarketDataChannel::TOP_OF_BOOK,
                '1',
                $close,
                $payload,
            ),
        ], 'hl_candle_atr_top_v1');
    }

    public function testHyperliquidHistoricalReplayValidatesEveryEventBeforeComparatorEarlyReturn(): void
    {
        $cases = [
            'different timestamps' => [
                $this->hyperliquidHistoricalEvent(
                    'BTCUSDT',
                    PaperMarketDataChannel::CANDLE_1M,
                    '1',
                    '2024-01-01T00:00:59.999000Z',
                    ['interval' => '1m'],
                ),
                $this->invalidHyperliquidBook(
                    'BTCUSDT',
                    '2024-01-01T00:01:59.999000Z',
                ),
            ],
            'different symbols' => [
                $this->hyperliquidHistoricalEvent(
                    'BTCUSDT',
                    PaperMarketDataChannel::CANDLE_1M,
                    '1',
                    '2024-01-01T00:59:59.999000Z',
                    ['interval' => '1m'],
                ),
                $this->invalidHyperliquidBook(
                    'ETHUSDT',
                    '2024-01-01T00:59:59.999000Z',
                ),
            ],
        ];

        foreach ($cases as $case => $events) {
            try {
                $this->assertHyperliquidReplayIntervalFailure(
                    $events,
                    'hl_candle_atr_top_v1',
                );
            } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
                self::fail($case . ': ' . $failure->getMessage());
            }
        }
    }

    public function testSingletonHyperliquidHistoricalIntervalFailureRedactsInvalidModelPayload(): void
    {
        $sentinel = 'HYPERLIQUID_SINGLETON_INTERVAL_SENTINEL_63f0ea';
        $close = '2024-01-01T00:59:59.999000Z';
        $payload = $this->hyperliquidBookPayload($close, 60_000);
        $payload['source_candle_start'] = $sentinel;

        $this->assertHyperliquidReplayIntervalFailure([
            $this->hyperliquidHistoricalEvent(
                'BTCUSDT',
                PaperMarketDataChannel::TOP_OF_BOOK,
                '1',
                $close,
                $payload,
            ),
        ], $sentinel);
    }

    public function testHyperliquidHistoricalReplayRejectsDurationValidButShiftedBookGrid(): void
    {
        $close = '2024-01-01T01:00:00.000000Z';

        $this->assertHyperliquidReplayIntervalFailure([
            $this->hyperliquidHistoricalEvent(
                'BTCUSDT',
                PaperMarketDataChannel::TOP_OF_BOOK,
                '1',
                $close,
                $this->hyperliquidBookPayload($close, 60_000),
            ),
        ]);
    }

    public function testHyperliquidHistoricalReplayAcceptsAlignedZeroEpochBoundary(): void
    {
        $close = '1970-01-01T00:00:59.999000Z';
        $candle = $this->hyperliquidHistoricalEvent(
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            '1',
            $close,
            ['interval' => '1m'],
        );
        $event = $this->hyperliquidHistoricalEvent(
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            '1',
            $close,
            $this->hyperliquidBookPayload($close, 60_000),
        );
        $dataset = $this->completeHyperliquidDataset([$candle, $event]);

        $replayed = iterator_to_array(
            $this->reader(new PaperReplayClock($event->exchangeTimestamp))
                ->read($dataset['directory'], 'hyperliquid.zero-grid-boundary'),
            false,
        );

        self::assertSame([$candle->toArray(), $event->toArray()], array_map(
            static fn (PaperMarketEvent $replayedEvent): array => $replayedEvent->toArray(),
            $replayed,
        ));
    }

    #[DataProvider('invalidHyperliquidBookIntervalProvider')]
    public function testHyperliquidHistoricalReplayRejectsInvalidBookIntervalWithStableReason(
        mixed $sourceStart,
        string $close,
    ): void {
        [$events, $dataset] = $this->invalidIntervalDataset($sourceStart, $close);

        try {
            iterator_to_array(
                $this->reader(new PaperReplayClock($events[0]->exchangeTimestamp))
                    ->read($dataset['directory'], 'hyperliquid.invalid-interval'),
                false,
            );
            self::fail('Invalid modelled-book interval metadata must fail replay ordering.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_hyperliquid_model_event_invalid', $exception->getMessage());
        }
    }

    public function testHyperliquidHistoricalIntervalFailureRedactsInvalidModelPayload(): void
    {
        $sentinel = 'HYPERLIQUID_REPLAY_INTERVAL_SENTINEL_53de2b';
        [$events, $dataset] = $this->invalidIntervalDataset(
            $sentinel,
            '2024-01-01T00:59:59.999000Z',
        );
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);

        try {
            iterator_to_array(
                $this->reader(new PaperReplayClock($events[0]->exchangeTimestamp))
                    ->read($dataset['directory'], 'hyperliquid.invalid-interval-redaction'),
                false,
            );
            self::fail('Invalid modelled-book interval metadata must fail replay ordering.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_hyperliquid_model_event_invalid', $exception->getMessage());
            $trace = (string) $exception . "\n" . print_r($exception->getTrace(), true);
            self::assertStringNotContainsString($sentinel, $trace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function testResumeSkipsExactlyThroughCheckpointAndYieldsTheFollowingEvent(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '3', '2026-07-19T10:00:02.000000Z', '2026-07-19T10:00:02.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $checkpoint = $this->checkpoint($dataset['manifest'], 'paper.worker-01', $events[1], 1);
        $store = new PaperReplayCheckpointStore();
        $store->save($dataset['directory'], $checkpoint);
        $clock = new PaperReplayClock($events[1]->exchangeTimestamp);
        $reader = new PaperReplayReader(new PaperDatasetVerifier(), $store, $clock);

        $yielded = iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);

        self::assertSame([$events[2]->eventId], array_map(
            static fn (PaperMarketEvent $event): string => $event->eventId,
            $yielded,
        ));
        self::assertSame(2, $reader->currentEventIndex());
        self::assertEquals($events[2]->exchangeTimestamp, $clock->now());
    }

    public function testRejectsCheckpointDatasetChecksumAndConsumerMismatches(): void
    {
        $event = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z');
        $dataset = $this->completeDataset([$event]);
        $cases = [
            'paper_replay_checkpoint_dataset_mismatch' => new PaperReplayCheckpoint(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
                'dataset-other-001', 'paper.worker-01', $event->eventId, 0, $event->exchangeTimestamp, $dataset['manifest']->eventsFileSha256 ?? ''
            ),
            'paper_replay_checkpoint_checksum_mismatch' => new PaperReplayCheckpoint(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
                $dataset['manifest']->datasetId, 'paper.worker-01', $event->eventId, 0, $event->exchangeTimestamp, str_repeat('f', 64)
            ),
            'paper_replay_checkpoint_consumer_mismatch' => new PaperReplayCheckpoint(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
                $dataset['manifest']->datasetId, 'paper.worker-02', $event->eventId, 0, $event->exchangeTimestamp, $dataset['manifest']->eventsFileSha256 ?? ''
            ),
            'paper_replay_checkpoint_network_mismatch' => new PaperReplayCheckpoint(
                \App\Trading\Paper\MarketData\PaperMarketDataNetwork::TESTNET,
                $dataset['manifest']->datasetId,
                'paper.worker-01',
                $event->eventId,
                0,
                $event->exchangeTimestamp,
                $dataset['manifest']->eventsFileSha256 ?? '',
            ),
        ];

        foreach ($cases as $error => $checkpoint) {
            $reader = $this->reader(new PaperReplayClock($event->exchangeTimestamp));
            try {
                iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01', $checkpoint), false);
                self::fail('Foreign checkpoints must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame($error, $exception->getMessage());
                self::assertNull($reader->currentEventIndex());
            }
        }
    }

    public function testRejectsCheckpointEventMissingOrAtAnIncoherentIndex(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $missing = new PaperReplayCheckpoint(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            $dataset['manifest']->datasetId,
            'paper.worker-01',
            str_repeat('f', 64),
            0,
            $events[0]->exchangeTimestamp,
            $dataset['manifest']->eventsFileSha256 ?? '',
        );
        $wrongIndex = $this->checkpoint($dataset['manifest'], 'paper.worker-01', $events[0], 1);

        foreach (['paper_replay_checkpoint_event_not_found' => $missing, 'paper_replay_checkpoint_event_mismatch' => $wrongIndex] as $error => $checkpoint) {
            $reader = $this->reader(new PaperReplayClock($events[0]->exchangeTimestamp));
            try {
                iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01', $checkpoint), false);
                self::fail('An incoherent checkpoint event must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }
    }

    public function testRejectsConfiguredEventLimitBeforeSortingOrYielding(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $clock = new PaperReplayClock($events[0]->exchangeTimestamp);
        $reader = new PaperReplayReader(new PaperDatasetVerifier(), new PaperReplayCheckpointStore(), $clock, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_replay_event_limit_exceeded');

        iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
    }

    public function testConfiguredEventLimitBoundsVerificationWhenManifestUnderstatesCount(): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $eventsPath = $dataset['directory'] . '/events.ndjson';
        $eventLine = file_get_contents($eventsPath);
        self::assertIsString($eventLine);
        self::assertSame(strlen($eventLine), file_put_contents($eventsPath, $eventLine, FILE_APPEND));

        $manifestPath = $dataset['directory'] . '/manifest.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );
        self::assertIsArray($manifest);
        $manifest['events_file_sha256'] = hash_file('sha256', $eventsPath);
        self::assertGreaterThan(0, file_put_contents(
            $manifestPath,
            CanonicalJson::encode($manifest) . "\n",
        ));

        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($event->exchangeTimestamp),
            1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_replay_event_limit_exceeded');

        iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
    }

    public function testClockRegressionDoesNotExposeAnEventIndexThatWasNotYielded(): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $reader = $this->reader(new PaperReplayClock(new \DateTimeImmutable('2026-07-19T10:00:01Z')));

        try {
            iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
            self::fail('A clock initialized after the first event must reject replay regression.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_replay_clock_regression', $exception->getMessage());
        }

        self::assertNull($reader->currentEventIndex());
    }

    public function testRejectsEventsPathSubstitutionAfterVerifiedOpen(): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $eventsPath = $dataset['directory'] . '/events.ndjson';
        $replacementPath = $this->testRoot . '/replacement-events.ndjson';
        self::assertTrue(copy($eventsPath, $replacementPath));
        self::assertTrue(chmod($replacementPath, 0600));
        $filesystem = new EventsPathSwapFilesystem($eventsPath, $replacementPath);
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($event->exchangeTimestamp),
            PaperReplayReader::DEFAULT_EVENT_LIMIT,
            filesystem: $filesystem,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_symlink_rejected');

        iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
    }

    public function testRejectsSameInodeEventsAppendAfterEof(): void
    {
        $this->assertRejectsEventsInPlaceSizeChange(true);
    }

    public function testRejectsSameInodeEventsTruncateAfterEof(): void
    {
        $this->assertRejectsEventsInPlaceSizeChange(false);
    }

    public function testRejectsDatasetDirectorySubstitutionBetweenVerifyAndReadBeforeYield(): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $filesystem = new DatasetDirectorySwapOnEventsOpenFilesystem(
            $dataset['directory'],
            $this->testRoot . '/displaced-before-read',
        );
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($event->exchangeTimestamp),
            filesystem: $filesystem,
        );

        try {
            iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
            self::fail('A substituted dataset directory must be rejected before any yield.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }

        self::assertNull($reader->currentEventIndex());
    }

    public function testRejectsDatasetDirectorySubstitutionImmediatelyAfterCheckpointLoad(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        (new PaperReplayCheckpointStore())->save(
            $dataset['directory'],
            $this->checkpoint($dataset['manifest'], 'paper.worker-01', $events[0], 0),
        );
        $filesystem = new DatasetDirectorySwapAtBoundaryFilesystem(
            $dataset['directory'],
            $this->testRoot . '/displaced-after-load',
            'paper_replay_dataset_after_checkpoint_load',
        );
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($events[0]->exchangeTimestamp),
            filesystem: $filesystem,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_directory_changed');

        iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
    }

    public function testRejectsTemporaryDatasetReplacementDuringCheckpointLoadBeforeItCanSkipEvents(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $replacement = $this->testRoot . '/replacement-dataset-during-checkpoint-load';
        self::assertTrue(mkdir($replacement, 0700));
        foreach (['manifest.json', 'events.ndjson'] as $filename) {
            self::assertTrue(copy($dataset['directory'] . '/' . $filename, $replacement . '/' . $filename));
            self::assertTrue(chmod($replacement . '/' . $filename, 0600));
        }
        (new PaperReplayCheckpointStore())->save(
            $replacement,
            $this->checkpoint($dataset['manifest'], 'paper.worker-01', $events[1], 1),
        );
        $checkpointFilesystem = new TemporaryDatasetReplacementOnCheckpointLoadFilesystem(
            $dataset['directory'],
            $this->testRoot . '/displaced-dataset-during-checkpoint-load',
            $replacement,
        );
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore($checkpointFilesystem),
            new PaperReplayClock($events[0]->exchangeTimestamp),
        );

        $failure = null;
        try {
            iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        } finally {
            $checkpointFilesystem->restoreOriginalDataset();
        }

        self::assertNotNull($failure, 'A temporary dataset replacement must not supply a forged resume checkpoint.');
        self::assertSame('paper_replay_checkpoint_directory_invalid', $failure->getMessage());
        self::assertTrue($checkpointFilesystem->restored);
        self::assertNull($reader->currentEventIndex());
    }

    public function testRevalidatesPinnedDatasetBeforeEveryYieldAfterGeneratorResume(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $reader = $this->reader(new PaperReplayClock($events[0]->exchangeTimestamp));
        $generator = $reader->read($dataset['directory'], 'paper.worker-01');

        self::assertSame($events[0]->eventId, $generator->current()->eventId);
        $displaced = $this->testRoot . '/displaced-after-yield';
        self::assertTrue(rename($dataset['directory'], $displaced));
        self::assertTrue(mkdir($dataset['directory'], 0700));

        try {
            $generator->next();
            self::fail('A dataset swap while the generator is suspended must block the next yield.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }

        self::assertFalse($generator->valid());
    }

    private function assertRejectsEventsInPlaceSizeChange(bool $append): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $eventsPath = $dataset['directory'] . '/events.ndjson';
        $before = lstat($eventsPath);
        self::assertIsArray($before);
        self::assertIsInt($before['size']);
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($event->exchangeTimestamp),
            filesystem: new EventsInPlaceSizeChangeAfterEofFilesystem($eventsPath, $append),
        );

        $failure = null;
        try {
            iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure, 'An in-place events size change after EOF must invalidate the replay snapshot.');
        self::assertSame('paper_replay_events_changed', $failure->getMessage());
        self::assertNull($reader->currentEventIndex());
        $after = lstat($eventsPath);
        self::assertIsArray($after);
        self::assertSame($before['dev'], $after['dev']);
        self::assertSame($before['ino'], $after['ino']);
        self::assertSame($append ? $before['size'] + 1 : $before['size'] - 1, $after['size']);
    }

    private function reader(PaperReplayClock $clock): PaperReplayReader
    {
        return new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            $clock,
        );
    }

    /** @param list<PaperMarketEvent> $events
     *  @return array{directory: string, manifest: PaperDatasetManifest}
     */
    private function completeDataset(array $events): array
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        foreach ($events as $event) {
            $recorder->append($event);
        }

        return ['directory' => $recorder->datasetDirectory(), 'manifest' => $recorder->complete()];
    }

    /** @param list<PaperMarketEvent> $events
     *  @return array{directory: string, manifest: PaperDatasetManifest}
     */
    private function completeHyperliquidDataset(array $events): array
    {
        $eventIds = array_map(
            static fn (PaperMarketEvent $event): string => $event->eventId,
            $events,
        );
        $datasetId = 'dataset-hyperliquid-history-' . substr(
            hash('sha256', implode('|', $eventIds)),
            0,
            16,
        );
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->hyperliquidManifest($datasetId),
        );
        foreach ($events as $event) {
            $recorder->append($event);
        }

        return ['directory' => $recorder->datasetDirectory(), 'manifest' => $recorder->complete()];
    }

    /**
     * @return array{
     *     list<PaperMarketEvent>,
     *     array{directory: string, manifest: PaperDatasetManifest}
     * }
     */
    private function invalidIntervalDataset(
        #[\SensitiveParameter] mixed $sourceStart,
        string $close,
    ): array {
        $payload = $this->hyperliquidBookPayload($close, 60_000);
        if ($sourceStart === null) {
            unset($payload['source_candle_start']);
        } else {
            $payload['source_candle_start'] = $sourceStart;
        }
        $events = [
            $this->hyperliquidHistoricalEvent(
                'BTCUSDT',
                PaperMarketDataChannel::CANDLE_1M,
                '1',
                $close,
                ['interval' => '1m'],
            ),
            $this->hyperliquidHistoricalEvent(
                'BTCUSDT',
                PaperMarketDataChannel::TOP_OF_BOOK,
                '1',
                $close,
                $payload,
            ),
        ];

        return [$events, $this->tamperedHyperliquidDataset($events)];
    }

    private function invalidHyperliquidBook(
        string $symbol,
        string $close,
    ): PaperMarketEvent {
        $payload = $this->hyperliquidBookPayload($close, 60_000);
        unset($payload['source_candle_start']);

        return $this->hyperliquidHistoricalEvent(
            $symbol,
            PaperMarketDataChannel::TOP_OF_BOOK,
            '1',
            $close,
            $payload,
        );
    }

    /** @param list<PaperMarketEvent> $events */
    private function assertHyperliquidReplayIntervalFailure(
        #[\SensitiveParameter] array $events,
        #[\SensitiveParameter] ?string $forbiddenTraceValue = null,
    ): void {
        $dataset = $this->tamperedHyperliquidDataset($events);
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $yielded = 0;
        $failure = null;

        try {
            foreach ($this->reader(new PaperReplayClock($events[0]->exchangeTimestamp))
                ->read($dataset['directory'], 'hyperliquid.pre-sort-validation') as $_event
            ) {
                ++$yielded;
            }
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }

        self::assertNotNull(
            $failure,
            'Every invalid Hyperliquid interval must fail before replay sorting or yield.',
        );
        self::assertSame('paper_dataset_hyperliquid_model_event_invalid', $failure->getMessage());
        self::assertSame(0, $yielded);
        if ($forbiddenTraceValue !== null) {
            $trace = (string) $failure . "\n" . print_r($failure->getTrace(), true);
            self::assertStringNotContainsString($forbiddenTraceValue, $trace);
        }
    }

    private function manifest(): PaperDatasetManifest
    {
        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: 'dataset-okx-001',
            venue: PaperMarketDataVenue::OKX,
            network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT' => 'BTC-USDT-SWAP', 'ETHUSDT' => 'ETH-USDT-SWAP'],
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

    private function hyperliquidManifest(
        string $datasetId = 'dataset-hyperliquid-history-001',
    ): PaperDatasetManifest
    {
        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: $datasetId,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT' => 'BTC', 'ETHUSDT' => 'ETH'],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
            modelName: 'hl_candle_atr_top_v1',
            modelVersion: '1.0.0',
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
    }

    /** @param array<array-key, mixed> $payload */
    private function event(
        string $symbol,
        PaperMarketDataChannel $channel,
        ?string $sequence,
        string $exchangeTimestamp,
        string $receivedTimestamp,
        array $payload = ['price' => '30000.0'],
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            $symbol,
            $channel,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    private function hyperliquidHistoricalEvent(
        string $symbol,
        PaperMarketDataChannel $channel,
        string $sequence,
        string $exchangeTimestamp,
        array $payload,
    ): PaperMarketEvent {
        $duration = match ($channel) {
            PaperMarketDataChannel::CANDLE_1M => 60_000,
            PaperMarketDataChannel::CANDLE_5M => 300_000,
            PaperMarketDataChannel::CANDLE_15M => 900_000,
            PaperMarketDataChannel::CANDLE_1H => 3_600_000,
            default => null,
        };
        if ($duration !== null) {
            $closeMilliseconds = (int) (new \DateTimeImmutable($exchangeTimestamp))->format('Uv');
            $payload += [
                'start_time' => (string) ($closeMilliseconds - $duration + 1),
                'close_time' => (string) $closeMilliseconds,
                'origin' => 'rest_candle_snapshot',
                'confirmed' => true,
            ];
        }
        ksort($payload, SORT_STRING);

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $symbol,
            $channel,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable($exchangeTimestamp),
            $sequence,
            $payload,
        );
    }

    /**
     * @param list<PaperMarketEvent> $events
     *
     * @return array{directory: string, manifest: PaperDatasetManifest}
     */
    private function tamperedHyperliquidDataset(
        #[\SensitiveParameter] array $events,
    ): array {
        $close = '2024-01-01T00:59:59.999000Z';
        $seed = (string) hexdec(substr(hash(
            'sha256',
            implode('|', array_map(
                static fn (PaperMarketEvent $event): string => $event->eventId,
                $events,
            )),
        ), 0, 7));
        $validCandle = $this->hyperliquidHistoricalEvent(
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1H,
            $seed,
            $close,
            ['interval' => '1h'],
        );
        $validBook = $this->hyperliquidHistoricalEvent(
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $seed,
            $close,
            $this->hyperliquidBookPayload($close, 3_600_000),
        );
        $dataset = $this->completeHyperliquidDataset([$validCandle, $validBook]);
        $contents = implode('', array_map(
            static fn (PaperMarketEvent $event): string => CanonicalJson::encode(
                $event->toArray(),
            ) . "\n",
            $events,
        ));
        self::assertSame(
            strlen($contents),
            file_put_contents($dataset['directory'] . '/events.ndjson', $contents),
        );
        $channels = array_values(array_unique(array_map(
            static fn (PaperMarketEvent $event): string => $event->channel->value,
            $events,
        )));
        sort($channels, SORT_STRING);
        $start = min(array_map(
            static fn (PaperMarketEvent $event): \DateTimeImmutable => $event->exchangeTimestamp,
            $events,
        ));
        $end = max(array_map(
            static fn (PaperMarketEvent $event): \DateTimeImmutable => $event->exchangeTimestamp,
            $events,
        ));
        $manifestPath = $dataset['directory'] . '/manifest.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );
        self::assertIsArray($manifest);
        $manifest = array_replace($manifest, [
            'channels' => $channels,
            'event_count' => \count($events),
            'events_file_sha256' => hash('sha256', $contents),
            'last_event_id' => $events[array_key_last($events)]->eventId,
            'sequence_gaps' => [],
            'start_exchange_timestamp' => $start->format('Y-m-d\TH:i:s.u\Z'),
            'end_exchange_timestamp' => $end->format('Y-m-d\TH:i:s.u\Z'),
        ]);
        $manifestContents = CanonicalJson::encode($manifest) . "\n";
        self::assertSame(
            strlen($manifestContents),
            file_put_contents($manifestPath, $manifestContents),
        );

        return $dataset;
    }

    /** @return array<string, mixed> */
    private function hyperliquidBookPayload(string $close, int $duration): array
    {
        $closeMilliseconds = (int) (new \DateTimeImmutable($close))->format('Uv');

        return [
            'ask_price' => '101',
            'ask_size' => '1',
            'bid_price' => '99',
            'bid_size' => '1',
            'model_name' => 'hl_candle_atr_top_v1',
            'model_version' => '1.0.0',
            'origin' => 'historical_candle_model',
            'source_candle_start' => (string) ($closeMilliseconds - $duration + 1),
            'synthetic' => true,
        ];
    }

    private function checkpoint(
        PaperDatasetManifest $manifest,
        string $consumerId,
        PaperMarketEvent $event,
        int $index,
    ): PaperReplayCheckpoint {
        return new PaperReplayCheckpoint(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            datasetId: $manifest->datasetId,
            consumerId: $consumerId,
            eventId: $event->eventId,
            eventIndex: $index,
            exchangeTimestamp: $event->exchangeTimestamp,
            eventsFileSha256: $manifest->eventsFileSha256 ?? '',
        );
    }

    private function datasetRoot(): string
    {
        return $this->testRoot . '/paper-market-data';
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

final class EventsPathSwapFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function __construct(
        private readonly string $eventsPath,
        private readonly string $replacementPath,
    ) {
    }

    /** @param resource $handle
     *  @return array<string, mixed>|false
     */
    public function stat($handle, string $operation): array|false
    {
        if ($operation === 'paper_replay_events_validation' && !$this->swapped) {
            $this->swapped = true;
            if (!unlink($this->eventsPath) || !symlink($this->replacementPath, $this->eventsPath)) {
                throw new \RuntimeException('Unable to inject events pathname substitution.');
            }
        }

        return parent::stat($handle, $operation);
    }
}

final class EventsInPlaceSizeChangeAfterEofFilesystem extends PaperDatasetRecorderFilesystem
{
    private int $eventsPathStats = 0;

    public function __construct(
        private readonly string $eventsPath,
        private readonly bool $append,
    ) {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($operation === 'paper_replay_events_validation'
            && $path === $this->eventsPath
            && ++$this->eventsPathStats === 2
        ) {
            $statistics = parent::pathStat($path, $operation);
            if ($statistics === false || !isset($statistics['size']) || !\is_int($statistics['size'])) {
                throw new \RuntimeException('Unable to inspect events before in-place size change.');
            }

            $handle = fopen($path, 'r+b');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open events for in-place size change.');
            }
            try {
                $changed = $this->append
                    ? fseek($handle, 0, SEEK_END) === 0 && fwrite($handle, "\n") === 1
                    : ftruncate($handle, $statistics['size'] - 1);
                if (!$changed) {
                    throw new \RuntimeException('Unable to inject in-place events size change.');
                }
            } finally {
                fclose($handle);
            }
        }

        return parent::pathStat($path, $operation);
    }
}

final class DatasetDirectorySwapOnEventsOpenFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function __construct(
        private readonly string $datasetDirectory,
        private readonly string $displacedDirectory,
    ) {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($operation === 'paper_replay_events_validation' && !$this->swapped) {
            $this->swapped = true;
            $this->swapWithPrivateCopy();
        }

        return parent::pathStat($path, $operation);
    }

    private function swapWithPrivateCopy(): void
    {
        if (!rename($this->datasetDirectory, $this->displacedDirectory)
            || !mkdir($this->datasetDirectory, 0700)
        ) {
            throw new \RuntimeException('Unable to inject dataset directory substitution.');
        }
        foreach (['manifest.json', 'events.ndjson'] as $filename) {
            $source = $this->displacedDirectory . '/' . $filename;
            $destination = $this->datasetDirectory . '/' . $filename;
            if (!copy($source, $destination) || !chmod($destination, 0600)) {
                throw new \RuntimeException('Unable to copy substituted dataset fixture.');
            }
        }
    }
}

final class DatasetDirectorySwapAtBoundaryFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function __construct(
        private readonly string $datasetDirectory,
        private readonly string $displacedDirectory,
        private readonly string $boundaryOperation,
    ) {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($operation === $this->boundaryOperation && !$this->swapped) {
            $this->swapped = true;
            if (!rename($this->datasetDirectory, $this->displacedDirectory)
                || !mkdir($this->datasetDirectory, 0700)
            ) {
                throw new \RuntimeException('Unable to inject dataset boundary substitution.');
            }
        }

        return parent::pathStat($path, $operation);
    }
}

final class TemporaryDatasetReplacementOnCheckpointLoadFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $restored = false;

    private bool $swapped = false;
    private int $checkpointLoadPathStats = 0;

    /** @var array<string, mixed>|null */
    private ?array $replacementCheckpointDirectoryStatistics = null;

    public function __construct(
        private readonly string $datasetDirectory,
        private readonly string $displacedDirectory,
        private readonly string $replacementDirectory,
    ) {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        $checkpointDirectory = $this->datasetDirectory . '/checkpoints';
        if ($operation === 'paper_replay_checkpoint_directory'
            && $path === $checkpointDirectory
            && !$this->swapped
        ) {
            $this->swapped = true;
            if (!rename($this->datasetDirectory, $this->displacedDirectory)
                || !rename($this->replacementDirectory, $this->datasetDirectory)
            ) {
                throw new \RuntimeException('Unable to inject temporary dataset replacement during checkpoint load.');
            }
        }

        if ($this->restored && $path === $checkpointDirectory) {
            return $this->replacementCheckpointDirectoryStatistics
                ?? throw new \RuntimeException('Missing replacement checkpoint directory identity.');
        }

        $statistics = parent::pathStat($path, $operation);
        if ($operation === 'paper_replay_checkpoint_directory'
            && $path === $checkpointDirectory
            && \is_array($statistics)
        ) {
            $this->replacementCheckpointDirectoryStatistics = $statistics;
        }

        if ($operation === 'paper_replay_checkpoint_load'
            && str_ends_with($path, '/paper.worker-01.json')
            && ++$this->checkpointLoadPathStats === 2
        ) {
            $this->restoreOriginalDataset();
        }

        return $statistics;
    }

    public function restoreOriginalDataset(): void
    {
        if (!$this->swapped || $this->restored) {
            return;
        }
        if (!rename($this->datasetDirectory, $this->replacementDirectory)
            || !rename($this->displacedDirectory, $this->datasetDirectory)
        ) {
            throw new \RuntimeException('Unable to restore dataset after checkpoint load race.');
        }
        $this->restored = true;
    }
}
