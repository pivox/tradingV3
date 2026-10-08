<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;

/**
 * Replay order of a verified events file, kept out of the PHP heap.
 *
 * Each validated event is appended, serialized, to a private anonymous spill
 * file; the heap keeps one compact binary key per event whose byte order
 * (SORT_STRING, i.e. strcmp) is exactly the replay comparator order, field by
 * field:
 *
 * - business order: exchange timestamp, channel, sequence (numeric, null last),
 *   event id, input position;
 * - candle availability order (PaperReplayOrder::candleAvailability()): the same
 *   fields, but a candle is placed at its close instead of its exchange timestamp,
 *   and a class byte after the timestamp puts the trigger channel's candles after
 *   every other event of the same instant;
 * - Hyperliquid historical order: exchange timestamp, symbol, interval,
 *   channel, event id, input position.
 *
 * Every key ends with the event id, the record's spill offset (input
 * position), its length and the SHA-256 of the exact serialized record. An
 * event is materialized only when needed, and only from a record with that
 * digest, so replay still yields the very events validated while indexing.
 *
 * @internal
 */
final class PaperReplayEventIndex
{
    private const TIMESTAMP_BYTES = 12;
    private const EVENT_ID_BYTES = 32;
    private const OFFSET_BYTES = 8;
    private const LENGTH_BYTES = 4;
    private const DIGEST_BYTES = 32;
    private const TAIL_BYTES = self::EVENT_ID_BYTES + self::OFFSET_BYTES + self::LENGTH_BYTES + self::DIGEST_BYTES;
    private const MAX_RECORD_BYTES = 0xFFFFFFFF;
    private const NON_NULL_SEQUENCE = "\x00";
    private const NULL_SEQUENCE = "\x01";
    private const OTHER_EVENT_CLASS = "\x00";
    private const TRIGGER_CANDLE_CLASS = "\x01";
    private const SERIALIZE_PRECISION_SETTING = 'serialize_precision';
    private const EXACT_SERIALIZE_PRECISION = '-1';
    private const RECORD_CLASSES = [
        PaperMarketEvent::class,
        PaperMarketDataNetwork::class,
        PaperMarketDataVenue::class,
        PaperMarketDataChannel::class,
        \DateTimeImmutable::class,
        \DateTimeZone::class,
    ];

    /** @var resource|null */
    private $spill;

    private int $spillBytes = 0;

    /** @var array<string, string> channel value => one byte rank in strcmp order */
    private readonly array $channelRanks;

    /** @var list<string> */
    private array $keys = [];

    private bool $sorted = false;

    private readonly PaperReplayOrder $order;

    /**
     * @param resource $spill empty private read/write temporary file, owned by the index
     * @param PaperReplayOrder|null $order business order of a non-historical dataset
     *        (default: exchange time); the Hyperliquid historical order never changes
     */
    public function __construct(
        $spill,
        private readonly bool $hyperliquidHistorical,
        private readonly PaperDatasetRecorderFilesystem $filesystem,
        ?PaperReplayOrder $order = null,
    ) {
        $this->spill = $spill;
        $this->channelRanks = self::channelRanks();
        $this->order = $order ?? PaperReplayOrder::exchangeTime();
    }

    public function count(): int
    {
        return \count($this->keys);
    }

    /** Spills one validated event; $intervalMilliseconds is required only for Hyperliquid historical order. */
    public function append(
        #[\SensitiveParameter] PaperMarketEvent $event,
        ?int $intervalMilliseconds = null,
    ): void {
        $spill = $this->spill;
        if ($this->sorted || $spill === null) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }
        if ($this->hyperliquidHistorical) {
            if ($intervalMilliseconds === null) {
                throw new \LogicException('paper_replay_event_index_invalid');
            }
            $order = self::timestamp($event->exchangeTimestamp)
                . $this->text($event->symbol)
                . self::signedInteger($intervalMilliseconds)
                . $this->channel($event->channel);
        } elseif ($this->order->candlesAtClose) {
            $order = self::timestamp($this->order->orderTimestamp($event))
                . ($this->order->isTrigger($event) ? self::TRIGGER_CANDLE_CLASS : self::OTHER_EVENT_CLASS)
                . $this->channel($event->channel)
                . self::sequence($event->sequence);
        } else {
            $order = self::timestamp($event->exchangeTimestamp)
                . $this->channel($event->channel)
                . self::sequence($event->sequence);
        }
        $eventId = \strlen($event->eventId) === 2 * self::EVENT_ID_BYTES
            && preg_match('/\A[0-9a-f]+\z/D', $event->eventId) === 1
                ? hex2bin($event->eventId)
                : false;
        if ($eventId === false) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }

        $record = self::serialize($event);
        $length = \strlen($record);
        if ($length > self::MAX_RECORD_BYTES) {
            throw new \RuntimeException('paper_replay_spill_failed');
        }
        $offset = $this->spillBytes;
        $written = 0;
        while ($written < $length) {
            $chunk = $this->filesystem->write(
                $spill,
                $written === 0 ? $record : substr($record, $written),
                'paper_replay_spill_write',
            );
            if ($chunk === false || $chunk <= 0) {
                throw new \RuntimeException('paper_replay_spill_failed');
            }
            $written += $chunk;
        }
        $this->spillBytes += $length;

        $this->keys[] = $order . $eventId . pack('J', $offset) . pack('N', $length) . hash('sha256', $record, true);
    }

    public function sort(): void
    {
        if ($this->spill === null || !$this->filesystem->flush($this->spill, 'paper_replay_spill_write')) {
            throw new \RuntimeException('paper_replay_spill_failed');
        }
        sort($this->keys, SORT_STRING);
        $this->sorted = true;
    }

    /** Sorted position of an event id, or null when it is absent. */
    public function positionOf(string $eventId): ?int
    {
        $this->assertSorted();
        if (preg_match('/\A[0-9a-f]{64}\z/D', $eventId) !== 1) {
            return null;
        }
        $needle = (string) hex2bin($eventId);
        foreach ($this->keys as $position => $key) {
            if (hash_equals($needle, substr($key, -self::TAIL_BYTES, self::EVENT_ID_BYTES))) {
                return $position;
            }
        }

        return null;
    }

    /** Materializes the event at a sorted position from its digest-checked record. */
    public function event(int $position): PaperMarketEvent
    {
        $this->assertSorted();
        $key = $this->keys[$position] ?? null;
        $spill = $this->spill;
        if ($key === null || $spill === null) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }
        $location = unpack(
            'Joffset/Nlength',
            substr($key, -self::OFFSET_BYTES - self::LENGTH_BYTES - self::DIGEST_BYTES, self::OFFSET_BYTES + self::LENGTH_BYTES),
        );
        if (!\is_array($location)
            || !\is_int($location['offset'] ?? null)
            || !\is_int($location['length'] ?? null)
            || $location['offset'] < 0
            || $location['length'] < 1
        ) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }
        if (!$this->filesystem->seek($spill, $location['offset'], SEEK_SET, 'paper_replay_spill_read')) {
            throw new \RuntimeException('paper_replay_spill_failed');
        }
        $record = '';
        while (\strlen($record) < $location['length']) {
            $chunk = $this->filesystem->read(
                $spill,
                $location['length'] - \strlen($record),
                'paper_replay_spill_read',
            );
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('paper_replay_spill_failed');
            }
            $record .= $chunk;
        }
        if (!hash_equals(substr($key, -self::DIGEST_BYTES), hash('sha256', $record, true))) {
            throw new \RuntimeException('paper_replay_spill_corrupt');
        }
        try {
            $event = unserialize($record, ['allowed_classes' => self::RECORD_CLASSES]);
        } catch (\Throwable) {
            $event = null;
        }
        if (!$event instanceof PaperMarketEvent) {
            throw new \RuntimeException('paper_replay_spill_corrupt');
        }

        return $event;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->spill !== null) {
            fclose($this->spill);
            $this->spill = null;
        }
        $this->keys = [];
    }

    /** serialize() with the only float precision that round-trips every double exactly. */
    private static function serialize(#[\SensitiveParameter] PaperMarketEvent $event): string
    {
        $precision = ini_get(self::SERIALIZE_PRECISION_SETTING);
        if ($precision === self::EXACT_SERIALIZE_PRECISION) {
            return serialize($event);
        }
        if (!\is_string($precision)
            || ini_set(self::SERIALIZE_PRECISION_SETTING, self::EXACT_SERIALIZE_PRECISION) === false
        ) {
            throw new \RuntimeException('paper_replay_spill_failed');
        }
        try {
            return serialize($event);
        } finally {
            ini_set(self::SERIALIZE_PRECISION_SETTING, $precision);
        }
    }

    private function assertSorted(): void
    {
        if (!$this->sorted) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }
    }

    /** DateTimeImmutable <=> compares seconds since epoch, then microseconds. */
    private static function timestamp(\DateTimeImmutable $timestamp): string
    {
        $key = self::signedInteger($timestamp->getTimestamp()) . pack('N', (int) $timestamp->format('u'));
        if (\strlen($key) !== self::TIMESTAMP_BYTES) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }

        return $key;
    }

    /** Big-endian two's complement with the sign bit flipped: byte order is numeric order. */
    private static function signedInteger(int $value): string
    {
        return pack('J', $value ^ PHP_INT_MIN);
    }

    /**
     * BigInteger order of canonical decimal digits is (digit count, digits); a
     * null sequence sorts after every sequence and ties with another null.
     */
    private static function sequence(?string $sequence): string
    {
        if ($sequence === null) {
            return self::NULL_SEQUENCE;
        }
        if (preg_match('/\A[0-9]{1,255}\z/D', $sequence) !== 1) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }
        $digits = ltrim($sequence, '0');
        if ($digits === '') {
            $digits = '0';
        }

        return self::NON_NULL_SEQUENCE . \chr(\strlen($digits)) . $digits;
    }

    /** strcmp order of NUL-free text, terminated so that a prefix sorts first. */
    private function text(string $value): string
    {
        if (str_contains($value, "\x00")) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }

        return $value . "\x00";
    }

    private function channel(PaperMarketDataChannel $channel): string
    {
        return $this->channelRanks[$channel->value]
            ?? throw new \LogicException('paper_replay_event_index_invalid');
    }

    /** @return array<string, string> */
    private static function channelRanks(): array
    {
        $values = array_map(
            static fn (PaperMarketDataChannel $channel): string => $channel->value,
            PaperMarketDataChannel::cases(),
        );
        sort($values, SORT_STRING);
        if (\count($values) > 256) {
            throw new \LogicException('paper_replay_event_index_invalid');
        }
        $ranks = [];
        foreach ($values as $rank => $value) {
            $ranks[$value] = \chr($rank);
        }

        return $ranks;
    }
}
