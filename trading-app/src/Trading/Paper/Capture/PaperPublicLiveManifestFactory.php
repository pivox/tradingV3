<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;

final readonly class PaperPublicLiveManifestFactory
{
    public const RECORDER_VERSION = 'paper-recorder.v2';

    public function create(PaperMarketDataVenue $venue, string $datasetId): PaperDatasetManifest
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,119}-mainnet\z/D', $datasetId) !== 1) {
            throw new \InvalidArgumentException('paper_public_capture_dataset_id_invalid');
        }

        $symbols = match ($venue) {
            PaperMarketDataVenue::OKX => [
                'BTCUSDT' => 'BTC-USDT-SWAP',
                'ETHUSDT' => 'ETH-USDT-SWAP',
            ],
            PaperMarketDataVenue::HYPERLIQUID => [
                'BTCUSDT' => 'BTC',
                'ETHUSDT' => 'ETH',
            ],
        };

        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: self::RECORDER_VERSION,
            datasetId: $datasetId,
            venue: $venue,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: $symbols,
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
}
