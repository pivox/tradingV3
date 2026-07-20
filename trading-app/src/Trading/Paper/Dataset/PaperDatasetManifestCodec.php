<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;

final class PaperDatasetManifestCodec
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const KEYS = [
        'schema_version',
        'recorder_version',
        'dataset_id',
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
    ];

    public function encode(PaperDatasetManifest $manifest): string
    {
        return CanonicalJson::encode($manifest->toArray()) . "\n";
    }

    public function decode(#[\SensitiveParameter] string $json): PaperDatasetManifest
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \RuntimeException('paper_dataset_manifest_json_invalid');
        }
        if (!\is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('paper_dataset_manifest_shape_invalid');
        }

        $actualKeys = array_keys($data);
        $expectedKeys = self::KEYS;
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \RuntimeException('paper_dataset_manifest_shape_invalid');
        }

        if (!\is_int($data['schema_version'])
            || !\is_string($data['recorder_version'])
            || !\is_string($data['dataset_id'])
            || !\is_string($data['source_venue'])
            || !\is_array($data['symbols']) || array_is_list($data['symbols'])
            || ($data['start_exchange_timestamp'] !== null && !\is_string($data['start_exchange_timestamp']))
            || ($data['end_exchange_timestamp'] !== null && !\is_string($data['end_exchange_timestamp']))
            || !\is_array($data['channels']) || !array_is_list($data['channels'])
            || !\is_int($data['event_count'])
            || !\is_array($data['sequence_gaps'])
            || ($data['sequence_gaps'] !== [] && array_is_list($data['sequence_gaps']))
            || !\is_string($data['quality'])
            || ($data['model_name'] !== null && !\is_string($data['model_name']))
            || ($data['model_version'] !== null && !\is_string($data['model_version']))
            || ($data['events_file_sha256'] !== null && !\is_string($data['events_file_sha256']))
            || !\is_string($data['state'])
            || ($data['last_event_id'] !== null && !\is_string($data['last_event_id']))
        ) {
            throw new \RuntimeException('paper_dataset_manifest_shape_invalid');
        }

        $venue = PaperMarketDataVenue::tryFrom($data['source_venue']);
        $quality = PaperMarketDataQuality::tryFrom($data['quality']);
        $state = PaperDatasetState::tryFrom($data['state']);
        if ($venue === null || $quality === null || $state === null) {
            throw new \RuntimeException('paper_dataset_manifest_value_invalid');
        }

        try {
            /** @var array<string, string> $symbols */
            $symbols = $data['symbols'];
            /** @var list<string> $channels */
            $channels = $data['channels'];
            /** @var array<string, int> $sequenceGaps */
            $sequenceGaps = $data['sequence_gaps'];

            return new PaperDatasetManifest(
                schemaVersion: $data['schema_version'],
                recorderVersion: $data['recorder_version'],
                datasetId: $data['dataset_id'],
                venue: $venue,
                symbols: $symbols,
                startExchangeTimestamp: $this->parseTimestamp($data['start_exchange_timestamp']),
                endExchangeTimestamp: $this->parseTimestamp($data['end_exchange_timestamp']),
                channels: $channels,
                eventCount: $data['event_count'],
                sequenceGaps: $sequenceGaps,
                quality: $quality,
                modelName: $data['model_name'],
                modelVersion: $data['model_version'],
                eventsFileSha256: $data['events_file_sha256'],
                state: $state,
                lastEventId: $data['last_event_id'],
            );
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('paper_dataset_manifest_value_invalid');
        }
    }

    private function parseTimestamp(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            $timestamp = \DateTimeImmutable::createFromFormat(
                '!' . self::TIMESTAMP_FORMAT,
                $value,
                new \DateTimeZone('UTC'),
            );
        } catch (\ValueError) {
            throw new \RuntimeException('paper_dataset_manifest_timestamp_invalid');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \RuntimeException('paper_dataset_manifest_timestamp_invalid');
        }

        return $timestamp;
    }
}
