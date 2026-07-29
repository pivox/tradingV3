<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;

final readonly class PaperDatasetManifest
{
    public const SCHEMA_VERSION = 2;
    public const LEGACY_SCHEMA_VERSION = 1;

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const ALLOWED_SYMBOLS = ['BTCUSDT', 'ETHUSDT'];

    public int $schemaVersion;
    public string $recorderVersion;
    public string $datasetId;
    public PaperMarketDataVenue $venue;
    public PaperMarketDataNetwork $network;

    /** @var array<string, string> */
    public array $symbols;

    public ?\DateTimeImmutable $startExchangeTimestamp;
    public ?\DateTimeImmutable $endExchangeTimestamp;

    /** @var list<string> */
    public array $channels;

    public int $eventCount;

    /** @var array<string, int> */
    public array $sequenceGaps;

    public PaperMarketDataQuality $quality;
    public ?string $modelName;
    public ?string $modelVersion;
    public ?string $eventsFileSha256;
    public PaperDatasetState $state;
    public ?string $lastEventId;
    public ?HyperliquidHistoricalCoverage $historicalCoverage;

    /**
     * @param array<string, string> $symbols
     * @param list<string>          $channels
     * @param array<string, int>    $sequenceGaps
     */
    public function __construct(
        int $schemaVersion,
        string $recorderVersion,
        string $datasetId,
        PaperMarketDataVenue $venue,
        PaperMarketDataNetwork $network,
        array $symbols,
        ?\DateTimeImmutable $startExchangeTimestamp,
        ?\DateTimeImmutable $endExchangeTimestamp,
        array $channels,
        int $eventCount,
        array $sequenceGaps,
        PaperMarketDataQuality $quality,
        ?string $modelName,
        ?string $modelVersion,
        ?string $eventsFileSha256,
        PaperDatasetState $state,
        ?string $lastEventId,
        ?HyperliquidHistoricalCoverage $historicalCoverage = null,
    ) {
        if (!\in_array($schemaVersion, [self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION], true)) {
            throw new \InvalidArgumentException('paper_dataset_schema_version_unsupported');
        }
        if (($schemaVersion === self::LEGACY_SCHEMA_VERSION) !== ($network === PaperMarketDataNetwork::LEGACY_UNKNOWN)) {
            throw new \InvalidArgumentException('paper_dataset_network_provenance_invalid');
        }
        if ($recorderVersion === '' || trim($recorderVersion) !== $recorderVersion) {
            throw new \InvalidArgumentException('paper_dataset_recorder_version_invalid');
        }
        self::assertDatasetId($datasetId);
        if ($eventCount < 0) {
            throw new \InvalidArgumentException('paper_dataset_event_count_invalid');
        }

        $normalizedSymbols = self::normalizeSymbols($symbols);
        $normalizedChannels = self::normalizeChannels($channels);
        $normalizedGaps = self::normalizeSequenceGaps($sequenceGaps);
        self::assertModel($quality, $modelName, $modelVersion);
        self::assertVenueQuality($venue, $quality);
        if ($historicalCoverage !== null
            && !\in_array($quality, [
                PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
                PaperMarketDataQuality::INCOMPLETE,
            ], true)
        ) {
            throw new \InvalidArgumentException('paper_dataset_hyperliquid_coverage_invalid');
        }
        self::assertChecksum($eventsFileSha256, $state);

        if ($state === PaperDatasetState::COMPLETE) {
            if ($endExchangeTimestamp === null) {
                throw new \InvalidArgumentException('paper_dataset_end_timestamp_required');
            }
            if ($quality === PaperMarketDataQuality::INCOMPLETE) {
                throw new \InvalidArgumentException('paper_dataset_complete_quality_invalid');
            }
        }
        if ($startExchangeTimestamp !== null && $endExchangeTimestamp !== null
            && $endExchangeTimestamp < $startExchangeTimestamp
        ) {
            throw new \InvalidArgumentException('paper_dataset_timestamp_range_invalid');
        }
        if ($lastEventId !== null && preg_match('/\A[0-9a-f]{64}\z/D', $lastEventId) !== 1) {
            throw new \InvalidArgumentException('paper_dataset_last_event_id_invalid');
        }

        $this->schemaVersion = $schemaVersion;
        $this->recorderVersion = $recorderVersion;
        $this->datasetId = $datasetId;
        $this->venue = $venue;
        $this->network = $network;
        $this->symbols = $normalizedSymbols;
        $this->startExchangeTimestamp = self::normalizeTimestamp($startExchangeTimestamp);
        $this->endExchangeTimestamp = self::normalizeTimestamp($endExchangeTimestamp);
        $this->channels = $normalizedChannels;
        $this->eventCount = $eventCount;
        $this->sequenceGaps = $normalizedGaps;
        $this->quality = $quality;
        $this->modelName = $modelName;
        $this->modelVersion = $modelVersion;
        $this->eventsFileSha256 = $eventsFileSha256;
        $this->state = $state;
        $this->lastEventId = $lastEventId;
        $this->historicalCoverage = $historicalCoverage;
    }

    public static function assertDatasetId(string $datasetId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,127}\z/D', $datasetId) !== 1) {
            throw new \InvalidArgumentException('paper_dataset_id_invalid');
        }
    }

    /**
     * @param list<string>       $channels
     * @param array<string, int> $sequenceGaps
     */
    public function withRecordingFacts(
        ?\DateTimeImmutable $startExchangeTimestamp,
        array $channels,
        int $eventCount,
        array $sequenceGaps,
        ?string $lastEventId,
    ): self {
        return new self(
            schemaVersion: $this->schemaVersion,
            recorderVersion: $this->recorderVersion,
            datasetId: $this->datasetId,
            venue: $this->venue,
            network: $this->network,
            symbols: $this->symbols,
            startExchangeTimestamp: $startExchangeTimestamp,
            endExchangeTimestamp: null,
            channels: $channels,
            eventCount: $eventCount,
            sequenceGaps: $sequenceGaps,
            quality: $this->quality,
            modelName: $this->modelName,
            modelVersion: $this->modelVersion,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: $lastEventId,
            historicalCoverage: $this->historicalCoverage,
        );
    }

    public function finalized(
        PaperDatasetState $state,
        ?\DateTimeImmutable $endExchangeTimestamp,
        PaperMarketDataQuality $quality,
        string $eventsFileSha256,
    ): self {
        if ($state === PaperDatasetState::RECORDING) {
            throw new \InvalidArgumentException('paper_dataset_final_state_invalid');
        }

        return new self(
            schemaVersion: $this->schemaVersion,
            recorderVersion: $this->recorderVersion,
            datasetId: $this->datasetId,
            venue: $this->venue,
            network: $this->network,
            symbols: $this->symbols,
            startExchangeTimestamp: $this->startExchangeTimestamp,
            endExchangeTimestamp: $endExchangeTimestamp,
            channels: $this->channels,
            eventCount: $this->eventCount,
            sequenceGaps: $this->sequenceGaps,
            quality: $quality,
            modelName: $quality === PaperMarketDataQuality::INCOMPLETE ? null : $this->modelName,
            modelVersion: $quality === PaperMarketDataQuality::INCOMPLETE ? null : $this->modelVersion,
            eventsFileSha256: $eventsFileSha256,
            state: $state,
            lastEventId: $this->lastEventId,
            historicalCoverage: $this->historicalCoverage,
        );
    }

    /**
     * @return array{
     *   schema_version: int,
     *   recorder_version: string,
     *   dataset_id: string,
     *   source_network?: string,
     *   source_venue: string,
     *   symbols: array<string, string>,
     *   start_exchange_timestamp: string|null,
     *   end_exchange_timestamp: string|null,
     *   channels: list<string>,
     *   event_count: int,
     *   sequence_gaps: array<string, int>,
     *   quality: string,
     *   model_name: string|null,
     *   model_version: string|null,
     *   events_file_sha256: string|null,
     *   state: string,
     *   last_event_id: string|null,
     *   historical_coverage?: array{
     *     schema_version: 1,
     *     request_sha256: string,
     *     from: string,
     *     to: string,
     *     intervals: list<string>,
     *     maximum_events: int,
     *     maximum_pages: int,
     *     maximum_response_bytes: int,
     *     maximum_retries: int
     *   }
     * }
     */
    public function toArray(): array
    {
        $data = [
            'schema_version' => $this->schemaVersion,
            'recorder_version' => $this->recorderVersion,
            'dataset_id' => $this->datasetId,
        ];
        if ($this->schemaVersion === self::SCHEMA_VERSION) {
            $data['source_network'] = $this->network->value;
        }
        $data += [
            'source_venue' => $this->venue->value,
            'symbols' => $this->symbols,
            'start_exchange_timestamp' => $this->startExchangeTimestamp?->format(self::TIMESTAMP_FORMAT),
            'end_exchange_timestamp' => $this->endExchangeTimestamp?->format(self::TIMESTAMP_FORMAT),
            'channels' => $this->channels,
            'event_count' => $this->eventCount,
            'sequence_gaps' => $this->sequenceGaps,
            'quality' => $this->quality->value,
            'model_name' => $this->modelName,
            'model_version' => $this->modelVersion,
            'events_file_sha256' => $this->eventsFileSha256,
            'state' => $this->state->value,
            'last_event_id' => $this->lastEventId,
        ];
        if ($this->quality === PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK
            && $this->historicalCoverage !== null
        ) {
            $data['historical_coverage'] = $this->historicalCoverage->toArray();
        }

        return $data;
    }

    public function hasCertifiableNetworkProvenance(): bool
    {
        return $this->schemaVersion === self::SCHEMA_VERSION && $this->network->isCertifiable();
    }

    /**
     * @param array<string, string> $symbols
     *
     * @return array<string, string>
     */
    private static function normalizeSymbols(array $symbols): array
    {
        if ($symbols === []) {
            throw new \InvalidArgumentException('paper_dataset_symbols_invalid');
        }

        foreach ($symbols as $normalized => $native) {
            if (!\is_string($normalized) || !\in_array($normalized, self::ALLOWED_SYMBOLS, true)
                || !\is_string($native) || $native === '' || trim($native) !== $native
            ) {
                throw new \InvalidArgumentException('paper_dataset_symbols_invalid');
            }
        }
        ksort($symbols, SORT_STRING);

        return $symbols;
    }

    /**
     * @param list<string> $channels
     *
     * @return list<string>
     */
    private static function normalizeChannels(array $channels): array
    {
        foreach ($channels as $channel) {
            if (!\is_string($channel) || PaperMarketDataChannel::tryFrom($channel) === null) {
                throw new \InvalidArgumentException('paper_dataset_channels_invalid');
            }
        }
        $channels = array_values(array_unique($channels));
        sort($channels, SORT_STRING);

        return $channels;
    }

    /**
     * @param array<string, int> $sequenceGaps
     *
     * @return array<string, int>
     */
    private static function normalizeSequenceGaps(array $sequenceGaps): array
    {
        foreach ($sequenceGaps as $channel => $count) {
            if (!\is_string($channel) || $channel === '' || !\is_int($count) || $count < 0) {
                throw new \InvalidArgumentException('paper_dataset_sequence_gaps_invalid');
            }
        }
        ksort($sequenceGaps, SORT_STRING);

        return $sequenceGaps;
    }

    private static function assertModel(
        PaperMarketDataQuality $quality,
        ?string $modelName,
        ?string $modelVersion,
    ): void {
        if ($quality === PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK) {
            if ($modelName !== 'hl_candle_atr_top_v1' || $modelVersion !== '1.0.0') {
                throw new \InvalidArgumentException('paper_dataset_hyperliquid_model_invalid');
            }

            return;
        }
        if ($quality !== PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES) {
            return;
        }
        if ($modelName === null || $modelVersion === null
            || $modelName === '' || $modelVersion === ''
            || trim($modelName) !== $modelName || trim($modelVersion) !== $modelVersion
        ) {
            throw new \InvalidArgumentException('paper_dataset_model_required');
        }
    }

    private static function assertVenueQuality(
        PaperMarketDataVenue $venue,
        PaperMarketDataQuality $quality,
    ): void {
        if (($quality === PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK
                && $venue !== PaperMarketDataVenue::HYPERLIQUID)
            || ($quality === PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES
                && $venue !== PaperMarketDataVenue::OKX)
        ) {
            throw new \InvalidArgumentException('paper_dataset_quality_venue_invalid');
        }
    }

    private static function assertChecksum(?string $checksum, PaperDatasetState $state): void
    {
        if ($state === PaperDatasetState::COMPLETE && $checksum === null) {
            throw new \InvalidArgumentException('paper_dataset_checksum_required');
        }
        if ($checksum !== null && preg_match('/\A[0-9a-f]{64}\z/D', $checksum) !== 1) {
            throw new \InvalidArgumentException('paper_dataset_checksum_invalid');
        }
    }

    private static function normalizeTimestamp(?\DateTimeImmutable $timestamp): ?\DateTimeImmutable
    {
        return $timestamp?->setTimezone(new \DateTimeZone('UTC'));
    }
}
