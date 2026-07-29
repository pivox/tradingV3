<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigDecimal;
use Symfony\Component\Clock\ClockInterface;

final class HyperliquidPaperMarketEventNormalizer
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private HyperliquidPaperSourceOrdinal $ordinals;

    public function __construct(
        private PaperMarketDataNetwork $network,
        ?HyperliquidPaperSourceOrdinal $ordinals = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        if ($this->network === PaperMarketDataNetwork::LEGACY_UNKNOWN) {
            throw new \InvalidArgumentException('hyperliquid_paper_network_invalid');
        }

        $this->ordinals = $ordinals ?? new HyperliquidPaperSourceOrdinal();
    }

    public function candle(HyperliquidCandle $candle): PaperMarketEvent
    {
        return $this->normalizedCandle(
            $candle,
            origin: 'rest_candle_snapshot',
            naturalIdentity: implode('|', [
                $candle->coin,
                $candle->interval,
                (string) $candle->startTime,
                (string) $candle->closeTime,
            ]),
            receivedTimestamp: null,
        );
    }

    public function closedLiveCandle(HyperliquidCandle $candle): PaperMarketEvent
    {
        return $this->normalizedCandle(
            $candle,
            origin: 'ws_candle',
            naturalIdentity: implode('|', [
                $this->network->value,
                $candle->coin,
                $candle->interval,
                (string) $candle->startTime,
                (string) $candle->closeTime,
            ]),
            receivedTimestamp: $this->receiptTimestamp(),
        );
    }

    private function normalizedCandle(
        HyperliquidCandle $candle,
        string $origin,
        string $naturalIdentity,
        ?\DateTimeImmutable $receivedTimestamp,
    ): PaperMarketEvent
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
            'origin' => $origin,
        ];

        return $this->event(
            symbol: (new HyperliquidPaperInstrumentMap())->normalizedSymbol($candle->coin),
            channel: $this->channel($candle->interval),
            exchangeTimestamp: $timestamp,
            naturalIdentity: $naturalIdentity,
            payload: $payload,
            receivedTimestamp: $receivedTimestamp,
        );
    }

    /** @param array<array-key, mixed> $row */
    public function liveTrade(#[\SensitiveParameter] array $row): PaperMarketEvent
    {
        [$symbol, $exchangeTimestamp, $naturalIdentity, $payload]
            = $this->liveTradeData($row);

        return $this->event(
            symbol: $symbol,
            channel: PaperMarketDataChannel::PUBLIC_TRADE,
            exchangeTimestamp: $exchangeTimestamp,
            naturalIdentity: $naturalIdentity,
            payload: $payload,
            receivedTimestamp: $this->receiptTimestamp(),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array{identity_hash: string, assignment_digest: string}
     */
    public function liveTradeFingerprint(#[\SensitiveParameter] array $row): array
    {
        [, $exchangeTimestamp, $naturalIdentity, $payload]
            = $this->liveTradeData($row);

        return [
            'identity_hash' => hash('sha256', $naturalIdentity),
            'assignment_digest' => HyperliquidPaperSourceOrdinal::assignmentDigest(
                $naturalIdentity,
                $exchangeTimestamp,
                $payload,
            ),
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array{string, \DateTimeImmutable, string, array<string, mixed>}
     */
    private function liveTradeData(#[\SensitiveParameter] array $row): array
    {
        self::assertExactKeys(
            $row,
            ['coin', 'side', 'px', 'sz', 'hash', 'time', 'tid', 'users'],
        );
        $coin = $row['coin'] ?? null;
        $side = $row['side'] ?? null;
        $price = $row['px'] ?? null;
        $size = $row['sz'] ?? null;
        $hash = $row['hash'] ?? null;
        $time = $row['time'] ?? null;
        $tid = $row['tid'] ?? null;
        if (!\is_string($coin)
            || !\is_string($side)
            || !\in_array($side, ['A', 'B'], true)
            || !\is_string($price)
            || !\is_string($size)
            || !\is_string($hash)
            || !\is_int($time)
            || $time < 0
            || !\is_int($tid)
            || $tid < 0
            || !\is_array($row['users'] ?? null)
            || \count($row['users']) !== 2
        ) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_trade_invalid');
        }
        $symbol = (new HyperliquidPaperInstrumentMap())->normalizedSymbol($coin);
        $price = self::canonicalPositiveDecimal($price);
        $size = self::canonicalPositiveDecimal($size);
        if (preg_match('/\A0x[0-9a-fA-F]{1,128}\z/D', $hash) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_trade_invalid');
        }
        $exchangeTimestamp = $this->timestamp($time);
        $naturalIdentity = implode('|', [
            $this->network->value,
            $coin,
            (string) $time,
            (string) $tid,
        ]);
        $payload = [
            'native_symbol' => $coin,
            'side' => $side === 'B' ? 'buy' : 'sell',
            'price' => $price,
            'size' => $size,
            'transaction_hash' => $hash,
            'block_time' => (string) $time,
            'trade_id' => (string) $tid,
            'origin' => 'ws_trades',
        ];

        return [$symbol, $exchangeTimestamp, $naturalIdentity, $payload];
    }

    /** @param array<array-key, mixed> $book */
    public function liveTopOfBook(
        #[\SensitiveParameter] array $book,
        int $sourceEpoch,
    ): PaperMarketEvent {
        self::assertExactKeys($book, ['coin', 'levels', 'time']);
        $coin = $book['coin'] ?? null;
        $time = $book['time'] ?? null;
        $levels = $book['levels'] ?? null;
        if (!\is_string($coin)
            || !\is_int($time)
            || $time < 0
            || $sourceEpoch < 1
            || !\is_array($levels)
            || !array_is_list($levels)
            || \count($levels) !== 2
        ) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_book_invalid');
        }
        $symbol = (new HyperliquidPaperInstrumentMap())->normalizedSymbol($coin);
        [$bidPrice, $bidSize] = self::bestLevel($levels[0], bids: true);
        [$askPrice, $askSize] = self::bestLevel($levels[1], bids: false);
        if (BigDecimal::of($bidPrice)->isGreaterThanOrEqualTo($askPrice)) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_book_invalid');
        }
        $sourceBookHash = hash('sha256', CanonicalJson::encode($book));

        return $this->event(
            symbol: $symbol,
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: $this->timestamp($time),
            naturalIdentity: implode('|', [
                $this->network->value,
                $coin,
                'book',
                (string) $time,
                $sourceBookHash,
            ]),
            payload: [
                'native_symbol' => $coin,
                'bid_price' => $bidPrice,
                'bid_size' => $bidSize,
                'ask_price' => $askPrice,
                'ask_size' => $askSize,
                'bid_level_count' => (string) \count($levels[0]),
                'ask_level_count' => (string) \count($levels[1]),
                'source_time' => (string) $time,
                'source_epoch' => (string) $sourceEpoch,
                'source_book_hash' => $sourceBookHash,
                'origin' => 'ws_l2_book',
                'synthetic' => false,
            ],
            receivedTimestamp: $this->receiptTimestamp(),
        );
    }

    public function connectionState(
        string $coin,
        string $state,
        int $epoch,
    ): PaperMarketEvent {
        if (!\in_array($state, ['connected', 'subscribed', 'reconnecting', 'stopped'], true)
            || $epoch < 1
        ) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_connection_state_invalid',
            );
        }
        $symbol = (new HyperliquidPaperInstrumentMap())->normalizedSymbol($coin);
        $timestamp = $this->receiptTimestamp();

        return $this->event(
            symbol: $symbol,
            channel: PaperMarketDataChannel::CONNECTION_STATE,
            exchangeTimestamp: $timestamp,
            naturalIdentity: implode('|', [
                $this->network->value,
                $coin,
                'connection',
                (string) $epoch,
                $state,
            ]),
            payload: [
                'native_symbol' => $coin,
                'state' => $state,
                'connection_epoch' => $epoch,
            ],
            receivedTimestamp: $timestamp,
        );
    }

    public function snapshotBoundary(
        string $coin,
        string $reason,
        int $epoch,
    ): PaperMarketEvent {
        if (!\in_array($reason, ['initial', 'reconnect'], true) || $epoch < 1) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_snapshot_boundary_invalid',
            );
        }
        $symbol = (new HyperliquidPaperInstrumentMap())->normalizedSymbol($coin);
        $timestamp = $this->receiptTimestamp();

        return $this->event(
            symbol: $symbol,
            channel: PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            exchangeTimestamp: $timestamp,
            naturalIdentity: implode('|', [
                $this->network->value,
                $coin,
                'snapshot',
                (string) $epoch,
                $reason,
            ]),
            payload: [
                'native_symbol' => $coin,
                'reason' => $reason,
                'source_epoch' => $epoch,
            ],
            receivedTimestamp: $timestamp,
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

        $validationWitness = HyperliquidPaperSourceOrdinal::modelValidationWitness(
            $candle,
            $book,
        );
        $timestamp = $this->timestamp($candle->closeTime);
        $payload = [
            'bid_price' => $book['bid'],
            'bid_size' => $book['size'],
            'ask_price' => $book['ask'],
            'ask_size' => $book['size'],
            'model_name' => HyperliquidPrudentBookModel::NAME,
            'model_version' => HyperliquidPrudentBookModel::VERSION,
            'origin' => 'historical_candle_model',
            'source_candle_start' => (string) $candle->startTime,
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
            validationWitness: $validationWitness,
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed>|null $validationWitness
     */
    private function event(
        string $symbol,
        PaperMarketDataChannel $channel,
        \DateTimeImmutable $exchangeTimestamp,
        string $naturalIdentity,
        #[\SensitiveParameter]
        array $payload,
        #[\SensitiveParameter]
        ?array $validationWitness = null,
        ?\DateTimeImmutable $receivedTimestamp = null,
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
            receivedTimestamp: $receivedTimestamp ?? $exchangeTimestamp,
            sequence: $assignment['sequence'],
            payload: $payload,
        );
        $this->ordinals->commit(
            $scope,
            $naturalIdentity,
            $digest,
            $event,
            $validationWitness,
        );

        return $event;
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

    private function receiptTimestamp(): \DateTimeImmutable
    {
        if ($this->clock === null) {
            throw new \LogicException('hyperliquid_paper_live_clock_required');
        }

        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @param mixed $levels
     *  @return array{string, string}
     */
    private static function bestLevel(mixed $levels, bool $bids): array
    {
        if (!\is_array($levels) || !array_is_list($levels) || $levels === []) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_book_invalid');
        }
        $bestPrice = null;
        $bestSize = null;
        foreach ($levels as $level) {
            if (!\is_array($level) || array_is_list($level)) {
                throw new \InvalidArgumentException('hyperliquid_paper_live_book_invalid');
            }
            self::assertExactKeys($level, ['px', 'sz', 'n']);
            $price = $level['px'] ?? null;
            $size = $level['sz'] ?? null;
            if (!\is_string($price)
                || !\is_string($size)
                || !\is_int($level['n'] ?? null)
                || $level['n'] < 1
            ) {
                throw new \InvalidArgumentException('hyperliquid_paper_live_book_invalid');
            }
            $price = self::canonicalPositiveDecimal($price);
            $size = self::canonicalPositiveDecimal($size);
            if ($bestPrice === null
                || ($bids
                    ? BigDecimal::of($price)->isGreaterThan($bestPrice)
                    : BigDecimal::of($price)->isLessThan($bestPrice))
            ) {
                $bestPrice = $price;
                $bestSize = $size;
            }
        }

        return [
            $bestPrice,
            $bestSize,
        ];
    }

    private static function canonicalPositiveDecimal(string $value): string
    {
        if (\strlen($value) > 128
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
            || !BigDecimal::of($value)->isGreaterThan(0)
        ) {
            throw new \InvalidArgumentException('hyperliquid_paper_decimal_invalid');
        }

        return (string) BigDecimal::of($value)->stripTrailingZeros();
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $keys
     */
    private static function assertExactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, \SORT_STRING);
        sort($keys, \SORT_STRING);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_shape_invalid');
        }
    }
}
