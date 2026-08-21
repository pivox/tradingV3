<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Backtesting\PaperBacktestDatasetAdapter;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;

final readonly class PaperCanonicalOrderBookSource
{
    public function __construct(
        private PaperMarketStateProjector $market,
        private PaperReplayClock $clock,
        private PaperBacktestDatasetAdapter $adapter = new PaperBacktestDatasetAdapter(),
    ) {
    }

    public function snapshotFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
    ): ?CanonicalOrderBookSnapshot {
        if (!$cell->isModern()) {
            throw new \LogicException('paper_canonical_strategy_cell_identity_missing');
        }
        if ($trigger->sourceNetwork !== $cell->network
            || $trigger->sourceVenue !== $cell->marketDataVenue
        ) {
            throw new \LogicException('paper_canonical_strategy_market_scope_mismatch');
        }

        $now = $this->clock->now();
        if ($trigger->exchangeTimestamp > $now || $trigger->receivedTimestamp > $now) {
            return null;
        }
        $projectedEvents = array_values(array_filter(
            $this->market->events(),
            static fn (PaperMarketEvent $event): bool =>
                $event->sourceNetwork === $cell->network
                && $event->sourceVenue === $cell->marketDataVenue
                && $event->symbol === $trigger->symbol,
        ));
        $latest = $projectedEvents === [] ? null : $projectedEvents[array_key_last($projectedEvents)];
        if (!$latest instanceof PaperMarketEvent
            || !hash_equals($latest->eventId, $trigger->eventId)
            || !hash_equals(
                CanonicalJson::encode($latest->toArray()),
                CanonicalJson::encode($trigger->toArray()),
            )
        ) {
            throw new \LogicException('paper_canonical_strategy_trigger_not_current');
        }

        $bookEvents = array_values(array_filter(
            $projectedEvents,
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::TOP_OF_BOOK
                && $event->exchangeTimestamp <= $now
                && $event->receivedTimestamp <= $now,
        ));
        if ($bookEvents === []) {
            return null;
        }

        $checksum = hash_init('sha256');
        foreach ($bookEvents as $event) {
            hash_update($checksum, CanonicalJson::encode($event->toArray()) . "\n");
        }
        $books = $this->adapter->adaptMicrostructureEvents(
            $bookEvents,
            'sha256:' . hash_final($checksum),
        )['books'];
        $book = $books === [] ? null : $books[array_key_last($books)];
        if ($book === null
            || $book->sourceNetwork !== $cell->network->value
            || $book->marketDataVenue !== $cell->marketDataVenue->value
            || $book->symbol !== $trigger->symbol
        ) {
            throw new \LogicException('paper_canonical_order_book_identity_mismatch');
        }

        $bestBid = (float) $book->bidPrice;
        $bestAsk = (float) $book->askPrice;

        return new CanonicalOrderBookSnapshot(
            exchange: $book->marketDataVenue,
            environment: $book->sourceNetwork,
            symbol: $book->symbol,
            marketType: 'perpetual',
            source: 'order_book',
            bestBid: $bestBid,
            bestAsk: $bestAsk,
            spreadBps: 10_000.0 * ($bestAsk - $bestBid) / (($bestAsk + $bestBid) / 2.0),
            observedAt: new \DateTimeImmutable($book->happenedAt),
            inputHash: 'sha256:' . $book->sourceRecordId,
        );
    }
}
