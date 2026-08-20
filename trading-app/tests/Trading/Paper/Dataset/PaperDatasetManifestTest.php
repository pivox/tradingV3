<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Trading\Paper\Dataset\HyperliquidHistoricalCoverage;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperDatasetManifest::class)]
#[CoversClass(PaperDatasetManifestCodec::class)]
final class PaperDatasetManifestTest extends TestCase
{
    public function testRoundTripsCompleteHyperliquidModelledBookManifest(): void
    {
        $coverage = new HyperliquidHistoricalCoverage(
            schemaVersion: 1,
            requestSha256: str_repeat('b', 64),
            from: '2026-07-28T10:00:00.000000Z',
            to: '2026-07-28T11:00:00.000000Z',
            intervals: ['1m', '5m', '15m', '1h'],
            maximumEvents: 1_000_000,
            maximumPages: 100_000,
            maximumResponseBytes: 1_048_576,
            maximumRetries: 5,
        );
        $manifest = self::completeManifest(
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: PaperMarketDataNetwork::MAINNET,
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
            modelName: 'hl_candle_atr_top_v1',
            modelVersion: '1.0.0',
            historicalCoverage: $coverage,
        );

        self::assertSame('public_historical_candles_modelled_book', $manifest->quality->value);
        self::assertSame([
            'schema_version' => 1,
            'request_sha256' => str_repeat('b', 64),
            'from' => '2026-07-28T10:00:00.000000Z',
            'to' => '2026-07-28T11:00:00.000000Z',
            'intervals' => ['1m', '5m', '15m', '1h'],
            'maximum_events' => 1_000_000,
            'maximum_pages' => 100_000,
            'maximum_response_bytes' => 1_048_576,
            'maximum_retries' => 5,
        ], $manifest->toArray()['historical_coverage']);

        $roundTripped = (new PaperDatasetManifestCodec())->decode(
            (new PaperDatasetManifestCodec())->encode($manifest),
        );

        self::assertSame($manifest->toArray(), $roundTripped->toArray());
        self::assertSame(
            $coverage->toArray(),
            $manifest->withRecordingFacts(null, [], 0, [], null)
                ->finalized(
                    PaperDatasetState::COMPLETE,
                    new \DateTimeImmutable('2026-07-28T10:01:00.000000Z'),
                    PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
                    str_repeat('c', 64),
                )
                ->historicalCoverage?->toArray(),
        );
    }

    public function testExistingNonHyperliquidManifestShapeRemainsIdentical(): void
    {
        $manifest = self::completeManifest(
            venue: PaperMarketDataVenue::OKX,
            network: PaperMarketDataNetwork::MAINNET,
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
            modelName: 'okx_historical_model',
            modelVersion: '1.0.0',
        );

        self::assertSame([
            'schema_version',
            'recorder_version',
            'dataset_id',
            'source_network',
            'source_venue',
            'symbols',
            'start_exchange_timestamp',
            'end_exchange_timestamp',
            'channels',
            'event_count',
            'sequence_gaps',
            'quality',
            'model_name',
            'model_version',
            'events_file_sha256',
            'state',
            'last_event_id',
        ], array_keys($manifest->toArray()));
    }

    /** @param array<string, mixed> $coverage */
    #[DataProvider('invalidHistoricalCoverageProvider')]
    public function testRejectsInvalidHistoricalCoverage(array $coverage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_dataset_hyperliquid_coverage_invalid');

        new HyperliquidHistoricalCoverage(
            schemaVersion: $coverage['schema_version'],
            requestSha256: $coverage['request_sha256'],
            from: $coverage['from'],
            to: $coverage['to'],
            intervals: $coverage['intervals'],
            maximumEvents: $coverage['maximum_events'],
            maximumPages: $coverage['maximum_pages'],
            maximumResponseBytes: $coverage['maximum_response_bytes'],
            maximumRetries: $coverage['maximum_retries'],
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidHistoricalCoverageProvider(): iterable
    {
        $valid = [
            'schema_version' => 1,
            'request_sha256' => str_repeat('a', 64),
            'from' => '2026-07-28T10:00:00.000000Z',
            'to' => '2026-07-28T11:00:00.000000Z',
            'intervals' => ['1m', '5m', '15m', '1h'],
            'maximum_events' => 1_000_000,
            'maximum_pages' => 100_000,
            'maximum_response_bytes' => 1_048_576,
            'maximum_retries' => 5,
        ];

        yield 'schema' => [array_replace($valid, ['schema_version' => 2])];
        yield 'uppercase hash' => [array_replace($valid, ['request_sha256' => str_repeat('A', 64)])];
        yield 'non exact UTC timestamp' => [array_replace($valid, ['from' => '2026-07-28T10:00:00Z'])];
        yield 'empty range' => [array_replace($valid, ['to' => $valid['from']])];
        yield 'interval order' => [array_replace($valid, ['intervals' => ['5m', '1m', '15m', '1h']])];
        yield 'events bound' => [array_replace($valid, ['maximum_events' => 0])];
        yield 'pages bound' => [array_replace($valid, ['maximum_pages' => 100_001])];
        yield 'response bytes bound' => [array_replace($valid, ['maximum_response_bytes' => 1_048_577])];
        yield 'retries bound' => [array_replace($valid, ['maximum_retries' => -1])];
    }

    /**
     * @param array{string|null, string|null} $model
     */
    #[DataProvider('invalidHyperliquidModelProvider')]
    public function testRejectsInvalidHyperliquidModelDeclaration(array $model): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_dataset_hyperliquid_model_invalid');

        self::completeManifest(
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: PaperMarketDataNetwork::MAINNET,
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
            modelName: $model[0],
            modelVersion: $model[1],
        );
    }

    /** @return iterable<string, array{array{string|null, string|null}}> */
    public static function invalidHyperliquidModelProvider(): iterable
    {
        yield 'missing model' => [[null, null]];
        yield 'wrong model name' => [['another_model', '1.0.0']];
        yield 'wrong model version' => [['hl_candle_atr_top_v1', '2.0.0']];
    }

    #[DataProvider('invalidHistoricalVenueProvider')]
    public function testRejectsHistoricalQualityForWrongVenue(
        PaperMarketDataVenue $venue,
        PaperMarketDataQuality $quality,
        ?string $modelName,
        ?string $modelVersion,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_dataset_quality_venue_invalid');

        self::completeManifest(
            venue: $venue,
            network: PaperMarketDataNetwork::MAINNET,
            quality: $quality,
            modelName: $modelName,
            modelVersion: $modelVersion,
        );
    }

    /**
     * @return iterable<string, array{
     *   PaperMarketDataVenue,
     *   PaperMarketDataQuality,
     *   string|null,
     *   string|null
     * }>
     */
    public static function invalidHistoricalVenueProvider(): iterable
    {
        yield 'modelled book on OKX' => [
            PaperMarketDataVenue::OKX,
            PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
            'hl_candle_atr_top_v1',
            '1.0.0',
        ];
        yield 'candles and trades on Hyperliquid' => [
            PaperMarketDataVenue::HYPERLIQUID,
            PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
            'okx_historical_model',
            '1.0.0',
        ];
    }

    private static function completeManifest(
        PaperMarketDataVenue $venue,
        PaperMarketDataNetwork $network,
        PaperMarketDataQuality $quality,
        ?string $modelName,
        ?string $modelVersion,
        ?HyperliquidHistoricalCoverage $historicalCoverage = null,
    ): PaperDatasetManifest {
        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: 'hyperliquid-history-001',
            venue: $venue,
            network: $network,
            symbols: ['BTCUSDT' => 'BTC'],
            startExchangeTimestamp: new \DateTimeImmutable('2026-07-28T10:00:00.000000Z'),
            endExchangeTimestamp: new \DateTimeImmutable('2026-07-28T10:01:00.000000Z'),
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: $quality,
            modelName: $modelName,
            modelVersion: $modelVersion,
            eventsFileSha256: str_repeat('a', 64),
            state: PaperDatasetState::COMPLETE,
            lastEventId: null,
            historicalCoverage: $historicalCoverage,
        );
    }
}
