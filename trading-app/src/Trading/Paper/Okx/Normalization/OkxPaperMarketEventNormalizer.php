<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Normalization;

use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use Symfony\Component\Clock\ClockInterface;

final class OkxPaperMarketEventNormalizer
{
    /** @var list<string> */
    private const CONNECTION_STATES = ['connected', 'subscribed', 'reconnecting', 'stopped'];

    /** @var list<string> */
    private const SNAPSHOT_REASONS = ['initial', 'reconnect', 'sequence_gap'];

    /** @var list<string> */
    private const TOP_OF_BOOK_ORIGINS = [
        'rest_initial_snapshot',
        'rest_resync_snapshot',
        'ws_books',
    ];

    private readonly OkxPaperInstrumentMap $instruments;
    private readonly OkxPaperSourceOrdinal $ordinals;

    public function __construct(
        private readonly ClockInterface $clock,
        ?OkxPaperInstrumentMap $instruments = null,
        ?OkxPaperSourceOrdinal $ordinals = null,
    ) {
        $this->instruments = $instruments ?? new OkxPaperInstrumentMap();
        $this->ordinals = $ordinals ?? new OkxPaperSourceOrdinal();
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public function historyCandle(
        string $instrumentId,
        string $bar,
        #[\SensitiveParameter]
        array $row,
    ): ?PaperMarketEvent {
        return $this->candle(
            $instrumentId,
            $bar,
            $row,
            origin: 'rest_history',
            receiptAtExchangeTime: true,
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public function warmupCandle(
        string $instrumentId,
        string $bar,
        #[\SensitiveParameter]
        array $row,
    ): ?PaperMarketEvent {
        return $this->candle(
            $instrumentId,
            $bar,
            $row,
            origin: 'rest_warmup',
            receiptAtExchangeTime: false,
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public function webSocketCandle(
        string $instrumentId,
        string $bar,
        #[\SensitiveParameter]
        array $row,
    ): ?PaperMarketEvent {
        return $this->candle(
            $instrumentId,
            $this->webSocketBar($bar),
            $row,
            origin: 'ws_candle',
            receiptAtExchangeTime: false,
        );
    }

    /** @param array<string, mixed> $row */
    public function historyTrade(#[\SensitiveParameter] array $row): PaperMarketEvent
    {
        return $this->trade($row, 'rest_history', historical: true, aggregationRequired: false);
    }

    /** @param array<string, mixed> $row */
    public function recoveryTrade(#[\SensitiveParameter] array $row): PaperMarketEvent
    {
        return $this->trade($row, 'rest_recovery', historical: false, aggregationRequired: false);
    }

    /** @param array<string, mixed> $row */
    public function webSocketTrade(#[\SensitiveParameter] array $row): PaperMarketEvent
    {
        return $this->trade($row, 'ws_aggregated', historical: false, aggregationRequired: true);
    }

    public function connectionState(
        string $instrumentId,
        string $state,
        int $connectionEpoch,
    ): PaperMarketEvent {
        if (!\in_array($state, self::CONNECTION_STATES, true) || $connectionEpoch < 1) {
            throw new \InvalidArgumentException('okx_paper_connection_state_invalid');
        }

        $timestamp = $this->receiptTimestamp();

        return $this->event(
            symbol: $this->instruments->normalizedSymbol($instrumentId),
            channel: PaperMarketDataChannel::CONNECTION_STATE,
            exchangeTimestamp: $timestamp,
            receivedTimestamp: $timestamp,
            naturalIdentity: implode('|', ['connection', (string) $connectionEpoch, $state]),
            payload: [
                'native_symbol' => $instrumentId,
                'state' => $state,
                'connection_epoch' => $connectionEpoch,
            ],
        );
    }

    public function snapshotBoundary(
        string $instrumentId,
        string $reason,
        int $sourceEpoch,
        string $sourceSequence,
    ): PaperMarketEvent {
        if (!\in_array($reason, self::SNAPSHOT_REASONS, true) || $sourceEpoch < 1) {
            throw new \InvalidArgumentException('okx_paper_snapshot_boundary_invalid');
        }
        $sourceSequence = $this->sourceSequence($sourceSequence);
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $sourceSequence) !== 1) {
            throw new \InvalidArgumentException('okx_paper_source_sequence_invalid');
        }
        $timestamp = $this->receiptTimestamp();

        return $this->event(
            symbol: $this->instruments->normalizedSymbol($instrumentId),
            channel: PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            exchangeTimestamp: $timestamp,
            receivedTimestamp: $timestamp,
            naturalIdentity: implode('|', [
                'snapshot',
                (string) $sourceEpoch,
                $sourceSequence,
                $reason,
            ]),
            payload: [
                'native_symbol' => $instrumentId,
                'reason' => $reason,
                'source_epoch' => $sourceEpoch,
                'source_seq_id' => $sourceSequence,
            ],
        );
    }

    /** Normalize only a complete book proven by OkxMaterializedBookState construction. */
    public function materializedTopOfBook(
        string $instrumentId,
        OkxMaterializedBookState $materializedBookState,
        int $sourceEpoch,
        string $origin = 'ws_books',
    ): PaperMarketEvent {
        if ($sourceEpoch < 1) {
            throw new \InvalidArgumentException('okx_paper_materialized_order_book_invalid');
        }
        if (!\in_array($origin, self::TOP_OF_BOOK_ORIGINS, true)) {
            throw new \InvalidArgumentException('okx_paper_top_of_book_origin_invalid');
        }

        $bid = $materializedBookState->bestBid();
        $ask = $materializedBookState->bestAsk();
        $sourceSequence = $materializedBookState->sourceSequence;
        $sourcePreviousSequence = $materializedBookState->sourcePreviousSequence;
        $exchangeTimestamp = $materializedBookState->exchangeTimestamp;

        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        $payload = [
            'native_symbol' => $instrumentId,
            'bid_price' => $bid['price'],
            'bid_size_contracts' => $bid['size'],
            'bid_order_count' => $bid['order_count'],
            'ask_price' => $ask['price'],
            'ask_size_contracts' => $ask['size'],
            'ask_order_count' => $ask['order_count'],
            'source_seq_id' => $sourceSequence,
            'source_prev_seq_id' => $sourcePreviousSequence,
            'source_epoch' => $sourceEpoch,
            'origin' => $origin,
        ];

        return $this->event(
            symbol: $symbol,
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $this->clock->now(),
            naturalIdentity: implode('|', ['book', (string) $sourceEpoch, $sourceSequence]),
            payload: $payload,
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function candle(
        string $instrumentId,
        string $bar,
        #[\SensitiveParameter]
        array $row,
        string $origin,
        bool $receiptAtExchangeTime,
    ): ?PaperMarketEvent {
        [$channel, $normalizedBar] = $this->timeframe($bar);
        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        $timestampValue = $this->unsignedIntegerString($row[0] ?? null);
        $exchangeTimestamp = $this->timestamp($timestampValue);
        $open = $this->decimal($row[1] ?? null);
        $high = $this->decimal($row[2] ?? null);
        $low = $this->decimal($row[3] ?? null);
        $close = $this->decimal($row[4] ?? null);
        $volumeContracts = $this->decimal($row[5] ?? null);
        $volumeBase = $this->decimal($row[6] ?? null);
        $volumeQuote = $this->decimal($row[7] ?? null);
        $confirmed = $row[8] ?? null;
        if (!\is_string($confirmed) || ($confirmed !== '0' && $confirmed !== '1')) {
            throw new \InvalidArgumentException('okx_paper_candle_confirmation_invalid');
        }
        if (!array_is_list($row) || \count($row) !== 9) {
            throw new \InvalidArgumentException('okx_paper_candle_invalid');
        }
        if ($confirmed === '0') {
            return null;
        }

        return $this->event(
            symbol: $symbol,
            channel: $channel,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $receiptAtExchangeTime ? $exchangeTimestamp : $this->receiptTimestamp(),
            naturalIdentity: implode('|', ['candle', $bar, $timestampValue]),
            payload: [
                'native_symbol' => $instrumentId,
                'bar' => $normalizedBar,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume_contracts' => $volumeContracts,
                'volume_base' => $volumeBase,
                'volume_quote' => $volumeQuote,
                'confirmed' => true,
                'origin' => $origin,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function trade(
        #[\SensitiveParameter]
        array $row,
        string $origin,
        bool $historical,
        bool $aggregationRequired,
    ): PaperMarketEvent {
        $instrumentId = $row['instId'] ?? null;
        if (!\is_string($instrumentId)) {
            throw new \InvalidArgumentException('okx_paper_instrument_not_allowed');
        }
        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        $tradeId = $this->unsignedIntegerString($row['tradeId'] ?? null);
        $price = $this->decimal($row['px'] ?? null);
        $size = $this->decimal($row['sz'] ?? null);
        $side = $row['side'] ?? null;
        if (!\is_string($side) || !\in_array($side, ['buy', 'sell'], true)) {
            throw new \InvalidArgumentException('okx_paper_trade_side_invalid');
        }
        $source = $this->unsignedIntegerString($row['source'] ?? null);
        $timestampValue = $this->unsignedIntegerString($row['ts'] ?? null);
        $exchangeTimestamp = $this->timestamp($timestampValue);

        $aggregateCount = null;
        if (array_key_exists('count', $row)) {
            $aggregateCount = $this->unsignedIntegerString($row['count']);
            if ($aggregateCount === '0') {
                throw new \InvalidArgumentException('okx_paper_unsigned_integer_invalid');
            }
        } elseif ($aggregationRequired) {
            throw new \InvalidArgumentException('okx_paper_unsigned_integer_invalid');
        }

        $sourceSequence = null;
        if (array_key_exists('seqId', $row)) {
            $sourceSequence = $this->sourceSequence($row['seqId']);
        } elseif ($aggregationRequired) {
            throw new \InvalidArgumentException('okx_paper_source_sequence_invalid');
        }

        return $this->event(
            symbol: $symbol,
            channel: PaperMarketDataChannel::PUBLIC_TRADE,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $historical ? $exchangeTimestamp : $this->clock->now(),
            naturalIdentity: 'trade|' . $tradeId,
            payload: [
                'native_symbol' => $instrumentId,
                'trade_id' => $tradeId,
                'price' => $price,
                'size_contracts' => $size,
                'taker_side' => $side,
                'aggregate_count' => $aggregateCount,
                'source' => $source,
                'source_seq_id' => $sourceSequence,
                'origin' => $origin,
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(
        string $symbol,
        PaperMarketDataChannel $channel,
        \DateTimeImmutable $exchangeTimestamp,
        \DateTimeImmutable $receivedTimestamp,
        string $naturalIdentity,
        #[\SensitiveParameter]
        array $payload,
    ): PaperMarketEvent {
        $scope = implode('/', [PaperMarketDataVenue::OKX->value, $symbol, $channel->value]);
        $assignmentDigest = OkxPaperSourceOrdinal::assignmentDigest(
            $naturalIdentity,
            $exchangeTimestamp,
            $payload,
        );
        $assignment = $this->ordinals->preview(
            $scope,
            $naturalIdentity,
            $assignmentDigest,
        );
        if ($assignment['replayed']) {
            return $assignment['event']
                ?? throw new \LogicException('okx_paper_ordinal_replay_state_invalid');
        }

        $event = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: $channel,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $receivedTimestamp,
            sequence: $assignment['sequence'],
            payload: $payload,
        );
        $this->ordinals->commit($scope, $naturalIdentity, $assignmentDigest, $event);

        return $event;
    }

    /** @return array{PaperMarketDataChannel, string} */
    private function timeframe(string $bar): array
    {
        return match ($bar) {
            '1m' => [PaperMarketDataChannel::CANDLE_1M, '1m'],
            '5m' => [PaperMarketDataChannel::CANDLE_5M, '5m'],
            '15m' => [PaperMarketDataChannel::CANDLE_15M, '15m'],
            '1H' => [PaperMarketDataChannel::CANDLE_1H, '1h'],
            default => throw new \InvalidArgumentException('okx_paper_timeframe_not_allowed'),
        };
    }

    private function webSocketBar(string $channel): string
    {
        return match ($channel) {
            'candle1m' => '1m',
            'candle5m' => '5m',
            'candle15m' => '15m',
            'candle1H' => '1H',
            default => throw new \InvalidArgumentException('okx_paper_timeframe_not_allowed'),
        };
    }

    private function receiptTimestamp(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    private function decimal(#[\SensitiveParameter] mixed $value): string
    {
        if (!\is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('okx_paper_decimal_invalid');
        }

        return $value;
    }

    private function unsignedIntegerString(#[\SensitiveParameter] mixed $value): string
    {
        if (!\is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('okx_paper_unsigned_integer_invalid');
        }

        return $value;
    }

    private function sourceSequence(#[\SensitiveParameter] mixed $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (!\is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('okx_paper_source_sequence_invalid');
        }

        return $value;
    }

    private function timestamp(#[\SensitiveParameter] mixed $value): \DateTimeImmutable
    {
        $milliseconds = $this->unsignedIntegerString($value);
        if (\strlen($milliseconds) !== 13) {
            throw new \InvalidArgumentException('okx_paper_timestamp_invalid');
        }

        $timestamp = \DateTimeImmutable::createFromFormat(
            '!U.u',
            substr($milliseconds, 0, 10) . '.' . substr($milliseconds, 10) . '000',
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        ) {
            throw new \InvalidArgumentException('okx_paper_timestamp_invalid');
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

}
