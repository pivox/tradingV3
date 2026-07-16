<?php

declare(strict_types=1);

namespace App\Tests\Provider\Fake;

use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeOrderValidator;
use App\Provider\Fake\FakeAccountProvider;
use App\Provider\Fake\FakeContractProvider;
use App\Provider\Fake\FakeOrderProvider;
use Psr\Clock\ClockInterface;

final readonly class FakeProviderFixture
{
    private function __construct(
        public FakeInstrumentCatalog $catalog,
        public FakeExchangeStateStore $state,
        public FakeExchangeAdapter $adapter,
        public FakeContractProvider $contract,
        public FakeAccountProvider $account,
        public FakeOrderProvider $order,
        public ClockInterface $clock,
    ) {
    }

    public static function create(): self
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
        $catalog = new FakeInstrumentCatalog();
        $state = new FakeExchangeStateStore();
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25001.0);
        $state->setOrderBookTop('ETHUSDT', 1799.0, 1801.0);
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine(
            $state,
            $book,
            $clock,
            new FakeOrderValidator($catalog),
            $catalog,
        );
        $adapter = new FakeExchangeAdapter($state, $book, $engine, $clock, $catalog);

        return new self(
            catalog: $catalog,
            state: $state,
            adapter: $adapter,
            contract: new FakeContractProvider($catalog, $state),
            account: new FakeAccountProvider($adapter),
            order: new FakeOrderProvider($adapter),
            clock: $clock,
        );
    }
}
