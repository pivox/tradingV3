<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final readonly class PaperMarketEvent
{
    public const SCHEMA_VERSION = 1;

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const ALLOWED_SYMBOLS = ['BTCUSDT', 'ETHUSDT'];

    /** @var list<string> */
    private const CONTRACT_KEYS = [
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
        PaperMarketDataVenue $venue,
        string $symbol,
        PaperMarketDataChannel $channel,
        \DateTimeImmutable $exchangeTimestamp,
        \DateTimeImmutable $receivedTimestamp,
        ?string $sequence,
        array $payload,
    ): self {
        $normalizedSymbol = strtoupper($symbol);
        if (!\in_array($normalizedSymbol, self::ALLOWED_SYMBOLS, true)) {
            throw new \InvalidArgumentException('paper_market_symbol_not_allowed');
        }

        self::assertValidSequence($sequence);
        PaperMarketEventRedactor::assertSafe($payload);
        $payload = self::detachPayload($payload);

        $exchangeTimestampUtc = $exchangeTimestamp->setTimezone(self::utc());
        $receivedTimestampUtc = $receivedTimestamp->setTimezone(self::utc());
        self::assertSerializableTimestamp($exchangeTimestampUtc);
        self::assertSerializableTimestamp($receivedTimestampUtc);
        $payloadHash = hash('sha256', CanonicalJson::encode($payload));
        $eventId = self::eventId(
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
    public static function fromArray(array $data): self
    {
        self::assertContractShape($data);

        if ($data['schema_version'] !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('paper_market_schema_version_unsupported');
        }

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

        if ($data['sequence'] !== null && !\is_string($data['sequence'])) {
            throw new \InvalidArgumentException('paper_market_sequence_invalid');
        }

        $venue = PaperMarketDataVenue::tryFrom($data['source_venue']);
        if ($venue === null) {
            throw new \InvalidArgumentException('paper_market_venue_unsupported');
        }

        $channel = PaperMarketDataChannel::tryFrom($data['channel']);
        if ($channel === null) {
            throw new \InvalidArgumentException('paper_market_channel_unsupported');
        }

        if (!\in_array($data['symbol'], self::ALLOWED_SYMBOLS, true)) {
            throw new \InvalidArgumentException('paper_market_symbol_not_allowed');
        }

        $event = self::create(
            venue: $venue,
            symbol: $data['symbol'],
            channel: $channel,
            exchangeTimestamp: self::parseTimestamp($data['exchange_timestamp']),
            receivedTimestamp: self::parseTimestamp($data['received_timestamp']),
            sequence: $data['sequence'],
            payload: $data['payload'],
        );

        if (!hash_equals($event->payloadHash, $data['payload_hash'])) {
            throw new \InvalidArgumentException('paper_market_payload_hash_mismatch');
        }

        if (!hash_equals($event->eventId, $data['event_id'])) {
            throw new \InvalidArgumentException('paper_market_event_id_mismatch');
        }

        return $event;
    }

    /**
     * @return array{
     *     schema_version: int,
     *     event_id: string,
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
        return [
            'schema_version' => $this->schemaVersion,
            'event_id' => $this->eventId,
            'source_venue' => $this->sourceVenue->value,
            'symbol' => $this->symbol,
            'channel' => $this->channel->value,
            'exchange_timestamp' => $this->exchangeTimestamp->format(self::TIMESTAMP_FORMAT),
            'received_timestamp' => $this->receivedTimestamp->format(self::TIMESTAMP_FORMAT),
            'sequence' => $this->sequence,
            'payload' => $this->payload,
            'payload_hash' => $this->payloadHash,
        ];
    }

    private static function eventId(
        PaperMarketDataVenue $venue,
        string $symbol,
        PaperMarketDataChannel $channel,
        \DateTimeImmutable $exchangeTimestamp,
        ?string $sequence,
        string $payloadHash,
    ): string {
        return hash('sha256', implode('|', [
            (string) self::SCHEMA_VERSION,
            $venue->value,
            $symbol,
            $channel->value,
            $exchangeTimestamp->format(self::TIMESTAMP_FORMAT),
            $sequence ?? $payloadHash,
        ]));
    }

    private static function assertValidSequence(?string $sequence): void
    {
        if ($sequence !== null && !ctype_digit($sequence)) {
            throw new \InvalidArgumentException('paper_market_sequence_invalid');
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function detachPayload(array $payload): array
    {
        $detached = [];
        foreach ($payload as $key => $value) {
            $detached[$key] = \is_array($value) ? self::detachPayload($value) : $value;
        }

        return $detached;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertContractShape(array $data): void
    {
        $actualKeys = array_keys($data);
        $expectedKeys = self::CONTRACT_KEYS;
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException('paper_market_event_shape_invalid');
        }
    }

    private static function parseTimestamp(string $value): \DateTimeImmutable
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!' . self::TIMESTAMP_FORMAT,
            $value,
            self::utc(),
        );
        $errors = \DateTimeImmutable::getLastErrors();

        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \InvalidArgumentException('paper_market_timestamp_invalid');
        }

        return $timestamp;
    }

    private static function assertSerializableTimestamp(\DateTimeImmutable $timestamp): void
    {
        self::parseTimestamp($timestamp->format(self::TIMESTAMP_FORMAT));
    }

    private static function utc(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
