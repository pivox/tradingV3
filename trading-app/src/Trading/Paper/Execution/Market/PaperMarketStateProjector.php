<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Market;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final class PaperMarketStateProjector
{
    /** @var array<string, string> */
    private array $appliedEvents = [];

    /** @var array<string, array{bid: string, ask: string}> */
    private array $books = [];

    /** @var list<PaperMarketEvent> */
    private array $eventLog = [];

    /** @var array<string, int> */
    private array $eventLogCounts = [];

    public function __construct(private readonly PaperKlineProvider $klines)
    {
    }

    public function apply(
        PaperMarketEvent $event,
        bool $projectLegacyKlines = true,
        ?string $modernModeId = null,
        bool $compactModern = true,
    ): void
    {
        $existingHash = $this->appliedEvents[$event->eventId] ?? null;
        if ($existingHash !== null) {
            if (!hash_equals($existingHash, $event->payloadHash)) {
                throw new \LogicException('market_event_identity_conflict');
            }

            return;
        }

        $timeframe = $this->timeframe($event->channel);
        if ($timeframe instanceof Timeframe) {
            $this->applyCandle($event, $timeframe, $projectLegacyKlines);
        } elseif ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK) {
            $this->applyBook($event);
        }

        $this->appliedEvents[$event->eventId] = $event->payloadHash;
        $this->eventLog[] = $event;
        $retentionKey = $event->symbol . '/' . $event->channel->value;
        $this->eventLogCounts[$retentionKey] = ($this->eventLogCounts[$retentionKey] ?? 0) + 1;
        if ($modernModeId !== null && $compactModern) {
            $limit = $this->modernRetentionLimit($modernModeId, $event->channel);
            if ($this->eventLogCounts[$retentionKey] > $limit + 32) {
                $this->compactModern($modernModeId);
            }
        }
    }

    /** @param iterable<PaperMarketEvent> $events */
    public function restore(
        iterable $events,
        bool $projectLegacyKlines = true,
        ?string $modernModeId = null,
    ): void
    {
        $this->klines->clear();
        $this->books = [];
        $this->appliedEvents = [];
        $this->eventLog = [];
        $this->eventLogCounts = [];
        foreach ($events as $event) {
            $this->apply($event, $projectLegacyKlines, $modernModeId);
        }
        if ($modernModeId !== null) {
            $this->compactModern($modernModeId);
        }
    }

    public function rollbackLastModern(PaperMarketEvent $event): void
    {
        $lastKey = array_key_last($this->eventLog);
        $last = $lastKey === null ? null : $this->eventLog[$lastKey];
        if (!$last instanceof PaperMarketEvent
            || !hash_equals($last->eventId, $event->eventId)
            || !hash_equals($last->payloadHash, $event->payloadHash)
        ) {
            throw new \LogicException('paper_market_modern_rollback_invalid');
        }

        array_pop($this->eventLog);
        unset($this->appliedEvents[$event->eventId]);
        $retentionKey = $event->symbol . '/' . $event->channel->value;
        $this->eventLogCounts[$retentionKey] = ($this->eventLogCounts[$retentionKey] ?? 1) - 1;
        if ($event->channel !== PaperMarketDataChannel::TOP_OF_BOOK) {
            return;
        }

        unset($this->books[$event->symbol]);
        for ($index = count($this->eventLog) - 1; $index >= 0; --$index) {
            $candidate = $this->eventLog[$index];
            if ($candidate->symbol === $event->symbol
                && $candidate->channel === PaperMarketDataChannel::TOP_OF_BOOK
            ) {
                $this->applyBook($candidate);

                return;
            }
        }
    }

    /** @return list<PaperMarketEvent> */
    public function events(): array
    {
        return $this->eventLog;
    }

    /** @return array{bid: string, ask: string}|null */
    public function topOfBook(string $symbol): ?array
    {
        return $this->books[$symbol] ?? null;
    }

    private function compactModern(string $modeId): void
    {
        if (!in_array($modeId, ['day_trading', 'scalping', 'micro_scalping'], true)) {
            throw new \LogicException('paper_market_modern_mode_invalid');
        }
        $counts = [];
        $retained = [];
        for ($index = count($this->eventLog) - 1; $index >= 0; --$index) {
            $event = $this->eventLog[$index];
            $key = $event->symbol . '/' . $event->channel->value;
            $limit = $this->modernRetentionLimit($modeId, $event->channel);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if ($counts[$key] <= $limit) {
                $retained[] = $event;
            }
        }
        $this->eventLog = array_reverse($retained);
        $this->eventLogCounts = [];
        foreach ($this->eventLog as $event) {
            $key = $event->symbol . '/' . $event->channel->value;
            $this->eventLogCounts[$key] = ($this->eventLogCounts[$key] ?? 0) + 1;
        }
    }

    private function modernRetentionLimit(string $modeId, PaperMarketDataChannel $channel): int
    {
        if ($channel === PaperMarketDataChannel::CANDLE_1H) {
            return $modeId === 'day_trading' ? 1003 : ($modeId === 'scalping' ? 250 : 1);
        }
        if (in_array($channel, [
            PaperMarketDataChannel::CANDLE_1M,
            PaperMarketDataChannel::CANDLE_5M,
            PaperMarketDataChannel::CANDLE_15M,
        ], true)) {
            return $modeId === 'micro_scalping' && $channel === PaperMarketDataChannel::CANDLE_15M ? 1 : 250;
        }
        if (in_array($channel, [PaperMarketDataChannel::TOP_OF_BOOK, PaperMarketDataChannel::PUBLIC_TRADE], true)) {
            return $modeId === 'micro_scalping' ? 2048 : 1;
        }

        return 8;
    }

    private function applyCandle(PaperMarketEvent $event, Timeframe $timeframe, bool $projectLegacyKlines): void
    {
        $payload = $event->payload;
        if (($payload['confirmed'] ?? null) !== true) {
            throw new \LogicException('paper_market_candle_unconfirmed');
        }
        $declaredInterval = $payload['interval'] ?? $payload['bar'] ?? null;
        if (!is_string($declaredInterval) || strtolower($declaredInterval) !== $timeframe->value) {
            throw new \LogicException('paper_market_candle_invalid');
        }

        try {
            $open = $this->positiveDecimal($payload['open'] ?? null);
            $high = $this->positiveDecimal($payload['high'] ?? null);
            $low = $this->positiveDecimal($payload['low'] ?? null);
            $close = $this->positiveDecimal($payload['close'] ?? null);
            $volume = $this->nonNegativeDecimal($payload['volume'] ?? $payload['volume_base'] ?? null);
        } catch (\InvalidArgumentException|MathException) {
            throw new \LogicException('paper_market_candle_invalid');
        }
        if ($high->isLessThan($open) || $high->isLessThan($close)
            || $low->isGreaterThan($open) || $low->isGreaterThan($close)
        ) {
            throw new \LogicException('paper_market_candle_invalid');
        }

        $openTime = $this->openTime($payload['start_time'] ?? null, $event->exchangeTimestamp, $timeframe);
        if (!$projectLegacyKlines) {
            return;
        }
        $this->klines->put(new KlineDto(
            symbol: $event->symbol,
            timeframe: $timeframe,
            openTime: $openTime,
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            volume: $volume,
            source: 'paper',
        ));
    }

    private function applyBook(PaperMarketEvent $event): void
    {
        try {
            $bid = $this->positiveDecimal($event->payload['bid_price'] ?? null);
            $ask = $this->positiveDecimal($event->payload['ask_price'] ?? null);
        } catch (\InvalidArgumentException|MathException) {
            throw new \LogicException('paper_market_top_of_book_invalid');
        }
        if (!$bid->isLessThan($ask)) {
            throw new \LogicException('paper_market_top_of_book_invalid');
        }

        $this->books[$event->symbol] = ['bid' => (string) $bid, 'ask' => (string) $ask];
    }

    private function timeframe(PaperMarketDataChannel $channel): ?Timeframe
    {
        return match ($channel) {
            PaperMarketDataChannel::CANDLE_1M => Timeframe::TF_1M,
            PaperMarketDataChannel::CANDLE_5M => Timeframe::TF_5M,
            PaperMarketDataChannel::CANDLE_15M => Timeframe::TF_15M,
            PaperMarketDataChannel::CANDLE_1H => Timeframe::TF_1H,
            default => null,
        };
    }

    private function openTime(mixed $startTime, \DateTimeImmutable $fallback, Timeframe $timeframe): \DateTimeImmutable
    {
        if ($startTime === null) {
            $timestamp = (int) $fallback->format('U');
        } elseif (is_string($startTime) && preg_match('/\A[0-9]{13}\z/D', $startTime) === 1) {
            $timestamp = intdiv((int) $startTime, 1000);
        } else {
            throw new \LogicException('paper_market_candle_invalid');
        }
        if ($timestamp < 0 || $timestamp % $timeframe->getStepInSeconds() !== 0) {
            throw new \LogicException('paper_market_candle_invalid');
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function positiveDecimal(mixed $value): BigDecimal
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException();
        }
        $decimal = BigDecimal::of($value);
        if (!$decimal->isPositive()) {
            throw new \InvalidArgumentException();
        }

        return $decimal;
    }

    private function nonNegativeDecimal(mixed $value): BigDecimal
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException();
        }
        $decimal = BigDecimal::of($value);
        if ($decimal->isNegative()) {
            throw new \InvalidArgumentException();
        }

        return $decimal;
    }
}
