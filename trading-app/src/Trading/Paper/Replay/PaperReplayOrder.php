<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;

/**
 * Replay order and observation instant of the events of a verified dataset.
 *
 * - Exchange time (the default): events are ordered by exchange timestamp, channel and
 *   sequence, and the replay clock observes each one at max(received, exchange).
 * - Candle availability (Paper execution replay, #132 decision a): a confirmed candle exists
 *   at its close (OKX: open + duration; Hyperliquid: inclusive close + 1 ms). It is ordered and
 *   observed at that instant, never before the books of its own interval. Every other event
 *   keeps its exchange-time position and its max(received, exchange) observation. At one
 *   instant, the candles of the cell's trigger channel come after every other event, so every
 *   candle that closes at that instant is known when the trigger is evaluated.
 */
final readonly class PaperReplayOrder
{
    private function __construct(
        public bool $candlesAtClose,
        public ?PaperMarketDataChannel $triggerChannel,
    ) {
    }

    public static function exchangeTime(): self
    {
        return new self(false, null);
    }

    /** @param PaperMarketDataChannel|null $triggerChannel null when no native candle triggers the cell */
    public static function candleAvailability(?PaperMarketDataChannel $triggerChannel): self
    {
        if ($triggerChannel !== null && self::candleSeconds($triggerChannel) === null) {
            throw new \InvalidArgumentException('paper_replay_trigger_channel_invalid');
        }

        return new self(true, $triggerChannel);
    }

    /** Position of the event on the replay time axis. */
    public function orderTimestamp(PaperMarketEvent $event): \DateTimeImmutable
    {
        return ($this->candlesAtClose ? self::candleClose($event) : null) ?? $event->exchangeTimestamp;
    }

    /** Instant at which the replay clock observes the event. */
    public function observationTimestamp(PaperMarketEvent $event): \DateTimeImmutable
    {
        return ($this->candlesAtClose ? self::candleClose($event) : null) ?? self::receivedOrExchange($event);
    }

    public function isTrigger(PaperMarketEvent $event): bool
    {
        return $this->candlesAtClose && $this->triggerChannel === $event->channel;
    }

    /**
     * Instant from which a replayed event may be used: the close of a candle, otherwise
     * max(received, exchange). It never exceeds the replay clock once the event is consumed,
     * in either order (a confirmed candle is never received before its close).
     */
    public static function availableAt(PaperMarketEvent $event): \DateTimeImmutable
    {
        return self::candleClose($event) ?? self::receivedOrExchange($event);
    }

    /** Close of a candle event; null for every other channel. */
    public static function candleClose(PaperMarketEvent $event): ?\DateTimeImmutable
    {
        $seconds = self::candleSeconds($event->channel);
        if ($seconds === null) {
            return null;
        }

        return match ($event->sourceVenue) {
            PaperMarketDataVenue::OKX => $event->exchangeTimestamp->modify('+' . $seconds . ' seconds'),
            PaperMarketDataVenue::HYPERLIQUID => $event->exchangeTimestamp->modify('+1 millisecond'),
        };
    }

    private static function receivedOrExchange(PaperMarketEvent $event): \DateTimeImmutable
    {
        return $event->receivedTimestamp > $event->exchangeTimestamp
            ? $event->receivedTimestamp
            : $event->exchangeTimestamp;
    }

    private static function candleSeconds(PaperMarketDataChannel $channel): ?int
    {
        return match ($channel) {
            PaperMarketDataChannel::CANDLE_1M => 60,
            PaperMarketDataChannel::CANDLE_5M => 300,
            PaperMarketDataChannel::CANDLE_15M => 900,
            PaperMarketDataChannel::CANDLE_1H => 3600,
            default => null,
        };
    }
}
