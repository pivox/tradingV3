<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\PaperPublicLiveManifestFactory;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperPublicLiveManifestFactory::class)]
final class PaperPublicLiveManifestFactoryTest extends TestCase
{
    public function testBuildsCanonicalMainnetIdentityForEachVenue(): void
    {
        $factory = new PaperPublicLiveManifestFactory();

        $okx = $factory->create(PaperMarketDataVenue::OKX, 'baseline-okx-mainnet');
        self::assertSame(PaperDatasetManifest::SCHEMA_VERSION, $okx->schemaVersion);
        self::assertSame('paper-recorder.v2', $okx->recorderVersion);
        self::assertSame(PaperMarketDataNetwork::MAINNET, $okx->network);
        self::assertSame(PaperMarketDataVenue::OKX, $okx->venue);
        self::assertSame([
            'BTCUSDT' => 'BTC-USDT-SWAP',
            'ETHUSDT' => 'ETH-USDT-SWAP',
        ], $okx->symbols);
        self::assertSame(PaperDatasetState::RECORDING, $okx->state);
        self::assertSame(PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES, $okx->quality);
        self::assertSame(0, $okx->eventCount);
        self::assertSame([], $okx->channels);
        self::assertSame([], $okx->sequenceGaps);
        self::assertNull($okx->eventsFileSha256);

        $hyperliquid = $factory->create(
            PaperMarketDataVenue::HYPERLIQUID,
            'baseline-hyperliquid-mainnet',
        );
        self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $hyperliquid->venue);
        self::assertSame([
            'BTCUSDT' => 'BTC',
            'ETHUSDT' => 'ETH',
        ], $hyperliquid->symbols);
        self::assertSame(PaperMarketDataNetwork::MAINNET, $hyperliquid->network);
        self::assertSame(PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES, $hyperliquid->quality);
    }

    #[DataProvider('invalidDatasetIds')]
    public function testRejectsNonCanonicalMainnetDatasetIds(string $datasetId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_public_capture_dataset_id_invalid');

        (new PaperPublicLiveManifestFactory())->create(PaperMarketDataVenue::OKX, $datasetId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDatasetIds(): iterable
    {
        yield 'missing suffix' => ['baseline-okx'];
        yield 'testnet suffix' => ['baseline-okx-testnet'];
        yield 'uppercase' => ['Baseline-okx-mainnet'];
        yield 'path separator' => ['baseline/okx-mainnet'];
        yield 'too short' => ['x-mainnet'];
        yield 'too long' => [str_repeat('a', 121) . '-mainnet'];
    }
}
