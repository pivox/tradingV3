<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Backtesting\NormalizedBacktestCandle;
use App\Trading\Paper\Backtesting\PaperBacktestDatasetAdapter;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;

final readonly class PaperCanonicalIndicatorWindowSource
{
    /** @var list<string> */
    private const OUTPUT_TIMEFRAMES = ['1m', '5m', '15m', '1h', '4h'];

    /** @var list<string> */
    private const NATIVE_TIMEFRAMES = ['1m', '5m', '15m', '1h'];

    public function __construct(
        private PaperMarketStateProjector $market,
        private PaperReplayClock $clock,
        private PaperBacktestDatasetAdapter $adapter = new PaperBacktestDatasetAdapter(),
    ) {
    }

    /**
     * @param list<string> $requestedTimeframes
     * @return array<string, list<array<string, bool|string>>>|null
     */
    public function windowsFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        array $requestedTimeframes,
    ): ?array {
        if (!$cell->isModern()) {
            throw new \LogicException('paper_canonical_strategy_cell_identity_missing');
        }
        if ($trigger->sourceNetwork !== $cell->network
            || $trigger->sourceVenue !== $cell->marketDataVenue
        ) {
            throw new \LogicException('paper_canonical_strategy_market_scope_mismatch');
        }
        $expectedRequested = array_values(array_filter(
            self::OUTPUT_TIMEFRAMES,
            static fn (string $timeframe): bool => in_array($timeframe, $requestedTimeframes, true),
        ));
        if ($requestedTimeframes === [] || $requestedTimeframes !== $expectedRequested) {
            throw new \LogicException('paper_canonical_strategy_indicator_timeframes_invalid');
        }
        $requestedNativeTimeframes = array_values(array_intersect(self::NATIVE_TIMEFRAMES, $requestedTimeframes));
        if (in_array('4h', $requestedTimeframes, true)
            && !in_array('1h', $requestedNativeTimeframes, true)
        ) {
            $requestedNativeTimeframes[] = '1h';
        }

        $now = $this->clock->now();
        if ($trigger->receivedTimestamp > $now) {
            return null;
        }
        $latest = null;
        $eventsByTimeframe = [];
        foreach ($this->market->events() as $event) {
            if ($event->sourceNetwork !== $cell->network
                || $event->sourceVenue !== $cell->marketDataVenue
                || $event->symbol !== $trigger->symbol
            ) {
                continue;
            }
            $latest = $event;
            $eventTimeframe = $this->timeframe($event->channel);
            if ($event->receivedTimestamp <= $now
                && $eventTimeframe !== null
                && in_array($eventTimeframe, $requestedNativeTimeframes, true)
            ) {
                $eventsByTimeframe[$eventTimeframe][] = $event;
            }
        }
        if (!$latest instanceof PaperMarketEvent
            || !hash_equals($latest->eventId, $trigger->eventId)
            || !hash_equals(
                CanonicalJson::encode($latest->toArray()),
                CanonicalJson::encode($trigger->toArray()),
            )
        ) {
            throw new \LogicException('paper_canonical_strategy_trigger_not_current');
        }
        $availableThrough = $now->format('Y-m-d\TH:i:s.u\Z');
        $windows = [];
        $triggerTimeframe = $this->timeframe($trigger->channel);
        $triggerBound = false;
        $fourHourRequested = in_array('4h', $requestedTimeframes, true);
        foreach (self::NATIVE_TIMEFRAMES as $timeframe) {
            $derivedHourlySourceRequired = $timeframe === '1h' && $fourHourRequested;
            if (!in_array($timeframe, $requestedTimeframes, true) && !$derivedHourlySourceRequired) {
                continue;
            }
            $required = $derivedHourlySourceRequired ? 1000 : 250;
            $timeframeEvents = $eventsByTimeframe[$timeframe] ?? [];
            if ($timeframeEvents === []) {
                return null;
            }
            $timeframeEvents = $this->latestCandleEvents(
                $timeframeEvents,
                $required === 1000 ? 1003 : $required,
            );
            $candidates = array_values(array_filter(
                $this->adapter->adaptCandleEvents($timeframeEvents),
                static fn (NormalizedBacktestCandle $candle): bool => $candle->availableAt <= $availableThrough,
            ));
            if (count($candidates) < $required) {
                return null;
            }
            $window = $this->latestCanonicalWindow($candidates, $required);
            if (!$this->isContiguous($window)
                || ($required === 1000 && (int) substr($window[0]->openAt, 11, 2) % 4 !== 0)
            ) {
                throw new \LogicException('paper_canonical_strategy_indicator_window_invalid');
            }
            if ($timeframe === $triggerTimeframe) {
                $last = $window[array_key_last($window)];
                if (!hash_equals($last->sourceRecordId, $trigger->eventId)) {
                    return null;
                }
                $triggerBound = true;
            }
            $windows[$timeframe] = array_map(
                static fn (NormalizedBacktestCandle $candle): array => $candle->toArray(),
                $window,
            );
        }

        return $triggerBound ? $windows : null;
    }

    /**
     * @param list<PaperMarketEvent> $events
     * @return list<PaperMarketEvent>
     */
    private function latestCandleEvents(array $events, int $limit): array
    {
        usort($events, static function (PaperMarketEvent $left, PaperMarketEvent $right): int {
            $comparison = $left->exchangeTimestamp <=> $right->exchangeTimestamp;

            return $comparison !== 0 ? $comparison : $left->eventId <=> $right->eventId;
        });

        return array_slice($events, -$limit);
    }

    /**
     * @param list<NormalizedBacktestCandle> $candidates
     * @return list<NormalizedBacktestCandle>
     */
    private function latestCanonicalWindow(array $candidates, int $required): array
    {
        if ($required !== 1000) {
            return array_slice($candidates, -$required);
        }
        for ($start = count($candidates) - $required; $start >= 0; --$start) {
            if ((int) substr($candidates[$start]->openAt, 11, 2) % 4 === 0) {
                return array_slice($candidates, $start);
            }
        }

        throw new \LogicException('paper_canonical_strategy_indicator_window_invalid');
    }

    /** @param list<NormalizedBacktestCandle> $window */
    private function isContiguous(array $window): bool
    {
        foreach ($window as $index => $candle) {
            if ($index > 0 && $candle->openAt !== $window[$index - 1]->closeAt) {
                return false;
            }
        }

        return true;
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
