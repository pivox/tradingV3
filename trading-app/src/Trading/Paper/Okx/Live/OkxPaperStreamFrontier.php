<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class OkxPaperStreamFrontier
{
    private const MAX_IDENTITY_BYTES = 1_024;
    private const SHA256_PATTERN = '/\A[a-f0-9]{64}\z/D';
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private function __construct(
        public string $sourceIdentity,
        public string $naturalIdentity,
        public string $canonicalDigest,
    ) {
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(#[\SensitiveParameter] array $state): self
    {
        self::assertExactKeys($state, ['source_identity', 'natural_identity', 'canonical_digest']);
        if (!\is_string($state['source_identity'])
            || !\is_string($state['natural_identity'])
            || !\is_string($state['canonical_digest'])
        ) {
            throw self::invalid();
        }
        self::assertIdentity($state['source_identity']);
        self::assertIdentity($state['natural_identity']);
        if (preg_match(self::SHA256_PATTERN, $state['canonical_digest']) !== 1) {
            throw self::invalid();
        }

        return new self(
            $state['source_identity'],
            $state['natural_identity'],
            $state['canonical_digest'],
        );
    }

    public static function fromEvent(#[\SensitiveParameter] PaperMarketEvent $event): self
    {
        if ($event->sourceVenue !== PaperMarketDataVenue::OKX) {
            throw self::invalid();
        }

        $payload = $event->payload;
        $nativeSymbol = self::requiredString($payload, 'native_symbol');
        $expectedSymbol = match ($nativeSymbol) {
            'BTC-USDT-SWAP' => 'BTCUSDT',
            'ETH-USDT-SWAP' => 'ETHUSDT',
            default => throw self::invalid(),
        };
        if ($event->symbol !== $expectedSymbol) {
            throw self::invalid();
        }

        [$sourceIdentity, $canonicalSourceFields] = match ($event->channel) {
            PaperMarketDataChannel::CANDLE_1M,
            PaperMarketDataChannel::CANDLE_5M,
            PaperMarketDataChannel::CANDLE_15M,
            PaperMarketDataChannel::CANDLE_1H => self::candleFields($event),
            PaperMarketDataChannel::PUBLIC_TRADE => self::tradeFields($event),
            PaperMarketDataChannel::TOP_OF_BOOK => self::bookFields($event),
            PaperMarketDataChannel::CONNECTION_STATE => self::connectionFields($event),
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY => self::boundaryFields($event),
        };

        $canonical = [
            'channel' => $event->channel->value,
            'native_symbol' => $nativeSymbol,
            'source_fields' => $canonicalSourceFields,
            'venue' => PaperMarketDataVenue::OKX->value,
        ];
        if (!\in_array($event->channel, [
            PaperMarketDataChannel::CONNECTION_STATE,
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
        ], true)) {
            $canonical['exchange_timestamp'] = $event->exchangeTimestamp->format(self::TIMESTAMP_FORMAT);
        }

        return self::fromArray([
            'source_identity' => $sourceIdentity,
            'natural_identity' => implode('|', [
                PaperMarketDataVenue::OKX->value,
                $nativeSymbol,
                $event->channel->value,
                $sourceIdentity,
            ]),
            'canonical_digest' => hash('sha256', CanonicalJson::encode($canonical)),
        ]);
    }

    /** @return array{source_identity: string, natural_identity: string, canonical_digest: string} */
    public function toArray(): array
    {
        return [
            'source_identity' => $this->sourceIdentity,
            'natural_identity' => $this->naturalIdentity,
            'canonical_digest' => $this->canonicalDigest,
        ];
    }

    /** @return array{string, array<string, mixed>} */
    private static function candleFields(PaperMarketEvent $event): array
    {
        $payload = $event->payload;
        $bar = self::requiredString($payload, 'bar');
        $expectedBar = match ($event->channel) {
            PaperMarketDataChannel::CANDLE_1M => '1m',
            PaperMarketDataChannel::CANDLE_5M => '5m',
            PaperMarketDataChannel::CANDLE_15M => '15m',
            PaperMarketDataChannel::CANDLE_1H => '1h',
            default => throw self::invalid(),
        };
        if ($bar !== $expectedBar || ($payload['confirmed'] ?? null) !== true) {
            throw self::invalid();
        }
        $timestamp = $event->exchangeTimestamp->format('Uv');

        return [
            $bar . '|' . $timestamp,
            [
                'bar' => $bar,
                'close' => self::requiredString($payload, 'close'),
                'confirmed' => true,
                'high' => self::requiredString($payload, 'high'),
                'low' => self::requiredString($payload, 'low'),
                'open' => self::requiredString($payload, 'open'),
                'opening_timestamp_ms' => $timestamp,
                'volume_base' => self::requiredString($payload, 'volume_base'),
                'volume_contracts' => self::requiredString($payload, 'volume_contracts'),
                'volume_quote' => self::requiredString($payload, 'volume_quote'),
            ],
        ];
    }

    /** @return array{string, array<string, mixed>} */
    private static function tradeFields(PaperMarketEvent $event): array
    {
        $payload = $event->payload;
        $tradeId = self::requiredUnsignedString($payload, 'trade_id');

        return [
            $tradeId,
            [
                'exchange_timestamp_ms' => $event->exchangeTimestamp->format('Uv'),
                'price' => self::requiredString($payload, 'price'),
                'size_contracts' => self::requiredString($payload, 'size_contracts'),
                'source' => self::requiredUnsignedString($payload, 'source'),
                'taker_side' => self::requiredString($payload, 'taker_side'),
                'trade_id' => $tradeId,
            ],
        ];
    }

    /** @return array{string, array<string, mixed>} */
    private static function bookFields(PaperMarketEvent $event): array
    {
        $payload = $event->payload;
        $sequence = self::requiredUnsignedString($payload, 'source_seq_id');

        return [
            $sequence,
            [
                'ask_order_count' => self::requiredUnsignedString($payload, 'ask_order_count'),
                'ask_price' => self::requiredString($payload, 'ask_price'),
                'ask_size_contracts' => self::requiredString($payload, 'ask_size_contracts'),
                'bid_order_count' => self::requiredUnsignedString($payload, 'bid_order_count'),
                'bid_price' => self::requiredString($payload, 'bid_price'),
                'bid_size_contracts' => self::requiredString($payload, 'bid_size_contracts'),
                'source_seq_id' => $sequence,
            ],
        ];
    }

    /** @return array{string, array<string, mixed>} */
    private static function connectionFields(PaperMarketEvent $event): array
    {
        $payload = $event->payload;
        $epoch = self::requiredPositiveInteger($payload, 'connection_epoch');
        $state = self::requiredString($payload, 'state');

        return [$epoch . '|' . $state, ['connection_epoch' => $epoch, 'state' => $state]];
    }

    /** @return array{string, array<string, mixed>} */
    private static function boundaryFields(PaperMarketEvent $event): array
    {
        $payload = $event->payload;
        $epoch = self::requiredPositiveInteger($payload, 'source_epoch');
        $sequence = self::requiredUnsignedString($payload, 'source_seq_id');
        $reason = self::requiredString($payload, 'reason');

        return [
            implode('|', [(string) $epoch, $sequence, $reason]),
            ['reason' => $reason, 'source_epoch' => $epoch, 'source_seq_id' => $sequence],
        ];
    }

    /** @param array<array-key, mixed> $payload */
    private static function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value)) {
            throw self::invalid();
        }
        self::assertIdentity($value);

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private static function requiredUnsignedString(array $payload, string $key): string
    {
        $value = self::requiredString($payload, $key);
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw self::invalid();
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private static function requiredPositiveInteger(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!\is_int($value) || $value < 1) {
            throw self::invalid();
        }

        return $value;
    }

    private static function assertIdentity(string $identity): void
    {
        if ($identity === ''
            || \strlen($identity) > self::MAX_IDENTITY_BYTES
            || preg_match('//u', $identity) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $identity) === 1
        ) {
            throw self::invalid();
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $expected
     */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::invalid();
        }
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('okx_paper_live_checkpoint_invalid');
    }
}
