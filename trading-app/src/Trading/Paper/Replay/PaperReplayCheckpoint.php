<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final readonly class PaperReplayCheckpoint
{
    public const SCHEMA_VERSION = 2;
    public const LEGACY_SCHEMA_VERSION = 1;

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const KEYS_V2 = [
        'schema_version',
        'source_network',
        'dataset_id',
        'consumer_id',
        'event_id',
        'event_index',
        'exchange_timestamp',
        'events_file_sha256',
    ];

    /** @var list<string> */
    private const KEYS_V1 = [
        'schema_version',
        'dataset_id',
        'consumer_id',
        'event_id',
        'event_index',
        'exchange_timestamp',
        'events_file_sha256',
    ];

    public int $schemaVersion;
    public PaperMarketDataNetwork $network;
    public string $datasetId;
    public string $consumerId;
    public string $eventId;
    public int $eventIndex;
    public \DateTimeImmutable $exchangeTimestamp;
    public string $eventsFileSha256;

    public function __construct(
        PaperMarketDataNetwork $network,
        string $datasetId,
        string $consumerId,
        string $eventId,
        int $eventIndex,
        #[\SensitiveParameter] \DateTimeImmutable $exchangeTimestamp,
        string $eventsFileSha256,
        int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if (!\in_array($schemaVersion, [self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION], true)
            || (($schemaVersion === self::LEGACY_SCHEMA_VERSION)
                !== ($network === PaperMarketDataNetwork::LEGACY_UNKNOWN))
        ) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_network_provenance_invalid');
        }
        self::assertDatasetId($datasetId);
        self::assertConsumerId($consumerId);
        if (preg_match('/\A[0-9a-f]{64}\z/D', $eventId) !== 1) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_event_id_invalid');
        }
        if ($eventIndex < 0) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_event_index_invalid');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/D', $eventsFileSha256) !== 1) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_checksum_invalid');
        }

        $canonicalTimestamp = $exchangeTimestamp
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::TIMESTAMP_FORMAT);
        $validatedTimestamp = self::parseTimestamp($canonicalTimestamp);

        $this->schemaVersion = $schemaVersion;
        $this->network = $network;
        $this->datasetId = $datasetId;
        $this->consumerId = $consumerId;
        $this->eventId = $eventId;
        $this->eventIndex = $eventIndex;
        $this->exchangeTimestamp = $validatedTimestamp;
        $this->eventsFileSha256 = $eventsFileSha256;
    }

    public static function assertConsumerId(string $consumerId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,127}\z/D', $consumerId) !== 1) {
            throw new \InvalidArgumentException('paper_replay_consumer_id_invalid');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        if (!array_key_exists('schema_version', $data) || !\is_int($data['schema_version'])) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_shape_invalid');
        }
        if (!\in_array($data['schema_version'], [self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION], true)) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_schema_version_unsupported');
        }
        $schemaVersion = $data['schema_version'];
        $actualKeys = array_keys($data);
        $expectedKeys = $schemaVersion === self::SCHEMA_VERSION ? self::KEYS_V2 : self::KEYS_V1;
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys
            || ($schemaVersion === self::SCHEMA_VERSION && !\is_string($data['source_network']))
            || !\is_string($data['dataset_id'])
            || !\is_string($data['consumer_id'])
            || !\is_string($data['event_id'])
            || !\is_int($data['event_index'])
            || !\is_string($data['exchange_timestamp'])
            || !\is_string($data['events_file_sha256'])
        ) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_shape_invalid');
        }
        $network = $schemaVersion === self::LEGACY_SCHEMA_VERSION
            ? PaperMarketDataNetwork::LEGACY_UNKNOWN
            : PaperMarketDataNetwork::tryFrom($data['source_network']);
        if ($network === null || ($schemaVersion === self::SCHEMA_VERSION && !$network->isCertifiable())) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_network_unsupported');
        }

        return new self(
            network: $network,
            datasetId: $data['dataset_id'],
            consumerId: $data['consumer_id'],
            eventId: $data['event_id'],
            eventIndex: $data['event_index'],
            exchangeTimestamp: self::parseTimestamp($data['exchange_timestamp']),
            eventsFileSha256: $data['events_file_sha256'],
            schemaVersion: $schemaVersion,
        );
    }

    /**
     * @return array{
     *   schema_version: int,
     *   source_network?: string,
     *   dataset_id: string,
     *   consumer_id: string,
     *   event_id: string,
     *   event_index: int,
     *   exchange_timestamp: string,
     *   events_file_sha256: string
     * }
     */
    public function toArray(): array
    {
        $data = [
            'schema_version' => $this->schemaVersion,
        ];
        if ($this->schemaVersion === self::SCHEMA_VERSION) {
            $data['source_network'] = $this->network->value;
        }
        $data += [
            'dataset_id' => $this->datasetId,
            'consumer_id' => $this->consumerId,
            'event_id' => $this->eventId,
            'event_index' => $this->eventIndex,
            'exchange_timestamp' => $this->exchangeTimestamp->format(self::TIMESTAMP_FORMAT),
            'events_file_sha256' => $this->eventsFileSha256,
        ];

        return $data;
    }

    private static function assertDatasetId(string $datasetId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,127}\z/D', $datasetId) !== 1) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_dataset_id_invalid');
        }
    }

    private static function parseTimestamp(#[\SensitiveParameter] string $value): \DateTimeImmutable
    {
        try {
            $timestamp = \DateTimeImmutable::createFromFormat(
                '!' . self::TIMESTAMP_FORMAT,
                $value,
                new \DateTimeZone('UTC'),
            );
        } catch (\ValueError) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_timestamp_invalid');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \InvalidArgumentException('paper_replay_checkpoint_timestamp_invalid');
        }

        return $timestamp;
    }
}
