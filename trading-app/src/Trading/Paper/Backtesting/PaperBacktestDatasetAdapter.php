<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\VerifiedPaperDatasetSnapshot;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use Brick\Math\BigDecimal;

final class PaperBacktestDatasetAdapter
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';
    private const MAX_DECIMAL_LENGTH = 256;

    /** @var array<string, int> */
    private const DURATIONS = ['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600];

    /** @var list<string> */
    private const OKX_KEYS = [
        'native_symbol', 'bar', 'open', 'high', 'low', 'close',
        'volume_contracts', 'volume_base', 'volume_quote', 'confirmed', 'origin',
    ];

    /** @var list<string> */
    private const HYPERLIQUID_KEYS = [
        'native_symbol', 'interval', 'start_time', 'close_time', 'open', 'high',
        'low', 'close', 'volume', 'trade_count', 'confirmed', 'origin',
    ];

    /** @var list<string> */
    private const OKX_TRADE_KEYS = [
        'native_symbol', 'trade_id', 'price', 'size_contracts', 'taker_side',
        'aggregate_count', 'source', 'source_seq_id', 'origin',
    ];

    /** @var list<string> */
    private const HYPERLIQUID_TRADE_KEYS = [
        'native_symbol', 'side', 'price', 'size', 'transaction_hash', 'block_time',
        'trade_id', 'origin',
    ];

    /** @var list<string> */
    private const OKX_BOOK_KEYS = [
        'native_symbol', 'bid_price', 'bid_size_contracts', 'bid_order_count',
        'ask_price', 'ask_size_contracts', 'ask_order_count', 'source_seq_id',
        'source_prev_seq_id', 'source_epoch', 'origin',
    ];

    /** @var list<string> */
    private const HYPERLIQUID_BOOK_KEYS = [
        'native_symbol', 'bid_price', 'bid_size', 'ask_price', 'ask_size',
        'bid_level_count', 'ask_level_count', 'source_time', 'source_epoch',
        'source_book_hash', 'origin', 'synthetic',
    ];

    public function adapt(VerifiedPaperDatasetSnapshot $snapshot): PaperBacktestDataset
    {
        $manifest = $snapshot->manifest;
        $this->assertManifest($manifest, $snapshot->events);
        $this->assertManifestSymbols($manifest);

        $candles = [];
        $publicTrades = [];
        $publicBooks = [];
        $sourceChecksum = 'sha256:' . $manifest->eventsFileSha256;
        foreach ($snapshot->events as $event) {
            $this->assertEventEnvelope($event, $manifest);
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE) {
                $this->assertCandleNativeSymbol($event, $manifest);
                $publicTrades[] = $manifest->venue === PaperMarketDataVenue::OKX
                    ? $this->normalizeOkxTrade($event, $sourceChecksum)
                    : $this->normalizeHyperliquidTrade($event, $sourceChecksum);
                continue;
            }
            if ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK) {
                if ($manifest->quality !== PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES) {
                    continue;
                }
                $this->assertCandleNativeSymbol($event, $manifest);
                $publicBooks[] = $manifest->venue === PaperMarketDataVenue::OKX
                    ? $this->normalizeOkxBook($event, $sourceChecksum)
                    : $this->normalizeHyperliquidBook($event, $sourceChecksum);
                continue;
            }
            $timeframe = $this->timeframe($event->channel);
            if ($timeframe === null) {
                continue;
            }
            $this->assertCandleNativeSymbol($event, $manifest);
            $candles[] = $manifest->venue === PaperMarketDataVenue::OKX
                ? $this->normalizeOkx($event, $timeframe)
                : $this->normalizeHyperliquid($event, $timeframe);
        }
        if ($candles === []) {
            throw new PaperBacktestAdapterException('paper_backtest_candles_empty');
        }

        usort($candles, static function (
            NormalizedBacktestCandle $left,
            NormalizedBacktestCandle $right,
        ): int {
            return [$left->marketDataVenue, $left->symbol, self::DURATIONS[$left->timeframe], $left->openAt, $left->sourceRecordId]
                <=> [$right->marketDataVenue, $right->symbol, self::DURATIONS[$right->timeframe], $right->openAt, $right->sourceRecordId];
        });
        usort($publicTrades, static fn (
            NormalizedBacktestPublicTrade $left,
            NormalizedBacktestPublicTrade $right,
        ): int => [$left->availableAt, $left->happenedAt, $left->sourceRecordId]
            <=> [$right->availableAt, $right->happenedAt, $right->sourceRecordId]);
        usort($publicBooks, static fn (
            NormalizedBacktestPublicBook $left,
            NormalizedBacktestPublicBook $right,
        ): int => [$left->availableAt, $left->happenedAt, $left->sourceRecordId]
            <=> [$right->availableAt, $right->happenedAt, $right->sourceRecordId]);

        return new PaperBacktestDataset([
            'source' => 'paper_market_dataset',
            'source_schema_version' => 'paper-market-dataset.v2',
            'source_build_version' => $manifest->recorderVersion,
            'source_checksum' => $sourceChecksum,
            'source_network' => $manifest->network->value,
            'market_data_venue' => $manifest->venue->value,
            'market_type' => 'perpetual',
        ], $candles, $publicTrades, $publicBooks);
    }

    private function normalizeOkxBook(
        PaperMarketEvent $event,
        string $sourceChecksum,
    ): NormalizedBacktestPublicBook {
        $payload = $event->payload;
        $this->assertExactKeys($payload, self::OKX_BOOK_KEYS);
        if (!\in_array($payload['origin'] ?? null, [
            'rest_initial_snapshot',
            'rest_resync_snapshot',
            'ws_books',
        ], true)
            || !\is_int($payload['source_epoch'] ?? null)
            || $payload['source_epoch'] < 1
            || !$this->unsignedString($payload['source_seq_id'] ?? null)
            || (($payload['source_prev_seq_id'] ?? null) !== null
                && !$this->signedSourceString($payload['source_prev_seq_id']))
            || !$this->positiveUnsignedString($payload['bid_order_count'] ?? null)
            || !$this->positiveUnsignedString($payload['ask_order_count'] ?? null)
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_public_book_invalid');
        }
        return $this->publicBook(
            $event,
            $sourceChecksum,
            $payload['bid_price'] ?? null,
            $payload['bid_size_contracts'] ?? null,
            $payload['ask_price'] ?? null,
            $payload['ask_size_contracts'] ?? null,
            'contracts',
            $payload['bid_order_count'],
            $payload['ask_order_count'],
            $payload['origin'],
        );
    }

    private function normalizeHyperliquidBook(
        PaperMarketEvent $event,
        string $sourceChecksum,
    ): NormalizedBacktestPublicBook {
        $payload = $event->payload;
        $this->assertExactKeys($payload, self::HYPERLIQUID_BOOK_KEYS);
        if (($payload['origin'] ?? null) !== 'ws_l2_book'
            || ($payload['synthetic'] ?? null) !== false
            || !$this->unsignedString($payload['source_time'] ?? null)
            || $payload['source_time'] !== $event->exchangeTimestamp->format('Uv')
            || !$this->positiveUnsignedString($payload['source_epoch'] ?? null)
            || !$this->positiveUnsignedString($payload['bid_level_count'] ?? null)
            || !$this->positiveUnsignedString($payload['ask_level_count'] ?? null)
            || !\is_string($payload['source_book_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $payload['source_book_hash']) !== 1
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_public_book_invalid');
        }
        return $this->publicBook(
            $event,
            $sourceChecksum,
            $payload['bid_price'] ?? null,
            $payload['bid_size'] ?? null,
            $payload['ask_price'] ?? null,
            $payload['ask_size'] ?? null,
            'base_asset',
            null,
            null,
            'ws_l2_book',
        );
    }

    private function publicBook(
        PaperMarketEvent $event,
        string $sourceChecksum,
        mixed $bidPrice,
        mixed $bidQuantity,
        mixed $askPrice,
        mixed $askQuantity,
        string $quantityUnit,
        ?string $bidOrderCount,
        ?string $askOrderCount,
        string $origin,
    ): NormalizedBacktestPublicBook {
        try {
            return new NormalizedBacktestPublicBook(
                $event->eventId,
                $sourceChecksum,
                $event->sourceNetwork->value,
                $event->sourceVenue->value,
                $event->symbol,
                $event->exchangeTimestamp->format(self::TIMESTAMP_FORMAT),
                $event->receivedTimestamp->format(self::TIMESTAMP_FORMAT),
                $this->decimal($bidPrice, true),
                $this->decimal($bidQuantity, true),
                $this->decimal($askPrice, true),
                $this->decimal($askQuantity, true),
                $quantityUnit,
                $bidOrderCount,
                $askOrderCount,
                $origin,
            );
        } catch (PaperBacktestAdapterException|\InvalidArgumentException) {
            throw new PaperBacktestAdapterException('paper_backtest_public_book_invalid');
        }
    }

    private function unsignedString(mixed $value): bool
    {
        return \is_string($value)
            && \strlen($value) <= 128
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1;
    }

    private function positiveUnsignedString(mixed $value): bool
    {
        return \is_string($value)
            && \strlen($value) <= 128
            && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1;
    }

    private function signedSourceString(mixed $value): bool
    {
        return $value === '-1' || $this->unsignedString($value);
    }

    private function normalizeOkxTrade(
        PaperMarketEvent $event,
        string $sourceChecksum,
    ): NormalizedBacktestPublicTrade
    {
        $payload = $event->payload;
        $this->assertExactKeys($payload, self::OKX_TRADE_KEYS);
        if (!\in_array($payload['origin'] ?? null, [
            'rest_history',
            'rest_recovery',
            'ws_aggregated',
        ], true)
            || !\is_string($payload['trade_id'] ?? null)
            || \strlen($payload['trade_id']) > 128
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $payload['trade_id']) !== 1
            || !\in_array($payload['taker_side'] ?? null, ['buy', 'sell'], true)
            || !\is_string($payload['source'] ?? null)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $payload['source']) !== 1
            || ($payload['aggregate_count'] !== null && (!\is_string($payload['aggregate_count'])
                || preg_match('/\A[1-9][0-9]*\z/D', $payload['aggregate_count']) !== 1))
            || ($payload['source_seq_id'] !== null && (!\is_string($payload['source_seq_id'])
                || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $payload['source_seq_id']) !== 1))
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_public_trade_invalid');
        }
        return $this->publicTrade(
            $event,
            $sourceChecksum,
            $payload['trade_id'],
            $payload['taker_side'],
            $payload['price'] ?? null,
            $payload['size_contracts'] ?? null,
            'contracts',
        );
    }

    private function normalizeHyperliquidTrade(
        PaperMarketEvent $event,
        string $sourceChecksum,
    ): NormalizedBacktestPublicTrade
    {
        $payload = $event->payload;
        $this->assertExactKeys($payload, self::HYPERLIQUID_TRADE_KEYS);
        if (($payload['origin'] ?? null) !== 'ws_trades'
            || !\is_string($payload['trade_id'] ?? null)
            || \strlen($payload['trade_id']) > 63
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $payload['trade_id']) !== 1
            || !\in_array($payload['side'] ?? null, ['buy', 'sell'], true)
            || !\is_string($payload['transaction_hash'] ?? null)
            || preg_match('/\A0x[0-9a-fA-F]{1,128}\z/D', $payload['transaction_hash']) !== 1
            || !\is_string($payload['block_time'] ?? null)
            || \strlen($payload['block_time']) > 64
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $payload['block_time']) !== 1
            || $payload['block_time'] !== $event->exchangeTimestamp->format('Uv')
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_public_trade_invalid');
        }
        return $this->publicTrade(
            $event,
            $sourceChecksum,
            $payload['block_time'] . ':' . $payload['trade_id'],
            $payload['side'],
            $payload['price'] ?? null,
            $payload['size'] ?? null,
            'base_asset',
        );
    }

    private function publicTrade(
        PaperMarketEvent $event,
        string $sourceChecksum,
        string $tradeId,
        string $side,
        mixed $price,
        mixed $quantity,
        string $unit,
    ): NormalizedBacktestPublicTrade {
        try {
            $normalizedPrice = $this->decimal($price, true);
            $normalizedQuantity = $this->decimal($quantity, true);
        } catch (PaperBacktestAdapterException) {
            throw new PaperBacktestAdapterException('paper_backtest_public_trade_invalid');
        }
        return new NormalizedBacktestPublicTrade(
            $event->eventId,
            $sourceChecksum,
            $event->sourceNetwork->value,
            $event->sourceVenue->value,
            $event->symbol,
            $tradeId,
            $event->exchangeTimestamp->format(self::TIMESTAMP_FORMAT),
            $event->receivedTimestamp->format(self::TIMESTAMP_FORMAT),
            $side,
            $normalizedPrice,
            $normalizedQuantity,
            $unit,
        );
    }

    /** @param list<PaperMarketEvent> $events */
    private function assertManifest(PaperDatasetManifest $manifest, array $events): void
    {
        $checksum = hash_init('sha256');
        $channels = [];
        $start = null;
        $end = null;
        foreach ($events as $event) {
            hash_update($checksum, CanonicalJson::encode($event->toArray()) . "\n");
            $channels[] = $event->channel->value;
            $start = $start === null || $event->exchangeTimestamp < $start
                ? $event->exchangeTimestamp : $start;
            $end = $end === null || $event->exchangeTimestamp > $end
                ? $event->exchangeTimestamp : $end;
        }
        $channels = array_values(array_unique($channels));
        sort($channels, \SORT_STRING);
        $lastEventId = $events === [] ? null : $events[array_key_last($events)]->eventId;
        if ($manifest->schemaVersion !== PaperDatasetManifest::SCHEMA_VERSION
            || $manifest->state !== PaperDatasetState::COMPLETE
            || !$manifest->network->isCertifiable()
            || !\in_array($manifest->venue, [PaperMarketDataVenue::OKX, PaperMarketDataVenue::HYPERLIQUID], true)
            || $manifest->recorderVersion === ''
            || trim($manifest->recorderVersion) !== $manifest->recorderVersion
            || $manifest->eventCount !== \count($events)
            || $manifest->eventsFileSha256 === null
            || preg_match('/\A[0-9a-f]{64}\z/D', $manifest->eventsFileSha256) !== 1
            || !hash_equals($manifest->eventsFileSha256, hash_final($checksum))
            || $manifest->lastEventId !== $lastEventId
            || $manifest->startExchangeTimestamp != $start
            || $manifest->endExchangeTimestamp != $end
            || $manifest->channels !== $channels
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_manifest_invalid');
        }
    }

    private function assertManifestSymbols(PaperDatasetManifest $manifest): void
    {
        foreach ($manifest->symbols as $symbol => $nativeSymbol) {
            try {
                $expectedNativeSymbol = $manifest->venue === PaperMarketDataVenue::OKX
                    ? (new OkxPaperInstrumentMap())->nativeInstrumentId($symbol)
                    : (new HyperliquidPaperInstrumentMap())->nativeCoin($symbol);
            } catch (\InvalidArgumentException) {
                throw new PaperBacktestAdapterException('paper_backtest_event_provenance_invalid');
            }
            if ($nativeSymbol !== $expectedNativeSymbol) {
                throw new PaperBacktestAdapterException('paper_backtest_event_provenance_invalid');
            }
        }
    }

    private function assertEventEnvelope(PaperMarketEvent $event, PaperDatasetManifest $manifest): void
    {
        if ($event->schemaVersion !== PaperMarketEvent::SCHEMA_VERSION
            || $event->sourceNetwork !== $manifest->network
            || $event->sourceVenue !== $manifest->venue
            || !isset($manifest->symbols[$event->symbol])
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_event_provenance_invalid');
        }
    }

    private function assertCandleNativeSymbol(PaperMarketEvent $event, PaperDatasetManifest $manifest): void
    {
        $nativeSymbol = $event->payload['native_symbol'] ?? null;
        if (!\is_string($nativeSymbol) || $manifest->symbols[$event->symbol] !== $nativeSymbol) {
            throw new PaperBacktestAdapterException('paper_backtest_event_provenance_invalid');
        }
    }

    private function normalizeOkx(PaperMarketEvent $event, string $timeframe): NormalizedBacktestCandle
    {
        $payload = $event->payload;
        $this->assertExactKeys($payload, self::OKX_KEYS);
        if (($payload['bar'] ?? null) !== $timeframe
            || ($payload['confirmed'] ?? null) !== true
            || !\in_array($payload['origin'] ?? null, ['rest_history', 'rest_warmup', 'ws_candle'], true)
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_okx_payload_invalid');
        }

        $openAt = $event->exchangeTimestamp;
        $this->assertGrid($openAt, self::DURATIONS[$timeframe]);
        $closeAt = $openAt->modify('+' . self::DURATIONS[$timeframe] . ' seconds');
        $this->decimal($payload['volume_contracts'] ?? null, false);
        $this->decimal($payload['volume_quote'] ?? null, false);

        return $this->candle(
            $event,
            $timeframe,
            $openAt,
            $closeAt,
            $payload,
            $payload['volume_base'] ?? null,
        );
    }

    private function normalizeHyperliquid(PaperMarketEvent $event, string $timeframe): NormalizedBacktestCandle
    {
        $payload = $event->payload;
        $this->assertExactKeys($payload, self::HYPERLIQUID_KEYS);
        if (($payload['interval'] ?? null) !== $timeframe
            || ($payload['confirmed'] ?? null) !== true
            || !\in_array($payload['origin'] ?? null, ['rest_candle_snapshot', 'ws_candle'], true)
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_hyperliquid_payload_invalid');
        }

        $start = $this->unsignedInteger($payload['start_time'] ?? null);
        $close = $this->unsignedInteger($payload['close_time'] ?? null);
        $durationMilliseconds = self::DURATIONS[$timeframe] * 1000;
        if ($start % $durationMilliseconds !== 0 || $close !== $start + $durationMilliseconds - 1) {
            throw new PaperBacktestAdapterException('paper_backtest_candle_time_invalid');
        }
        $openAt = $this->fromMilliseconds($start);
        $inclusiveCloseAt = $this->fromMilliseconds($close);
        if ($event->exchangeTimestamp != $inclusiveCloseAt) {
            throw new PaperBacktestAdapterException('paper_backtest_candle_time_invalid');
        }
        $closeAt = $openAt->modify('+' . self::DURATIONS[$timeframe] . ' seconds');
        $this->unsignedInteger($payload['trade_count'] ?? null);

        return $this->candle($event, $timeframe, $openAt, $closeAt, $payload, $payload['volume'] ?? null);
    }

    /** @param array<array-key, mixed> $payload */
    private function candle(
        PaperMarketEvent $event,
        string $timeframe,
        \DateTimeImmutable $openAt,
        \DateTimeImmutable $closeAt,
        array $payload,
        mixed $volumeValue,
    ): NormalizedBacktestCandle {
        $open = $this->decimal($payload['open'] ?? null, true);
        $high = $this->decimal($payload['high'] ?? null, true);
        $low = $this->decimal($payload['low'] ?? null, true);
        $close = $this->decimal($payload['close'] ?? null, true);
        $volume = $this->decimal($volumeValue, false);
        if (BigDecimal::of($low)->isGreaterThan(BigDecimal::of($open))
            || BigDecimal::of($low)->isGreaterThan(BigDecimal::of($close))
            || BigDecimal::of($high)->isLessThan(BigDecimal::of($open))
            || BigDecimal::of($high)->isLessThan(BigDecimal::of($close))
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_candle_geometry_invalid');
        }
        $availableAt = $event->receivedTimestamp > $closeAt ? $event->receivedTimestamp : $closeAt;

        return new NormalizedBacktestCandle(
            $event->eventId,
            $event->sourceNetwork->value,
            $event->sourceVenue->value,
            $event->symbol,
            $timeframe,
            $openAt->format(self::TIMESTAMP_FORMAT),
            $closeAt->format(self::TIMESTAMP_FORMAT),
            $availableAt->format(self::TIMESTAMP_FORMAT),
            $open,
            $high,
            $low,
            $close,
            $volume,
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param list<string> $expected
     */
    private function assertExactKeys(array $payload, array $expected): void
    {
        $keys = array_keys($payload);
        if (!array_is_list($keys)) {
            throw new PaperBacktestAdapterException('paper_backtest_payload_shape_invalid');
        }
        foreach ($keys as $key) {
            if (!\is_string($key)) {
                throw new PaperBacktestAdapterException('paper_backtest_payload_shape_invalid');
            }
        }
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new PaperBacktestAdapterException('paper_backtest_payload_shape_invalid');
        }
    }

    private function decimal(mixed $value, bool $positive): string
    {
        if (!\is_string($value)
            || \strlen($value) > self::MAX_DECIMAL_LENGTH
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_decimal_invalid');
        }
        $decimal = BigDecimal::of($value)->stripTrailingZeros();
        if (($positive && !$decimal->isPositive()) || (!$positive && $decimal->isNegative())) {
            throw new PaperBacktestAdapterException('paper_backtest_decimal_invalid');
        }

        return (string) $decimal;
    }

    private function unsignedInteger(mixed $value): int
    {
        $maximum = (string) \PHP_INT_MAX;
        if (!\is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || \strlen($value) > \strlen($maximum)
            || (\strlen($value) === \strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_unsigned_integer_invalid');
        }

        return (int) $value;
    }

    private function assertGrid(\DateTimeImmutable $timestamp, int $duration): void
    {
        if ($timestamp->getOffset() !== 0
            || $timestamp->format('u') !== '000000'
            || ((int) $timestamp->format('U')) % $duration !== 0
        ) {
            throw new PaperBacktestAdapterException('paper_backtest_candle_time_invalid');
        }
    }

    private function fromMilliseconds(int $milliseconds): \DateTimeImmutable
    {
        $source = intdiv($milliseconds, 1000) . '.'
            . str_pad((string) (($milliseconds % 1000) * 1000), 6, '0', \STR_PAD_LEFT);
        $timestamp = \DateTimeImmutable::createFromFormat('!U.u', $source, new \DateTimeZone('UTC'));
        if ($timestamp === false) {
            throw new PaperBacktestAdapterException('paper_backtest_candle_time_invalid');
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    private function timeframe(PaperMarketDataChannel $channel): ?string
    {
        return match ($channel) {
            PaperMarketDataChannel::CANDLE_1M => '1m',
            PaperMarketDataChannel::CANDLE_5M => '5m',
            PaperMarketDataChannel::CANDLE_15M => '15m',
            PaperMarketDataChannel::CANDLE_1H => '1h',
            default => null,
        };
    }
}
