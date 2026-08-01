<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;

final readonly class PaperFakeRuntime
{
    public FakeExchangeAdapter $adapter;

    public function __construct(
        public PaperExecutionCell $cell,
        public string $statePath,
        public FakeExchangeStateStore $stateStore,
        ExchangeAdapterInterface $adapter,
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
