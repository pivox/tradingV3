<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Contract\Provider\Dto\SymbolBidAskDto;

final readonly class FakeExchangeOrderBook
{
    public function __construct(
        private FakeExchangeStateStore $stateStore,
    ) {
    }

    public function top(string $symbol): SymbolBidAskDto
    {
        $top = $this->stateStore->getOrderBookTop($symbol);

        return new SymbolBidAskDto(
            symbol: strtoupper($symbol),
            bid: $top['bid'],
            ask: $top['ask'],
            timestamp: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function movePrice(string $symbol, float $midPrice, float $spreadBps = 2.0): SymbolBidAskDto
    {
        if ($midPrice <= 0.0) {
            throw new \InvalidArgumentException('midPrice must be positive');
        }
        if ($spreadBps < 0.0) {
            throw new \InvalidArgumentException('spreadBps cannot be negative');
        }

        $symbol = strtoupper($symbol);
        $halfSpread = max($midPrice * ($spreadBps / 10000.0) / 2.0, 0.00000001);
        $bid = $midPrice - $halfSpread;
        $ask = $midPrice + $halfSpread;
        if ($bid <= 0.0 || $ask <= 0.0 || $bid >= $ask) {
            throw new \InvalidArgumentException('fake order book requires positive bid < ask');
        }

        $this->stateStore->setMarkPrice(
            $symbol,
            json_encode($midPrice, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        );
        $this->stateStore->setOrderBookTop($symbol, $bid, $ask);

        return $this->top($symbol);
    }
}
