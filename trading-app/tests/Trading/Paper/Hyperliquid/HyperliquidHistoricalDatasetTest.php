<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Dataset\PaperHistoricalDatasetBuilder;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalEventStream;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalRequest;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperDatasetRecorder::class)]
#[CoversClass(PaperDatasetVerifier::class)]
#[CoversClass(PaperHistoricalDatasetBuilder::class)]
#[CoversClass(HyperliquidHistoricalEventStream::class)]
#[CoversClass(PaperReplayReader::class)]
final class HyperliquidHistoricalDatasetTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $path = sys_get_temp_dir() . '/hyperliquid-historical-dataset-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0700, true));
        $resolved = realpath($path);
        self::assertIsString($resolved);
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->testRoot);
        parent::tearDown();
    }

    public function testBuildsCertifiesAndReplaysNetworkSeparatedDatasetsWithoutNetworkAccess(): void
    {
        [$mainnetManifest, $mainnetEvents, $mainnetDirectory] = $this->buildDataset(
            'hyperliquid-history-mainnet',
            PaperMarketDataNetwork::MAINNET,
        );
        [$testnetManifest, $testnetEvents, $testnetDirectory] = $this->buildDataset(
            'hyperliquid-history-testnet',
            PaperMarketDataNetwork::TESTNET,
        );

        foreach ([$mainnetManifest, $testnetManifest] as $manifest) {
            self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
            self::assertSame(
                PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
                $manifest->quality,
            );
            self::assertSame('hl_candle_atr_top_v1', $manifest->modelName);
            self::assertSame('1.0.0', $manifest->modelVersion);
            self::assertNotContains(PaperMarketDataChannel::PUBLIC_TRADE->value, $manifest->channels);
        }
        self::assertSame(PaperMarketDataNetwork::MAINNET, $mainnetManifest->network);
        self::assertSame(PaperMarketDataNetwork::TESTNET, $testnetManifest->network);
        self::assertNotSame($mainnetManifest->datasetId, $testnetManifest->datasetId);
        self::assertNotSame($mainnetManifest->toArray(), $testnetManifest->toArray());
        self::assertNotSame($mainnetDirectory, $testnetDirectory);
        self::assertNotEmpty($mainnetEvents);
        self::assertContains(PaperMarketDataChannel::TOP_OF_BOOK->value, $mainnetManifest->channels);
        foreach ($mainnetEvents as $event) {
            self::assertNotSame(PaperMarketDataChannel::PUBLIC_TRADE, $event->channel);
        }
        self::assertCount(count($mainnetEvents), $testnetEvents);
        foreach ($mainnetEvents as $index => $mainnetEvent) {
            self::assertNotSame($mainnetEvent->eventId, $testnetEvents[$index]->eventId);
            self::assertSame(
                PaperMarketDataNetwork::MAINNET,
                $mainnetEvent->sourceNetwork,
            );
            self::assertSame(
                PaperMarketDataNetwork::TESTNET,
                $testnetEvents[$index]->sourceNetwork,
            );
        }

        $appendManifest = $this->recordingManifest(
            'hyperliquid-history-mainnet-append-guard',
            PaperMarketDataNetwork::MAINNET,
        );
        $recorder = new PaperDatasetRecorder($this->testRoot, $appendManifest);
        $this->expectRuntimeReason('paper_dataset_event_network_mismatch');
        $recorder->append($testnetEvents[0]);
    }

    public function testCorruptStagedPageMakesBuilderPublishIncompleteDataset(): void
    {
        $manifest = $this->recordingManifest(
            'hyperliquid-history-corrupt',
            PaperMarketDataNetwork::MAINNET,
        );
        $recorder = new PaperDatasetRecorder($this->testRoot, $manifest);
        $corrupted = false;
        $source = new HyperliquidHistoricalEventStream(
            new FixtureHyperliquidHistoricalClient(),
            $this->request($manifest->datasetId, $manifest->network),
            $recorder->datasetDirectory(),
            static function (string $boundary) use (&$corrupted, $recorder): void {
                if ($corrupted || !str_starts_with($boundary, 'after_page_save:')) {
                    return;
                }
                $pages = glob(
                    $recorder->datasetDirectory()
                    . '/checkpoints/hyperliquid-acquisition/mainnet/pages/*.ndjson',
                );
                self::assertIsArray($pages);
                self::assertNotEmpty($pages);
                self::assertNotFalse(file_put_contents($pages[0], "{\"corrupted\":true}\n"));
                $corrupted = true;
            },
        );

        $result = (new PaperHistoricalDatasetBuilder())->build($recorder, $source);

        self::assertTrue($corrupted);
        self::assertSame(PaperDatasetState::INCOMPLETE, $result->state);
        self::assertSame(PaperMarketDataQuality::INCOMPLETE, $result->quality);
        $this->expectRuntimeReason('paper_dataset_not_complete');
        (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());
    }

    public function testBaselineRejectsLegacyUnknownAndIncompleteDatasets(): void
    {
        $legacyFixture = dirname(__DIR__, 3) . '/Fixtures/PaperMarketData/complete-dataset';
        $legacyDirectory = $this->testRoot . '/legacy-complete-dataset';
        self::assertTrue(mkdir($legacyDirectory, 0700));
        foreach (['manifest.json', 'events.ndjson'] as $file) {
            self::assertTrue(copy($legacyFixture . '/' . $file, $legacyDirectory . '/' . $file));
            self::assertTrue(chmod($legacyDirectory . '/' . $file, 0600));
        }

        try {
            (new PaperDatasetVerifier())->verifyForBaseline($legacyDirectory);
            self::fail('Legacy network provenance must not certify a baseline.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_network_provenance_uncertifiable', $exception->getMessage());
        }

        $manifest = $this->recordingManifest(
            'hyperliquid-history-incomplete',
            PaperMarketDataNetwork::MAINNET,
        );
        $recorder = new PaperDatasetRecorder($this->testRoot, $manifest);
        $recorder->append($this->validCandle());
        $recorder->markIncomplete();

        $this->expectRuntimeReason('paper_dataset_not_complete');
        (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());
    }

    public function testBaselineRejectsMissingHistoricalCoverage(): void
    {
        $manifest = $this->recordingManifest(
            'hyperliquid-history-missing-coverage',
            historicalCoverage: false,
        );
        $recorder = new PaperDatasetRecorder($this->testRoot, $manifest);
        $recorder->append($this->validCandle());
        $recorder->complete();

        $this->expectRuntimeReason('paper_dataset_hyperliquid_coverage_invalid');
        (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());
    }

    public function testBaselineRejectsManifestWithOnlyBtcOneMinuteCandle(): void
    {
        [, $events, $directory] = $this->buildDataset(
            'hyperliquid-history-partial-grid',
            PaperMarketDataNetwork::MAINNET,
        );
        $events = array_values(array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool => $event->symbol === 'BTCUSDT'
                && $event->channel === PaperMarketDataChannel::CANDLE_1M,
        ));
        $this->replaceDatasetEvents($directory, $events);

        $this->expectRuntimeReason('paper_dataset_hyperliquid_coverage_incomplete');
        (new PaperDatasetVerifier())->verifyForBaseline($directory);
    }

    public function testBaselineRejectsOrphanModelledBook(): void
    {
        [, $events, $directory] = $this->buildDataset(
            'hyperliquid-history-orphan-book',
            PaperMarketDataNetwork::MAINNET,
        );
        $book = array_values(array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool => $event->channel
                === PaperMarketDataChannel::TOP_OF_BOOK,
        ))[0];
        $this->replaceDatasetEvents($directory, [$book]);

        $this->expectRuntimeReason('paper_dataset_hyperliquid_model_event_invalid');
        (new PaperDatasetVerifier())->verifyForBaseline($directory);
    }

    public function testBaselineRejectsEveryCoverageGapBoundaryAndExtra(): void
    {
        $cases = ['missing symbol interval', 'first', 'internal', 'last', 'uniform truncation', 'extra'];
        foreach ($cases as $caseIndex => $case) {
            $request = new HyperliquidHistoricalRequest(
                datasetId: 'hyperliquid-coverage-matrix-' . $caseIndex,
                network: PaperMarketDataNetwork::MAINNET,
                symbols: ['BTCUSDT', 'ETHUSDT'],
                from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
                to: new \DateTimeImmutable('2024-01-01T00:03:00.000000Z'),
                maximumEvents: 100,
                maximumPages: 16,
                maximumResponseBytes: 123_456,
                maximumRetries: 3,
            );
            [, $events, $directory] = $this->buildDatasetFromRequest($request);
            $candles = array_values(array_filter(
                $events,
                static fn (PaperMarketEvent $event): bool => $event->channel
                    !== PaperMarketDataChannel::TOP_OF_BOOK,
            ));
            $selected = match ($case) {
                'missing symbol interval' => array_values(array_filter(
                    $candles,
                    static fn (PaperMarketEvent $event): bool => !(
                        $event->symbol === 'ETHUSDT'
                        && $event->channel === PaperMarketDataChannel::CANDLE_5M
                    ),
                )),
                'first' => $this->withoutCandleStart($candles, 'BTCUSDT', '1m', '1704067200000'),
                'internal' => $this->withoutCandleStart($candles, 'BTCUSDT', '1m', '1704067260000'),
                'last' => $this->withoutCandleStart($candles, 'BTCUSDT', '1m', '1704067320000'),
                'uniform truncation' => array_values(array_filter(
                    $candles,
                    static fn (PaperMarketEvent $event): bool => (
                        $event->payload['start_time'] ?? null
                    ) !== match ($event->payload['interval'] ?? null) {
                        '1m' => '1704067320000',
                        default => '1704067200000',
                    },
                )),
                'extra' => [...$candles, $this->extraOneMinuteCandle()],
            };
            $this->replaceDatasetEvents($directory, $selected);

            try {
                (new PaperDatasetVerifier())->verifyForBaseline($directory);
                self::fail($case . ' must not certify.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'paper_dataset_hyperliquid_coverage_incomplete',
                    $exception->getMessage(),
                    $case,
                );
            }
        }
    }

    public function testBaselineRejectsEveryHistoricalRequestIdentityMismatch(): void
    {
        $mutations = [
            'dataset' => ['dataset_id' => 'different-hyperliquid-dataset'],
            'network' => ['source_network' => PaperMarketDataNetwork::TESTNET->value],
            'symbols' => ['symbols' => ['BTCUSDT' => 'BTC']],
            'from' => ['historical_coverage.from' => '2023-12-31T23:59:00.000000Z'],
            'to' => ['historical_coverage.to' => '2024-01-01T00:02:00.000000Z'],
            'maximum events' => ['historical_coverage.maximum_events' => 101],
            'maximum pages' => ['historical_coverage.maximum_pages' => 17],
            'maximum response bytes' => ['historical_coverage.maximum_response_bytes' => 123_455],
            'maximum retries' => ['historical_coverage.maximum_retries' => 2],
            'request hash' => ['historical_coverage.request_sha256' => str_repeat('f', 64)],
        ];
        foreach ($mutations as $case => $mutation) {
            [, , $directory] = $this->buildDataset(
                'hyperliquid-identity-mismatch-' . substr(hash('sha256', $case), 0, 12),
                PaperMarketDataNetwork::MAINNET,
            );
            $this->rewriteNestedManifest($directory, $mutation);

            try {
                (new PaperDatasetVerifier())->verifyForBaseline($directory);
                self::fail($case . ' must not certify.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'paper_dataset_hyperliquid_coverage_invalid',
                    $exception->getMessage(),
                    $case,
                );
            }
        }
    }

    public function testBaselineRejectsEveryModelledBookCandleMismatch(): void
    {
        $cases = ['symbol', 'network', 'close', 'start', 'interval'];
        foreach ($cases as $case) {
            [, $events, $directory] = $this->buildDataset(
                'hyperliquid-book-mismatch-' . $case,
                PaperMarketDataNetwork::MAINNET,
            );
            $bookIndex = array_find_key(
                $events,
                static fn (PaperMarketEvent $event): bool => $event->channel
                    === PaperMarketDataChannel::TOP_OF_BOOK
                    && $event->symbol === 'BTCUSDT'
                    && ($event->payload['source_candle_start'] ?? null) === '1704067200000'
                    && $event->exchangeTimestamp->format('H:i:s') === '00:59:59',
            );
            self::assertIsInt($bookIndex);
            $book = $events[$bookIndex];
            $payload = $book->payload;
            $symbol = $book->symbol;
            $network = $book->sourceNetwork;
            $timestamp = $book->exchangeTimestamp;
            if ($case === 'symbol') {
                $symbol = 'ETHUSDT';
                $events = array_values(array_filter(
                    $events,
                    static fn (PaperMarketEvent $event): bool => !(
                        $event->symbol === 'ETHUSDT'
                        && ($event->channel === PaperMarketDataChannel::CANDLE_1H
                            || ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK
                                && $event->exchangeTimestamp->format('H:i:s') === '00:59:59'))
                    ),
                ));
                $bookIndex = array_find_key(
                    $events,
                    static fn (PaperMarketEvent $event): bool => $event->eventId === $book->eventId,
                );
                self::assertIsInt($bookIndex);
            } elseif ($case === 'network') {
                $network = PaperMarketDataNetwork::TESTNET;
            } elseif ($case === 'close') {
                $timestamp = $timestamp->modify('+1 millisecond');
            } elseif ($case === 'start') {
                $payload['source_candle_start'] = '1704067200001';
            } elseif ($case === 'interval') {
                $payload['source_candle_start'] = '1704070500000';
            }
            $events[$bookIndex] = PaperMarketEvent::create(
                network: $network,
                venue: $book->sourceVenue,
                symbol: $symbol,
                channel: $book->channel,
                exchangeTimestamp: $timestamp,
                receivedTimestamp: $timestamp,
                sequence: $book->sequence,
                payload: $payload,
            );
            $this->replaceDatasetEvents($directory, array_values($events));

            try {
                (new PaperDatasetVerifier())->verifyForBaseline($directory);
                self::fail($case . ' must not certify.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    $case === 'network'
                        ? 'paper_dataset_event_network_mismatch'
                        : 'paper_dataset_hyperliquid_model_event_invalid',
                    $exception->getMessage(),
                    $case,
                );
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidHyperliquidEventProvider(): iterable
    {
        yield 'public trade' => [
            ['channel' => PaperMarketDataChannel::PUBLIC_TRADE, 'payload' => ['price' => '100']],
            'paper_dataset_hyperliquid_historical_trade_forbidden',
        ];
        yield 'connection state channel' => [
            ['channel' => PaperMarketDataChannel::CONNECTION_STATE, 'payload' => ['connected' => true]],
            'paper_dataset_hyperliquid_channel_invalid',
        ];
        yield 'wrong model name' => [
            ['payload' => self::bookPayload(['model_name' => 'wrong_model'])],
            'paper_dataset_hyperliquid_model_event_invalid',
        ];
        yield 'missing model version' => [
            ['payload' => self::bookPayload(['model_version' => null])],
            'paper_dataset_hyperliquid_model_event_invalid',
        ];
        yield 'current L2 origin' => [
            ['payload' => self::bookPayload(['origin' => 'rest_l2_snapshot'])],
            'paper_dataset_hyperliquid_model_event_invalid',
        ];
        yield 'non-synthetic book' => [
            ['payload' => self::bookPayload(['synthetic' => false])],
            'paper_dataset_hyperliquid_model_event_invalid',
        ];
        yield 'missing source candle start' => [
            ['payload' => self::bookPayload(['source_candle_start' => null])],
            'paper_dataset_hyperliquid_model_event_invalid',
        ];
        yield 'shifted source candle grid' => [
            ['payload' => self::bookPayload(['source_candle_start' => '1704067200001'])],
            'paper_dataset_hyperliquid_model_event_invalid',
        ];
    }

    /**
     * @param array{channel?: PaperMarketDataChannel, payload: array<string, mixed>} $specification
     */
    #[DataProvider('invalidHyperliquidEventProvider')]
    public function testRecorderRejectsInvalidHyperliquidHistoricalEvents(
        array $specification,
        string $reason,
    ): void {
        $recorder = new PaperDatasetRecorder(
            $this->testRoot,
            $this->recordingManifest('hyperliquid-recorder-' . substr(hash('sha256', $reason), 0, 12)),
        );
        $event = $this->event(
            channel: $specification['channel'] ?? PaperMarketDataChannel::TOP_OF_BOOK,
            payload: $specification['payload'],
        );

        $this->expectRuntimeReason($reason);
        $recorder->append($event);
    }

    /**
     * @param array{channel?: PaperMarketDataChannel, payload: array<string, mixed>} $specification
     */
    #[DataProvider('invalidHyperliquidEventProvider')]
    public function testVerifierRejectsInvalidHyperliquidHistoricalEvents(
        array $specification,
        string $reason,
    ): void {
        $recorder = $this->completeSingleEventDataset(
            'hyperliquid-verifier-' . substr(hash('sha256', $reason), 0, 12),
        );
        $event = $this->event(
            channel: $specification['channel'] ?? PaperMarketDataChannel::TOP_OF_BOOK,
            payload: $specification['payload'],
        );
        $this->replaceDatasetEvent($recorder->datasetDirectory(), $event);

        $this->expectRuntimeReason($reason);
        (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());
    }

    public function testCommonRecorderGuardsStillRejectMixedVenueAndNetwork(): void
    {
        $recorder = new PaperDatasetRecorder(
            $this->testRoot,
            $this->recordingManifest('hyperliquid-common-recorder-venue'),
        );
        $this->expectRuntimeReason('paper_dataset_event_venue_mismatch');
        $recorder->append($this->event(venue: PaperMarketDataVenue::OKX));
    }

    /**
     * @return iterable<string, array{PaperMarketDataVenue, PaperMarketDataNetwork, string}>
     */
    public static function mixedProvenanceProvider(): iterable
    {
        yield 'venue' => [
            PaperMarketDataVenue::OKX,
            PaperMarketDataNetwork::MAINNET,
            'paper_dataset_event_venue_mismatch',
        ];
        yield 'network' => [
            PaperMarketDataVenue::HYPERLIQUID,
            PaperMarketDataNetwork::TESTNET,
            'paper_dataset_event_network_mismatch',
        ];
    }

    #[DataProvider('mixedProvenanceProvider')]
    public function testVerifierRetainsCommonVenueAndNetworkSeparation(
        PaperMarketDataVenue $venue,
        PaperMarketDataNetwork $network,
        string $reason,
    ): void {
        $recorder = $this->completeSingleEventDataset(
            'hyperliquid-common-verifier-' . $venue->value . '-' . $network->value,
        );
        $this->replaceDatasetEvent(
            $recorder->datasetDirectory(),
            $this->event(venue: $venue, network: $network),
        );

        $this->expectRuntimeReason($reason);
        (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function invalidManifestModelProvider(): iterable
    {
        yield 'wrong name' => ['wrong_model', '1.0.0'];
        yield 'missing name' => [null, '1.0.0'];
        yield 'wrong version' => ['hl_candle_atr_top_v1', '9.9.9'];
        yield 'missing version' => ['hl_candle_atr_top_v1', null];
    }

    #[DataProvider('invalidManifestModelProvider')]
    public function testBaselineRejectsWrongOrMissingManifestModel(
        ?string $modelName,
        ?string $modelVersion,
    ): void {
        $recorder = $this->completeSingleEventDataset(
            'hyperliquid-manifest-model-' . substr(hash('sha256', (string) $modelName . $modelVersion), 0, 12),
        );
        $this->rewriteManifest($recorder->datasetDirectory(), [
            'model_name' => $modelName,
            'model_version' => $modelVersion,
        ]);

        $this->expectRuntimeReason('paper_dataset_manifest_value_invalid');
        (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());
    }

    /**
     * @return array{PaperDatasetManifest, list<PaperMarketEvent>, string}
     */
    private function buildDataset(string $datasetId, PaperMarketDataNetwork $network): array
    {
        return $this->buildDatasetFromRequest($this->request($datasetId, $network));
    }

    /**
     * @return array{PaperDatasetManifest, list<PaperMarketEvent>, string}
     */
    private function buildDatasetFromRequest(HyperliquidHistoricalRequest $request): array
    {
        $recorder = new PaperDatasetRecorder(
            $this->testRoot,
            $this->recordingManifest($request->datasetId, $request->network, request: $request),
        );
        $source = new HyperliquidHistoricalEventStream(
            new FixtureHyperliquidHistoricalClient(),
            $request,
            $recorder->datasetDirectory(),
        );
        $manifest = (new PaperHistoricalDatasetBuilder())->build($recorder, $source);
        $verified = (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());
        self::assertSame($manifest->toArray(), $verified->toArray());

        $captured = $this->readRawEvents($recorder->datasetDirectory());
        self::assertNotNull($manifest->startExchangeTimestamp);
        $replayed = iterator_to_array((new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($manifest->startExchangeTimestamp),
        ))->read($recorder->datasetDirectory(), 'hyperliquid.dataset-certification'), false);
        self::assertSame(
            array_map(static fn (PaperMarketEvent $event): array => $event->toArray(), $captured),
            array_map(static fn (PaperMarketEvent $event): array => $event->toArray(), $replayed),
        );

        return [$manifest, $captured, $recorder->datasetDirectory()];
    }

    private function request(
        string $datasetId,
        PaperMarketDataNetwork $network,
    ): HyperliquidHistoricalRequest {
        return new HyperliquidHistoricalRequest(
            datasetId: $datasetId,
            network: $network,
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            to: new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
            maximumEvents: 100,
            maximumPages: 16,
            maximumResponseBytes: 123_456,
            maximumRetries: 3,
        );
    }

    private function recordingManifest(
        string $datasetId,
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
        bool $historicalCoverage = true,
        ?HyperliquidHistoricalRequest $request = null,
    ): PaperDatasetManifest {
        $request ??= $this->request($datasetId, $network);

        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: $datasetId,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: $network,
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
            historicalCoverage: $historicalCoverage ? $request->historicalCoverage() : null,
        );
    }

    private function completeSingleEventDataset(string $datasetId): PaperDatasetRecorder
    {
        $recorder = new PaperDatasetRecorder(
            $this->testRoot,
            $this->recordingManifest($datasetId, historicalCoverage: false),
        );
        $recorder->append($this->validCandle());
        $recorder->complete();

        return $recorder;
    }

    private function validCandle(): PaperMarketEvent
    {
        return $this->event(
            channel: PaperMarketDataChannel::CANDLE_1M,
            payload: [
                'close' => '100',
                'interval' => '1m',
                'start_time' => '1704067200000',
                'close_time' => '1704067259999',
                'origin' => 'rest_candle_snapshot',
                'confirmed' => true,
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function event(
        PaperMarketDataChannel $channel = PaperMarketDataChannel::TOP_OF_BOOK,
        ?array $payload = null,
        PaperMarketDataVenue $venue = PaperMarketDataVenue::HYPERLIQUID,
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            network: $network,
            venue: $venue,
            symbol: 'BTCUSDT',
            channel: $channel,
            exchangeTimestamp: new \DateTimeImmutable('2024-01-01T00:00:59.999000Z'),
            receivedTimestamp: new \DateTimeImmutable('2024-01-01T00:00:59.999000Z'),
            sequence: '1',
            payload: $payload ?? self::bookPayload(),
        );
    }

    /**
     * @param array<string, mixed> $replace
     *
     * @return array<string, mixed>
     */
    private static function bookPayload(array $replace = []): array
    {
        $payload = array_replace([
            'bid_price' => '99',
            'bid_size' => '1',
            'ask_price' => '101',
            'ask_size' => '1',
            'model_name' => 'hl_candle_atr_top_v1',
            'model_version' => '1.0.0',
            'origin' => 'historical_candle_model',
            'source_candle_start' => '1704067200000',
            'synthetic' => true,
        ], $replace);
        foreach ($payload as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    private function replaceDatasetEvent(string $directory, PaperMarketEvent $event): void
    {
        $eventsPath = $directory . '/events.ndjson';
        $contents = CanonicalJson::encode($event->toArray()) . "\n";
        self::assertSame(strlen($contents), file_put_contents($eventsPath, $contents));
        $timestamp = $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z');
        $this->rewriteManifest($directory, [
            'channels' => [$event->channel->value],
            'event_count' => 1,
            'events_file_sha256' => hash('sha256', $contents),
            'last_event_id' => $event->eventId,
            'sequence_gaps' => [],
            'start_exchange_timestamp' => $timestamp,
            'end_exchange_timestamp' => $timestamp,
        ]);
    }

    /** @param list<PaperMarketEvent> $events */
    private function replaceDatasetEvents(string $directory, array $events): void
    {
        self::assertNotEmpty($events);
        $contents = implode('', array_map(
            static fn (PaperMarketEvent $event): string => CanonicalJson::encode(
                $event->toArray(),
            ) . "\n",
            $events,
        ));
        self::assertSame(
            strlen($contents),
            file_put_contents($directory . '/events.ndjson', $contents),
        );

        $channels = array_values(array_unique(array_map(
            static fn (PaperMarketEvent $event): string => $event->channel->value,
            $events,
        )));
        sort($channels, SORT_STRING);
        $start = $events[0]->exchangeTimestamp;
        $end = $events[0]->exchangeTimestamp;
        $lastSequences = [];
        $sequenceGaps = [];
        foreach ($events as $event) {
            $start = $event->exchangeTimestamp < $start ? $event->exchangeTimestamp : $start;
            $end = $event->exchangeTimestamp > $end ? $event->exchangeTimestamp : $end;
            if ($event->sequence === null) {
                continue;
            }
            $key = implode('/', [
                $event->sourceNetwork->value,
                $event->sourceVenue->value,
                $event->symbol,
                $event->channel->value,
            ]);
            $sequence = (int) $event->sequence;
            if (isset($lastSequences[$key]) && $sequence > $lastSequences[$key] + 1) {
                $sequenceGaps[$key] = ($sequenceGaps[$key] ?? 0) + 1;
            }
            $lastSequences[$key] = $sequence;
        }
        ksort($sequenceGaps, SORT_STRING);

        $this->rewriteManifest($directory, [
            'channels' => $channels,
            'event_count' => \count($events),
            'events_file_sha256' => hash('sha256', $contents),
            'last_event_id' => $events[array_key_last($events)]->eventId,
            'sequence_gaps' => $sequenceGaps,
            'start_exchange_timestamp' => $start->format('Y-m-d\TH:i:s.u\Z'),
            'end_exchange_timestamp' => $end->format('Y-m-d\TH:i:s.u\Z'),
        ]);
    }

    /**
     * @param list<PaperMarketEvent> $events
     *
     * @return list<PaperMarketEvent>
     */
    private function withoutCandleStart(
        array $events,
        string $symbol,
        string $interval,
        string $start,
    ): array {
        return array_values(array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool => !(
                $event->symbol === $symbol
                && ($event->payload['interval'] ?? null) === $interval
                && ($event->payload['start_time'] ?? null) === $start
            ),
        ));
    }

    private function extraOneMinuteCandle(): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            network: PaperMarketDataNetwork::MAINNET,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::CANDLE_1M,
            exchangeTimestamp: new \DateTimeImmutable('2024-01-01T00:03:59.999000Z'),
            receivedTimestamp: new \DateTimeImmutable('2024-01-01T00:03:59.999000Z'),
            sequence: '999',
            payload: [
                'interval' => '1m',
                'start_time' => '1704067380000',
                'close_time' => '1704067439999',
                'origin' => 'rest_candle_snapshot',
                'confirmed' => true,
                'close' => '100',
            ],
        );
    }

    /** @param array<string, mixed> $replace */
    private function rewriteManifest(string $directory, array $replace): void
    {
        $path = $directory . '/manifest.json';
        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );
        self::assertIsArray($decoded);
        $contents = CanonicalJson::encode(array_replace($decoded, $replace)) . "\n";
        self::assertSame(strlen($contents), file_put_contents($path, $contents));
    }

    /** @param array<string, mixed> $replace */
    private function rewriteNestedManifest(string $directory, array $replace): void
    {
        $path = $directory . '/manifest.json';
        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );
        self::assertIsArray($decoded);
        foreach ($replace as $pathKey => $value) {
            if (str_starts_with($pathKey, 'historical_coverage.')) {
                $coverageKey = substr($pathKey, strlen('historical_coverage.'));
                self::assertIsArray($decoded['historical_coverage']);
                $decoded['historical_coverage'][$coverageKey] = $value;
            } else {
                $decoded[$pathKey] = $value;
            }
        }
        $contents = CanonicalJson::encode($decoded) . "\n";
        self::assertSame(strlen($contents), file_put_contents($path, $contents));
    }

    /** @return list<PaperMarketEvent> */
    private function readRawEvents(string $directory): array
    {
        $lines = file($directory . '/events.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_map(
            static function (string $line): PaperMarketEvent {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                self::assertIsArray($decoded);

                return PaperMarketEvent::fromArray($decoded);
            },
            $lines,
        );
    }

    private function expectRuntimeReason(string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}

final class FixtureHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        unset($maximumResponseBytes, $maximumRetries);
        $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
        $fixture = $this->fixtureRow($coin);
        $rows = [];
        for ($time = $startTime; $time <= $endTime; $time += $step) {
            $rows[] = array_replace($fixture, [
                'T' => $time + $step - 1,
                'i' => $interval,
                's' => $coin,
                't' => $time,
            ]);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function fixtureRow(string $coin): array
    {
        $path = dirname(__DIR__, 3)
            . '/Fixtures/HyperliquidPaperPublic/candles-'
            . strtolower($coin)
            . '-two-pages.json';
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($fixture)
            || !\is_array($fixture['pages'] ?? null)
            || !\is_array($fixture['pages'][0][0] ?? null)
        ) {
            throw new \LogicException('test_candle_fixture_invalid');
        }

        return $fixture['pages'][0][0];
    }
}
