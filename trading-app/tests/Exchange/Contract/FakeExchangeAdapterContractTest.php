<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Contract;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeExchangeAdapter::class)]
#[CoversClass(FakeExchangeMatchingEngine::class)]
#[CoversClass(FakeExchangeOrderBook::class)]
#[CoversClass(FakeExchangeScenarioService::class)]
#[CoversClass(FakeExchangeStateStore::class)]
final class FakeExchangeAdapterContractTest extends ExchangeAdapterContractTestCase
{
    private FakeExchangeAdapter $adapter;

    private FakeExchangeScenarioService $scenario;

    protected function setUp(): void
    {
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());
        $this->adapter = new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
        $this->scenario = new FakeExchangeScenarioService($state, $book, $engine);
    }

    protected function adapter(): ExchangeAdapterInterface
    {
        return $this->adapter;
    }

    protected function exchange(): Exchange
    {
        return Exchange::FAKE;
    }

    protected function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    protected function marketOrdersFillImmediately(): bool
    {
        return true;
    }

    protected function supportsLocalFillHook(): bool
    {
        return true;
    }

    protected function fillOrderForContract(string $exchangeOrderId, ?float $quantity = null, ?float $price = null): ?ExchangeOrderDto
    {
        return $this->scenario->fillOrder($exchangeOrderId, $quantity, $price);
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        };
    }
}
