<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Dto\ExchangeOrderDto;

final readonly class FakeExchangeScenarioService
{
    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private FakeExchangeOrderBook $orderBook,
        private FakeExchangeMatchingEngine $matchingEngine,
    ) {
    }

    public function reset(): void
    {
        $this->stateStore->reset();
    }

    /**
     * @return array{book: SymbolBidAskDto, matched_orders: ExchangeOrderDto[]}
     */
    public function movePrice(string $symbol, float $midPrice, float $spreadBps = 2.0): array
    {
        $book = $this->orderBook->movePrice($symbol, $midPrice, $spreadBps);

        return [
            'book' => $book,
            'matched_orders' => $this->matchingEngine->matchOpenOrders($symbol),
        ];
    }

    public function fillOrder(string $exchangeOrderId, ?float $quantity = null, ?float $price = null): ?ExchangeOrderDto
    {
        return $this->matchingEngine->fillOrder($exchangeOrderId, $quantity, $price);
    }

    public function rejectNextProtectionOrder(): void
    {
        $this->stateStore->rejectNextProtectionOrder();
    }

    /**
     * @return FakeExchangeEvent[]
     */
    public function events(?string $type = null): array
    {
        return $this->stateStore->events($type);
    }
}
