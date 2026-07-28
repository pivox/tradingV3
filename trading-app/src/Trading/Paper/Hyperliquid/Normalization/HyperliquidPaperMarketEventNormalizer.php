<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigDecimal;

final class HyperliquidPaperMarketEventNormalizer
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';
    private const MAX_DECIMAL_LENGTH = 128;

    /** @var list<string> */
    private const MODELLED_BOOK_KEYS = [
        'ask',
        'atr',
        'bid',
        'model_name',
        'model_version',
        'size',
        'spread_bps',
    ];

    private HyperliquidPaperSourceOrdinal $ordinals;

    public function __construct(
        private PaperMarketDataNetwork $network,
        ?HyperliquidPaperSourceOrdinal $ordinals = null,
    ) {
        if ($this->network === PaperMarketDataNetwork::LEGACY_UNKNOWN) {
            throw new \InvalidArgumentException('hyperliquid_paper_network_invalid');
        }

        $this->ordinals = $ordinals ?? new HyperliquidPaperSourceOrdinal();
    }

    public function candle(HyperliquidCandle $candle): PaperMarketEvent
    {
        $timestamp = $this->timestamp($candle->closeTime);
        $payload = [
            'native_symbol' => $candle->coin,
            'interval' => $candle->interval,
            'start_time' => (string) $candle->startTime,
            'close_time' => (string) $candle->closeTime,
            'open' => (string) $candle->open,
            'high' => (string) $candle->high,
            'low' => (string) $candle->low,
            'close' => (string) $candle->close,
            'volume' => (string) $candle->volume,
            'trade_count' => (string) $candle->tradeCount,
            'confirmed' => true,
            'origin' => 'rest_candle_snapshot',
        ];

        return $this->event(
            symbol: (new HyperliquidPaperInstrumentMap())->normalizedSymbol($candle->coin),
            channel: $this->channel($candle->interval),
            exchangeTimestamp: $timestamp,
            naturalIdentity: implode('|', [
                $candle->coin,
                $candle->interval,
                (string) $candle->startTime,
                (string) $candle->closeTime,
            ]),
            payload: $payload,
        );
    }

    /**
     * @param array<array-key, mixed>|null $book
     */
    public function modelledTopOfBook(
        HyperliquidCandle $candle,
        #[\SensitiveParameter]
        ?array $book,
    ): ?PaperMarketEvent {
        if ($book === null) {
            return null;
        }

        $this->assertModelledBook($candle, $book);
        $timestamp = $this->timestamp($candle->closeTime);
        $payload = [
            'bid_price' => $book['bid'],
            'bid_size' => $book['size'],
            'ask_price' => $book['ask'],
            'ask_size' => $book['size'],
            'spread_bps' => $book['spread_bps'],
            'atr' => $book['atr'],
            'model_name' => HyperliquidPrudentBookModel::NAME,
            'model_version' => HyperliquidPrudentBookModel::VERSION,
            'origin' => 'historical_candle_model',
            'source_candle_start' => (string) $candle->startTime,
            'source_candle_close' => (string) $candle->closeTime,
            'source_interval' => $candle->interval,
            'synthetic' => true,
        ];

        return $this->event(
            symbol: (new HyperliquidPaperInstrumentMap())->normalizedSymbol($candle->coin),
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: $timestamp,
            naturalIdentity: implode('|', [
                $candle->coin,
                $candle->interval,
                (string) $candle->startTime,
                (string) $candle->closeTime,
                HyperliquidPrudentBookModel::NAME,
                HyperliquidPrudentBookModel::VERSION,
            ]),
            payload: $payload,
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function event(
        string $symbol,
        PaperMarketDataChannel $channel,
        \DateTimeImmutable $exchangeTimestamp,
        string $naturalIdentity,
        #[\SensitiveParameter]
        array $payload,
    ): PaperMarketEvent {
        $scope = implode('/', [
            $this->network->value,
            PaperMarketDataVenue::HYPERLIQUID->value,
            $symbol,
            $channel->value,
        ]);
        $digest = HyperliquidPaperSourceOrdinal::assignmentDigest(
            $naturalIdentity,
            $exchangeTimestamp,
            $payload,
        );
        $assignment = $this->ordinals->preview($scope, $naturalIdentity, $digest);
        if ($assignment['replayed']) {
            return $assignment['event']
                ?? throw new \LogicException('hyperliquid_paper_source_ordinal_state_invalid');
        }

        $event = PaperMarketEvent::create(
            network: $this->network,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            symbol: $symbol,
            channel: $channel,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $exchangeTimestamp,
            sequence: $assignment['sequence'],
            payload: $payload,
        );
        $this->ordinals->commit($scope, $naturalIdentity, $digest, $event);

        return $event;
    }

    /** @param array<array-key, mixed> $book */
    private function assertModelledBook(HyperliquidCandle $candle, array $book): void
    {
        try {
            $keys = array_keys($book);
            sort($keys, \SORT_STRING);
            if ($keys !== self::MODELLED_BOOK_KEYS
                || $book['model_name'] !== HyperliquidPrudentBookModel::NAME
                || $book['model_version'] !== HyperliquidPrudentBookModel::VERSION
            ) {
                throw new \InvalidArgumentException();
            }

            $bid = $this->decimal($book['bid'], positive: true);
            $ask = $this->decimal($book['ask'], positive: true);
            $this->decimal($book['size'], positive: true);
            $this->decimal($book['spread_bps'], positive: false);
            $this->decimal($book['atr'], positive: false);
            if (!$bid->isLessThan($candle->close)
                || !$ask->isGreaterThan($candle->close)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException('hyperliquid_paper_modelled_book_invalid');
        }
    }

    private function decimal(mixed $value, bool $positive): BigDecimal
    {
        if (!\is_string($value)
            || \strlen($value) > self::MAX_DECIMAL_LENGTH
            || preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        $decimal = BigDecimal::of($value);
        if ((string) $decimal->stripTrailingZeros() !== $value
            || ($positive ? !$decimal->isGreaterThan(0) : $decimal->isLessThan(0))
        ) {
            throw new \InvalidArgumentException();
        }

        return $decimal;
    }

    private function channel(string $interval): PaperMarketDataChannel
    {
        return match ($interval) {
            '1m' => PaperMarketDataChannel::CANDLE_1M,
            '5m' => PaperMarketDataChannel::CANDLE_5M,
            '15m' => PaperMarketDataChannel::CANDLE_15M,
            '1h' => PaperMarketDataChannel::CANDLE_1H,
            default => throw new \InvalidArgumentException('hyperliquid_paper_interval_invalid'),
        };
    }

    private function timestamp(int $milliseconds): \DateTimeImmutable
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('hyperliquid_paper_timestamp_invalid');
        }

        $seconds = intdiv($milliseconds, 1_000);
        $microseconds = ($milliseconds % 1_000) * 1_000;
        $source = (string) $seconds . '.' . str_pad(
            (string) $microseconds,
            6,
            '0',
            \STR_PAD_LEFT,
        );

        try {
            $timestamp = \DateTimeImmutable::createFromFormat(
                '!U.u',
                $source,
                new \DateTimeZone('UTC'),
            );
            $errors = \DateTimeImmutable::getLastErrors();
            if ($timestamp === false
                || ($errors !== false
                    && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            ) {
                throw new \InvalidArgumentException();
            }
            $timestamp = $timestamp->setTimezone(new \DateTimeZone('UTC'));
            if ($timestamp->format('U.u') !== $source) {
                throw new \InvalidArgumentException();
            }

            $serialized = $timestamp->format(self::TIMESTAMP_FORMAT);
            $roundTrip = \DateTimeImmutable::createFromFormat(
                '!' . self::TIMESTAMP_FORMAT,
                $serialized,
                new \DateTimeZone('UTC'),
            );
            $errors = \DateTimeImmutable::getLastErrors();
            if ($roundTrip === false
                || ($errors !== false
                    && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
                || $roundTrip->format(self::TIMESTAMP_FORMAT) !== $serialized
            ) {
                throw new \InvalidArgumentException();
            }

            return $timestamp;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('hyperliquid_paper_timestamp_invalid');
        }
    }
}
