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
        $recorder = new PaperDatasetRecorder(
            $this->testRoot,
            $this->recordingManifest($datasetId, $network),
        );
        $source = new HyperliquidHistoricalEventStream(
            new FixtureHyperliquidHistoricalClient(),
            $this->request($datasetId, $network),
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
    ): PaperDatasetManifest {
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
        );
    }

    private function completeSingleEventDataset(string $datasetId): PaperDatasetRecorder
    {
        $recorder = new PaperDatasetRecorder($this->testRoot, $this->recordingManifest($datasetId));
        $recorder->append($this->validCandle());
        $recorder->complete();

        return $recorder;
    }

    private function validCandle(): PaperMarketEvent
    {
        return $this->event(
            channel: PaperMarketDataChannel::CANDLE_1M,
            payload: ['close' => '100', 'origin' => 'rest_candle_snapshot'],
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
