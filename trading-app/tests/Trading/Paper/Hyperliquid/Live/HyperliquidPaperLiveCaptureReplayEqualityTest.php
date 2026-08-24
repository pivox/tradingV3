<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
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
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperDatasetVerifier::class)]
final class HyperliquidPaperLiveCaptureReplayEqualityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $temporary = realpath(sys_get_temp_dir());
        self::assertIsString($temporary);
        $this->root = $temporary . '/hyperliquid-equality-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{PaperMarketDataNetwork}> */
    public static function networks(): iterable
    {
        yield 'mainnet' => [PaperMarketDataNetwork::MAINNET];
        yield 'testnet' => [PaperMarketDataNetwork::TESTNET];
    }

    #[DataProvider('networks')]
    public function testCompleteCaptureExactlyEqualsReplay(
        PaperMarketDataNetwork $network,
    ): void {
        [$directory, $captured] = $this->completeDataset($network);
        $manifest = (new PaperDatasetVerifier())->verifyForBaseline($directory);

        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock(new \DateTimeImmutable('1970-01-01T00:00:00Z')),
        );
        $replayed = [];
        foreach ($reader->read($directory, 'hyperliquid-live-equality') as $event) {
            $replayed[] = $event->toArray();
        }

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(
            PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            $manifest->quality,
        );
        self::assertSame(
            array_map(static function (PaperMarketEvent $event): array {
                $canonicalPayload = json_decode(
                    CanonicalJson::encode($event->payload),
                    true,
                    512,
                    \JSON_THROW_ON_ERROR,
                );
                self::assertIsArray($canonicalPayload);
                $canonical = $event->toArray();
                $canonical['payload'] = $canonicalPayload;

                return $canonical;
            }, $captured),
            $replayed,
        );
    }

    public function testCandleFrontierMapOrderDoesNotInvalidateExactCheckpoint(): void
    {
        [$directory] = $this->completeDataset(
            PaperMarketDataNetwork::MAINNET,
            multipleCandleFrontiers: true,
        );

        self::assertSame(
            PaperDatasetState::COMPLETE,
            (new PaperDatasetVerifier())->verifyForBaseline($directory)->state,
        );
    }

    public function testMissingTerminalCheckpointIsRejectedForLiveBaseline(): void
    {
        [$directory] = $this->completeDataset(PaperMarketDataNetwork::MAINNET);
        unlink($directory . '/checkpoints/hyperliquid-live.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_hyperliquid_live_checkpoint_invalid');
        (new PaperDatasetVerifier())->verifyForBaseline($directory);
    }

    public function testMissingSnapshotBoundaryIsRejectedIndependently(): void
    {
        $this->assertInvalidLiveCompletion(
            fn (): array => $this->completeDataset(
                PaperMarketDataNetwork::MAINNET,
                omitSnapshots: true,
            ),
        );
    }

    public function testSyntheticBookIsRejectedIndependently(): void
    {
        $this->assertInvalidLiveCompletion(
            fn (): array => $this->completeDataset(
                PaperMarketDataNetwork::MAINNET,
                syntheticBook: true,
            ),
        );
    }

    public function testInstrumentMetadataAfterItsSnapshotBoundaryIsRejected(): void
    {
        $this->assertInvalidLiveCompletion(
            fn (): array => $this->completeDataset(
                PaperMarketDataNetwork::MAINNET,
                metadataAfterSnapshots: true,
            ),
        );
    }

    public function testFundingEpochMustMatchTheFollowingSnapshotBoundary(): void
    {
        $this->assertInvalidLiveCompletion(
            fn (): array => $this->completeDataset(
                PaperMarketDataNetwork::MAINNET,
                fundingEpoch: 2,
            ),
        );
    }

    public function testPeriodicFundingWithinTheCurrentEpochCertifies(): void
    {
        [$directory, $events] = $this->completeDataset(
            PaperMarketDataNetwork::MAINNET,
            periodicFunding: true,
        );

        self::assertSame(PaperDatasetState::COMPLETE, (new PaperDatasetVerifier())
            ->verifyForBaseline($directory)->state);
        self::assertCount(4, array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::FUNDING_RATE,
        ));
    }

    public function testPeriodicFundingFromAFutureEpochWithoutItsBoundaryIsRejected(): void
    {
        $this->assertInvalidLiveCompletion(
            fn (): array => $this->completeDataset(
                PaperMarketDataNetwork::MAINNET,
                periodicFunding: true,
                periodicFundingEpoch: 2,
            ),
        );
    }

    public function testContinuityLostCheckpointCannotCertifyCompleteDataset(): void
    {
        [$directory] = $this->completeDataset(PaperMarketDataNetwork::MAINNET);
        $path = $directory . '/checkpoints/hyperliquid-live.json';
        $document = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $document['state']['phase'] = 'streaming';
        $document['state']['continuity'] = false;
        $document['state']['failure_reason'] = 'hyperliquid_public_trade_gap_unrecoverable';
        $document['sha256'] = hash(
            'sha256',
            CanonicalJson::encode($document['state']),
        );
        file_put_contents($path, CanonicalJson::encode($document) . "\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_hyperliquid_live_checkpoint_invalid');
        (new PaperDatasetVerifier())->verifyForBaseline($directory);
    }

    /**
     * @return array{string, list<PaperMarketEvent>}
     */
    private function completeDataset(
        PaperMarketDataNetwork $network,
        bool $omitSnapshots = false,
        bool $syntheticBook = false,
        bool $metadataAfterSnapshots = false,
        int $fundingEpoch = 1,
        bool $periodicFunding = false,
        int $periodicFundingEpoch = 1,
        bool $multipleCandleFrontiers = false,
    ): array
    {
        $datasetId = 'paper-hyperliquid-equality-' . $network->value;
        $manifest = new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: 'test',
            datasetId: $datasetId,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: $network,
            symbols: ['BTCUSDT' => 'BTC', 'ETHUSDT' => 'ETH'],
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
        $recorder = new PaperDatasetRecorder($this->root, $manifest);
        $directory = $recorder->datasetDirectory();
        $store = new HyperliquidPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(
            $datasetId,
            $network,
            HyperliquidPaperLivePolicy::configurationSha256($network),
        );
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = new HyperliquidPaperMarketEventNormalizer(
            $network,
            $ordinals,
            new MockClock('2026-07-29T10:00:00Z'),
        );
        $baseMilliseconds = 1_785_319_200_000;
        $secondNormalizer = new HyperliquidPaperMarketEventNormalizer(
            $network,
            $ordinals,
            new MockClock('2026-07-29T10:00:00.000003Z'),
        );
        $btcFundingNormalizer = new HyperliquidPaperMarketEventNormalizer(
            $network,
            $ordinals,
            new MockClock('2026-07-29T10:00:00.000001Z'),
        );
        $btcSnapshotNormalizer = new HyperliquidPaperMarketEventNormalizer(
            $network,
            $ordinals,
            new MockClock('2026-07-29T10:00:00.000002Z'),
        );
        $ethFundingNormalizer = new HyperliquidPaperMarketEventNormalizer(
            $network,
            $ordinals,
            new MockClock('2026-07-29T10:00:00.000004Z'),
        );
        $ethSnapshotNormalizer = new HyperliquidPaperMarketEventNormalizer(
            $network,
            $ordinals,
            new MockClock('2026-07-29T10:00:00.000005Z'),
        );
        $marketNormalizer = new HyperliquidPaperMarketEventNormalizer(
            $network,
            $ordinals,
            new MockClock('2026-07-29T10:02:00Z'),
        );
        $btcMetadata = $normalizer->instrumentMetadata([
            'coin' => 'BTC', 'asset_id' => 0, 'sz_decimals' => 5, 'max_leverage' => 50,
        ], 1);
        $ethMetadata = $secondNormalizer->instrumentMetadata([
            'coin' => 'ETH', 'asset_id' => 1, 'sz_decimals' => 4, 'max_leverage' => 25,
        ], 1);
        $btcFunding = $btcFundingNormalizer->fundingRate([
            'coin' => 'BTC', 'funding_rate' => '0.0000125',
        ], $fundingEpoch);
        $ethFunding = $ethFundingNormalizer->fundingRate([
            'coin' => 'ETH', 'funding_rate' => '-0.000025',
        ], $fundingEpoch);
        $btcSnapshot = $btcSnapshotNormalizer->snapshotBoundary('BTC', 'initial', 1);
        $ethSnapshot = $ethSnapshotNormalizer->snapshotBoundary('ETH', 'initial', 1);
        $events = $omitSnapshots ? [] : ($metadataAfterSnapshots
            ? [$btcSnapshot, $ethSnapshot, $btcMetadata, $btcFunding, $ethMetadata, $ethFunding]
            : [$btcMetadata, $btcFunding, $btcSnapshot, $ethMetadata, $ethFunding, $ethSnapshot]);
        $events[] = $marketNormalizer->liveTrade([
                'coin' => 'BTC',
                'side' => 'B',
                'px' => '65000',
                'sz' => '0.01',
                'hash' => '0xabc',
                'time' => $baseMilliseconds + 1_000,
                'tid' => 42,
                'users' => ['0xa', '0xb'],
            ]);
        $events[] = $syntheticBook
            ? PaperMarketEvent::create(
                network: $network,
                venue: PaperMarketDataVenue::HYPERLIQUID,
                symbol: 'BTCUSDT',
                channel: \App\Trading\Paper\MarketData\PaperMarketDataChannel::TOP_OF_BOOK,
                exchangeTimestamp: new \DateTimeImmutable('2026-07-29T10:00:02Z'),
                receivedTimestamp: new \DateTimeImmutable('2026-07-29T10:00:02Z'),
                sequence: '1',
                payload: [
                    'bid_price' => '64999',
                    'bid_size' => '1',
                    'ask_price' => '65001',
                    'ask_size' => '1',
                    'model_name' => 'hl_candle_atr_top_v1',
                    'model_version' => '1.0.0',
                    'origin' => 'historical_candle_model',
                    'source_candle_start' => (string) $baseMilliseconds,
                    'synthetic' => true,
                ],
            )
            : $marketNormalizer->liveTopOfBook([
                'coin' => 'BTC',
                'levels' => [
                    [['px' => '64999', 'sz' => '1', 'n' => 1]],
                    [['px' => '65001', 'sz' => '1', 'n' => 1]],
                ],
                'time' => $baseMilliseconds + 2_000,
            ], 1);
        $events[] = $marketNormalizer->closedLiveCandle(HyperliquidCandle::fromApiRow([
                'T' => $baseMilliseconds + 59_999,
                'c' => '2',
                'h' => '3',
                'i' => '1m',
                'l' => '0.5',
                'n' => 5,
                'o' => '1',
                's' => 'BTC',
                't' => $baseMilliseconds,
                'v' => '4',
            ], 'BTC', '1m'));
        if ($multipleCandleFrontiers) {
            $multipleCandleNormalizer = new HyperliquidPaperMarketEventNormalizer(
                $network,
                $ordinals,
                new MockClock('2026-07-29T10:20:00Z'),
            );
            foreach ([
                ['interval' => '5m', 'duration_ms' => 300_000],
                ['interval' => '15m', 'duration_ms' => 900_000],
            ] as $candle) {
                $events[] = $multipleCandleNormalizer->closedLiveCandle(HyperliquidCandle::fromApiRow([
                    'T' => $baseMilliseconds + $candle['duration_ms'] - 1,
                    'c' => '2',
                    'h' => '3',
                    'i' => $candle['interval'],
                    'l' => '0.5',
                    'n' => 5,
                    'o' => '1',
                    's' => 'BTC',
                    't' => $baseMilliseconds,
                    'v' => '4',
                ], 'BTC', $candle['interval']));
            }
        }
        if ($periodicFunding) {
            $refreshNormalizer = new HyperliquidPaperMarketEventNormalizer(
                $network,
                $ordinals,
                new MockClock('2026-07-29T10:50:00Z'),
            );
            $events[] = $refreshNormalizer->fundingRate([
                'coin' => 'BTC', 'funding_rate' => '0.000015',
            ], $periodicFundingEpoch);
            $events[] = $refreshNormalizer->fundingRate([
                'coin' => 'ETH', 'funding_rate' => '-0.00002',
            ], $periodicFundingEpoch);
        }
        foreach ($events as $event) {
            $recorder->append($event);
        }
        $checkpoint = $checkpoint->withOrdinalState($ordinals->snapshot());
        foreach ($events as $event) {
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE) {
                $naturalIdentity = implode('|', [
                    $network->value,
                    $event->payload['native_symbol'],
                    $event->payload['block_time'],
                    $event->payload['trade_id'],
                ]);
                $checkpoint = $checkpoint->rememberTradeIdentity(
                    hash('sha256', $naturalIdentity),
                    HyperliquidPaperSourceOrdinal::assignmentDigest(
                        $naturalIdentity,
                        $event->exchangeTimestamp,
                        $event->payload,
                    ),
                );
            }
            $checkpoint = $checkpoint
                ->withPending($event, ['kind' => 'certification'])
                ->acknowledge($event->eventId);
        }
        $checkpoint = $checkpoint->finalizeCandle('BTC/1m', $baseMilliseconds);
        if ($multipleCandleFrontiers) {
            $checkpoint = $checkpoint
                ->finalizeCandle('BTC/5m', $baseMilliseconds)
                ->finalizeCandle('BTC/15m', $baseMilliseconds);
        }
        $checkpoint = $checkpoint
            ->withPhase('streaming')
            ->requestHealthyStop()
            ->completeHealthyStop();
        $store->save($checkpoint);
        $recorder->complete();

        return [$directory, $events];
    }

    /** @param callable(): array{string, list<PaperMarketEvent>} $capture */
    private function assertInvalidLiveCompletion(callable $capture): void
    {
        try {
            $capture();
            self::fail('Expected independently invalid live dataset.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_complete_failed', $exception->getMessage());
            self::assertSame(
                'paper_dataset_hyperliquid_live_event_invalid',
                $exception->getPrevious()?->getMessage(),
            );
        }
    }

}
