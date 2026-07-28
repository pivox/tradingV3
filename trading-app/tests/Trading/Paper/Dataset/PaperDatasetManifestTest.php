<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

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
        $manifest = self::completeManifest(
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: PaperMarketDataNetwork::MAINNET,
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
            modelName: 'hl_candle_atr_top_v1',
            modelVersion: '1.0.0',
        );

        self::assertSame('public_historical_candles_modelled_book', $manifest->quality->value);

        $roundTripped = (new PaperDatasetManifestCodec())->decode(
            (new PaperDatasetManifestCodec())->encode($manifest),
        );

        self::assertSame($manifest->toArray(), $roundTripped->toArray());
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
        );
    }
}
