<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final readonly class PaperMarketEvent
{
    public const SCHEMA_VERSION = 2;
    public const LEGACY_SCHEMA_VERSION = 1;

    /** Far above current exchange counters while bounding identity input deterministically. */
    public const MAX_SEQUENCE_DIGITS = 128;

    /** Outer event map plus its ten non-payload values. */
    public const CANONICAL_ENVELOPE_NODES = 11;

    /** Eleven associative keys in the fixed event contract. */
    public const CANONICAL_ENVELOPE_KEYS = 11;

    /** The payload is nested one level below the outer event map. */
    public const CANONICAL_ENVELOPE_DEPTH = 1;

    /**
     * Event key bytes plus fixed schema, hashes and timestamp scalar bytes. Venue, symbol,
     * channel and sequence bytes are added from the normalized event identity.
     */
    public const CANONICAL_ENVELOPE_FIXED_BYTES = 313;

    private const LEGACY_CANONICAL_ENVELOPE_NODES = 10;
    private const LEGACY_CANONICAL_ENVELOPE_KEYS = 10;
    private const LEGACY_CANONICAL_ENVELOPE_FIXED_BYTES = 293;

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const ALLOWED_SYMBOLS = ['BTCUSDT', 'ETHUSDT'];

    /** @var list<string> */
    private const CONTRACT_KEYS_V2 = [
        'schema_version',
        'event_id',
        'source_network',
        'source_venue',
        'symbol',
        'channel',
        'exchange_timestamp',
        'received_timestamp',
        'sequence',
        'payload',
        'payload_hash',
    ];

    /** @var list<string> */
    private const CONTRACT_KEYS_V1 = [
        'schema_version',
        'event_id',
        'source_venue',
        'symbol',
        'channel',
        'exchange_timestamp',
        'received_timestamp',
        'sequence',
        'payload',
        'payload_hash',
    ];

    /**
     * @param array<array-key, mixed> $payload
     */
    private function __construct(
        public int $schemaVersion,
        public string $eventId,
        public PaperMarketDataNetwork $sourceNetwork,
        public PaperMarketDataVenue $sourceVenue,
        public string $symbol,
        public PaperMarketDataChannel $channel,
        public \DateTimeImmutable $exchangeTimestamp,
        public \DateTimeImmutable $receivedTimestamp,
        public ?string $sequence,
        public array $payload,
        public string $payloadHash,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function create(
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        #[\SensitiveParameter]
        string $symbol,
        PaperMarketDataChannel $channel,
        #[\SensitiveParameter]
        \DateTimeImmutable $exchangeTimestamp,
        #[\SensitiveParameter]
        \DateTimeImmutable $receivedTimestamp,
        #[\SensitiveParameter]
        ?string $sequence,
        #[\SensitiveParameter]
        array $payload,
    ): self {
        if ($network === PaperMarketDataNetwork::LEGACY_UNKNOWN) {
            throw new \InvalidArgumentException('paper_market_network_legacy_forbidden');
        }

        $normalizedSymbol = strtoupper($symbol);
        if (!\in_array($normalizedSymbol, self::ALLOWED_SYMBOLS, true)) {
            throw new \InvalidArgumentException('paper_market_symbol_not_allowed');
        }

        self::assertValidSequence($sequence);
        PaperMarketEventRedactor::assertSafe($payload);
        $payload = self::detachPayload($payload);

        $exchangeTimestampUtc = self::normalizeTimestamp($exchangeTimestamp);
        $receivedTimestampUtc = self::normalizeTimestamp($receivedTimestamp);
        self::assertSerializableTimestamp($exchangeTimestampUtc);
        self::assertSerializableTimestamp($receivedTimestampUtc);
        $payloadHash = hash('sha256', CanonicalJson::encodeWithReservedBudget(
            $payload,
            self::CANONICAL_ENVELOPE_NODES,
            self::canonicalEnvelopeBytes(
                self::SCHEMA_VERSION,
                $network,
                $venue,
                $normalizedSymbol,
                $channel,
                $sequence,
            ),
            self::CANONICAL_ENVELOPE_KEYS,
            self::CANONICAL_ENVELOPE_DEPTH,
        ));
        $eventId = self::eventId(
            schemaVersion: self::SCHEMA_VERSION,
            network: $network,
            venue: $venue,
            symbol: $normalizedSymbol,
            channel: $channel,
            exchangeTimestamp: $exchangeTimestampUtc,
            sequence: $sequence,
            payloadHash: $payloadHash,
        );

        return new self(
            schemaVersion: self::SCHEMA_VERSION,
            eventId: $eventId,
            sourceNetwork: $network,
            sourceVenue: $venue,
            symbol: $normalizedSymbol,
            channel: $channel,
            exchangeTimestamp: $exchangeTimestampUtc,
            receivedTimestamp: $receivedTimestampUtc,
            sequence: $sequence,
            payload: $payload,
            payloadHash: $payloadHash,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        if (!isset($data['schema_version']) || !\is_int($data['schema_version'])) {
            throw new \InvalidArgumentException('paper_market_schema_version_unsupported');
        }
        $schemaVersion = $data['schema_version'];
        if (!\in_array($schemaVersion, [self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION], true)) {
            throw new \InvalidArgumentException('paper_market_schema_version_unsupported');
        }
        self::assertContractShape($data, $schemaVersion);

        if (!\is_string($data['event_id'])
            || !\is_string($data['source_venue'])
            || !\is_string($data['symbol'])
            || !\is_string($data['channel'])
            || !\is_string($data['exchange_timestamp'])
            || !\is_string($data['received_timestamp'])
            || !\is_array($data['payload'])
            || !\is_string($data['payload_hash'])
        ) {
            throw new \InvalidArgumentException('paper_market_event_shape_invalid');
        }
        if ($schemaVersion === self::SCHEMA_VERSION && !\is_string($data['source_network'])) {
            throw new \InvalidArgumentException('paper_market_event_shape_invalid');
        }

        if ($data['sequence'] !== null && !\is_string($data['sequence'])) {
            throw new \InvalidArgumentException('paper_market_sequence_invalid');
        }

        $venue = PaperMarketDataVenue::tryFrom($data['source_venue']);
        if ($venue === null) {
            throw new \InvalidArgumentException('paper_market_venue_unsupported');
        }
        $network = $schemaVersion === self::LEGACY_SCHEMA_VERSION
            ? PaperMarketDataNetwork::LEGACY_UNKNOWN
            : PaperMarketDataNetwork::tryFrom($data['source_network']);
        if ($network === null || ($schemaVersion === self::SCHEMA_VERSION && !$network->isCertifiable())) {
            throw new \InvalidArgumentException('paper_market_network_unsupported');
        }

        $channel = PaperMarketDataChannel::tryFrom($data['channel']);
        if ($channel === null) {
            throw new \InvalidArgumentException('paper_market_channel_unsupported');
        }

        if (!\in_array($data['symbol'], self::ALLOWED_SYMBOLS, true)) {
            throw new \InvalidArgumentException('paper_market_symbol_not_allowed');
        }

        self::assertValidSequence($data['sequence']);
        PaperMarketEventRedactor::assertSafe($data['payload']);
        $payload = self::detachPayload($data['payload']);
        $exchangeTimestamp = self::parseTimestamp($data['exchange_timestamp']);
        $receivedTimestamp = self::parseTimestamp($data['received_timestamp']);
        $payloadHash = hash('sha256', CanonicalJson::encodeWithReservedBudget(
            $payload,
            $schemaVersion === self::SCHEMA_VERSION
                ? self::CANONICAL_ENVELOPE_NODES
                : self::LEGACY_CANONICAL_ENVELOPE_NODES,
            self::canonicalEnvelopeBytes(
                $schemaVersion,
                $network,
                $venue,
                $data['symbol'],
                $channel,
                $data['sequence'],
            ),
            $schemaVersion === self::SCHEMA_VERSION
                ? self::CANONICAL_ENVELOPE_KEYS
                : self::LEGACY_CANONICAL_ENVELOPE_KEYS,
            self::CANONICAL_ENVELOPE_DEPTH,
        ));
        if (!hash_equals($payloadHash, $data['payload_hash'])) {
            throw new \InvalidArgumentException('paper_market_payload_hash_mismatch');
        }
        $eventId = self::eventId(
            schemaVersion: $schemaVersion,
            network: $network,
            venue: $venue,
            symbol: $data['symbol'],
            channel: $channel,
            exchangeTimestamp: $exchangeTimestamp,
            sequence: $data['sequence'],
            payloadHash: $payloadHash,
        );
        if (!hash_equals($eventId, $data['event_id'])) {
            throw new \InvalidArgumentException('paper_market_event_id_mismatch');
        }

        return new self(
            schemaVersion: $schemaVersion,
            eventId: $eventId,
            sourceNetwork: $network,
            sourceVenue: $venue,
            symbol: $data['symbol'],
            channel: $channel,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $receivedTimestamp,
            sequence: $data['sequence'],
            payload: $payload,
            payloadHash: $payloadHash,
        );
    }

    /**
     * @return array{
     *     schema_version: int,
     *     event_id: string,
     *     source_network?: string,
     *     source_venue: string,
     *     symbol: string,
     *     channel: string,
     *     exchange_timestamp: string,
     *     received_timestamp: string,
     *     sequence: string|null,
     *     payload: array<array-key, mixed>,
     *     payload_hash: string
     * }
     */
    public function toArray(): array
    {
        $data = [
            'schema_version' => $this->schemaVersion,
            'event_id' => $this->eventId,
        ];
        if ($this->schemaVersion === self::SCHEMA_VERSION) {
            $data['source_network'] = $this->sourceNetwork->value;
        }
        $data += [
            'source_venue' => $this->sourceVenue->value,
            'symbol' => $this->symbol,
            'channel' => $this->channel->value,
            'exchange_timestamp' => $this->exchangeTimestamp->format(self::TIMESTAMP_FORMAT),
            'received_timestamp' => $this->receivedTimestamp->format(self::TIMESTAMP_FORMAT),
            'sequence' => $this->sequence,
            'payload' => $this->payload,
            'payload_hash' => $this->payloadHash,
        ];

        return $data;
    }

    private static function eventId(
        int $schemaVersion,
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        string $symbol,
        PaperMarketDataChannel $channel,
        \DateTimeImmutable $exchangeTimestamp,
        ?string $sequence,
        string $payloadHash,
    ): string {
        $identity = [
            (string) $schemaVersion,
            $symbol,
            $channel->value,
            $exchangeTimestamp->format(self::TIMESTAMP_FORMAT),
            $sequence ?? $payloadHash,
        ];
        array_splice($identity, 1, 0, $schemaVersion === self::SCHEMA_VERSION
            ? [$network->value, $venue->value]
            : [$venue->value]);

        return hash('sha256', implode('|', $identity));
    }

    private static function canonicalEnvelopeBytes(
        int $schemaVersion,
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        string $symbol,
        PaperMarketDataChannel $channel,
        ?string $sequence,
    ): int {
        return ($schemaVersion === self::SCHEMA_VERSION
                ? self::CANONICAL_ENVELOPE_FIXED_BYTES + \strlen($network->value)
                : self::LEGACY_CANONICAL_ENVELOPE_FIXED_BYTES)
            + \strlen($venue->value)
            + \strlen($symbol)
            + \strlen($channel->value)
            + ($sequence === null ? 4 : \strlen($sequence));
    }

    private static function assertValidSequence(
        #[\SensitiveParameter] ?string $sequence,
    ): void
    {
        if ($sequence === null) {
            return;
        }

        if (\strlen($sequence) > self::MAX_SEQUENCE_DIGITS) {
            throw new \InvalidArgumentException('paper_market_sequence_too_large');
        }

        if (preg_match('/\A[0-9]+\z/D', $sequence) !== 1) {
            throw new \InvalidArgumentException('paper_market_sequence_invalid');
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function detachPayload(#[\SensitiveParameter] array $payload): array
    {
        $nodeCount = 0;
        $byteCount = 0;

        return self::detachPayloadWithinBudget($payload, $nodeCount, $byteCount);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function detachPayloadWithinBudget(
        #[\SensitiveParameter]
        array $payload,
        int &$nodeCount,
        int &$byteCount,
    ): array {
        self::consumeDetachNode($nodeCount);
        $detached = [];
        foreach ($payload as $key => $value) {
            if (\is_string($key)) {
                if (\strlen($key) > PaperMarketEventRedactor::MAX_PAYLOAD_KEY_BYTES) {
                    throw new \InvalidArgumentException('paper_market_payload_key_too_large');
                }

                self::consumeDetachBytes($byteCount, \strlen($key));
            }

            if (\is_array($value)) {
                $detached[$key] = self::detachPayloadWithinBudget($value, $nodeCount, $byteCount);

                continue;
            }

            self::consumeDetachNode($nodeCount);
            if (\is_string($value)) {
                if (\strlen($value) > PaperMarketEventRedactor::MAX_PAYLOAD_STRING_BYTES) {
                    throw new \InvalidArgumentException('paper_market_payload_string_too_large');
                }

                self::consumeDetachBytes($byteCount, \strlen($value));
            }

            $detached[$key] = $value;
        }

        return $detached;
    }

    private static function consumeDetachNode(int &$nodeCount): void
    {
        ++$nodeCount;
        if ($nodeCount > PaperMarketEventRedactor::MAX_PAYLOAD_NODES) {
            throw new \InvalidArgumentException('paper_market_payload_nodes_exceeded');
        }
    }

    private static function consumeDetachBytes(int &$byteCount, int $bytes): void
    {
        if (
            $bytes > PaperMarketEventRedactor::MAX_PAYLOAD_BYTES
            || $byteCount > PaperMarketEventRedactor::MAX_PAYLOAD_BYTES - $bytes
        ) {
            throw new \InvalidArgumentException('paper_market_payload_bytes_exceeded');
        }

        $byteCount += $bytes;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertContractShape(
        #[\SensitiveParameter] array $data,
        int $schemaVersion,
    ): void
    {
        $actualKeys = array_keys($data);
        $expectedKeys = $schemaVersion === self::SCHEMA_VERSION
            ? self::CONTRACT_KEYS_V2
            : self::CONTRACT_KEYS_V1;
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException('paper_market_event_shape_invalid');
        }
    }

    private static function parseTimestamp(
        #[\SensitiveParameter] string $value,
    ): \DateTimeImmutable
    {
        try {
            $timestamp = \DateTimeImmutable::createFromFormat(
                '!' . self::TIMESTAMP_FORMAT,
                $value,
                self::utc(),
            );
        } catch (\ValueError) {
            throw new \InvalidArgumentException('paper_market_timestamp_invalid');
        }
        $errors = \DateTimeImmutable::getLastErrors();

        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \InvalidArgumentException('paper_market_timestamp_invalid');
        }

        return $timestamp;
    }

    private static function normalizeTimestamp(
        #[\SensitiveParameter] \DateTimeImmutable $timestamp,
    ): \DateTimeImmutable
    {
        try {
            return \DateTimeImmutable::createFromInterface($timestamp)->setTimezone(self::utc());
        } catch (\Throwable) {
            throw new \InvalidArgumentException('paper_market_timestamp_invalid');
        }
    }

    private static function assertSerializableTimestamp(
        #[\SensitiveParameter] \DateTimeImmutable $timestamp,
    ): void
    {
        self::parseTimestamp($timestamp->format(self::TIMESTAMP_FORMAT));
    }

    private static function utc(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
