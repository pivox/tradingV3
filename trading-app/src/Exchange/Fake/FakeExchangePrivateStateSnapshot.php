<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;

final readonly class FakeExchangePrivateStateSnapshot
{
    /**
     * @param list<ExchangeBalanceDto> $balances
     * @param list<ExchangeOrderDto> $orders
     * @param list<ExchangePositionDto> $positions
     * @param array<string,array{bid:float,ask:float}> $orderBooks
     * @param array<string,string> $markPrices
     * @param list<FakeExchangeEvent> $events
     */
    public function __construct(
        public int $stateRevision,
        public array $balances,
        public array $orders,
        public array $positions,
        public array $orderBooks,
        public array $markPrices,
        public array $events,
    ) {
        if ($stateRevision < 1 || $stateRevision === PHP_INT_MAX) {
            throw new \InvalidArgumentException('fake_exchange_private_state_snapshot_invalid');
        }
        $this->assertInstances($balances, ExchangeBalanceDto::class);
        $this->assertInstances($orders, ExchangeOrderDto::class);
        $this->assertInstances($positions, ExchangePositionDto::class);
        $this->assertInstances($events, FakeExchangeEvent::class);
    }

    /** @param array<mixed> $values */
    private function assertInstances(array $values, string $class): void
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException('fake_exchange_private_state_snapshot_invalid');
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                throw new \InvalidArgumentException('fake_exchange_private_state_snapshot_invalid');
            }
        }
    }
}
