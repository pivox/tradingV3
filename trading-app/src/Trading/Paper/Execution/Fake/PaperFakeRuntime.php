<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;

final readonly class PaperFakeRuntime
{
    public const CANDLE_CLOSE_SPREAD_MODEL_VERSION = 'paper-candle-close-spread-v1';
    private const CANDLE_CLOSE_SPREAD_BPS = 2.0;

    public FakeExchangeAdapter $adapter;

    public function __construct(
        public PaperExecutionCell $cell,
        public string $statePath,
        public FakeExchangeStateStore $stateStore,
        private FakeExchangeOrderBook $orderBook,
        private FakeExchangeMatchingEngine $matchingEngine,
        ExchangeAdapterInterface $adapter,
        private ?PaperCanonicalFakeInstrumentRegistry $canonicalInstruments = null,
    ) {
        if ($adapter->exchange() !== Exchange::FAKE
            || $adapter->marketType() !== MarketType::PERPETUAL
            || !$adapter instanceof FakeExchangeAdapter
        ) {
            throw new \InvalidArgumentException('paper_execution_exchange_must_be_fake');
        }
        if (!$adapter->isBackedByStateStore($stateStore)) {
            throw new \InvalidArgumentException('paper_fake_runtime_state_mismatch');
        }
        $this->adapter = $adapter;
    }

    public function bindCanonicalInstrument(CanonicalOrderPlan $plan): string
    {
        if (!$this->cell->isModern() || !$this->canonicalInstruments instanceof PaperCanonicalFakeInstrumentRegistry) {
            throw new \LogicException('paper_canonical_fake_instrument_registry_unavailable');
        }

        return $this->canonicalInstruments->bind($plan);
    }

    public function applyMarketEvent(PaperMarketEvent $event): void
    {
        if ($event->sourceNetwork !== $this->cell->network || $event->sourceVenue !== $this->cell->marketDataVenue) {
            throw new \InvalidArgumentException('paper_fake_market_provenance_mismatch');
        }

        if ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK) {
            $bid = $this->positivePrice($event->payload['bid_price'] ?? null);
            $ask = $this->positivePrice($event->payload['ask_price'] ?? null);
            if ($bid >= $ask) {
                throw new \InvalidArgumentException('paper_fake_market_book_invalid');
            }
            $this->stateStore->setMarkPrice($event->symbol, (string) (($bid + $ask) / 2.0));
            $this->stateStore->setOrderBookTop($event->symbol, $bid, $ask);
        } elseif (in_array($event->channel, [
            PaperMarketDataChannel::CANDLE_1M,
            PaperMarketDataChannel::CANDLE_5M,
            PaperMarketDataChannel::CANDLE_15M,
            PaperMarketDataChannel::CANDLE_1H,
        ], true)) {
            // Deliberately prudent: never infer an intrabar path from OHLC. Only the observed close moves the book.
            $this->orderBook->movePrice($event->symbol, $this->positivePrice($event->payload['close'] ?? null), self::CANDLE_CLOSE_SPREAD_BPS);
        } else {
            return;
        }

        $this->matchingEngine->matchOpenOrders($event->symbol);
    }

    private function positivePrice(mixed $value): float
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)) {
            throw new \InvalidArgumentException('paper_fake_market_price_invalid');
        }
        $price = (float) $value;
        if (!is_finite($price) || $price <= 0.0) {
            throw new \InvalidArgumentException('paper_fake_market_price_invalid');
        }

        return $price;
    }

    public function eventCursor(): int
    {
        return count($this->stateStore->events());
    }

    /** @return list<FakeExchangeEvent> */
    public function eventsSince(int $cursor): array
    {
        $events = $this->stateStore->events();
        if ($cursor < 0 || $cursor > count($events)) {
            throw new \InvalidArgumentException('paper_fake_event_cursor_invalid');
        }

        return array_values(array_slice($events, $cursor));
    }
}
