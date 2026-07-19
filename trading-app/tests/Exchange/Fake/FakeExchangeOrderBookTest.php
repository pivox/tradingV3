<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\TestCase;

final class FakeExchangeOrderBookTest extends TestCase
{
    public function testInvalidSpreadDoesNotPersistMarkOrOrderBookTop(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake-order-book-');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $state->setMarkPrice('BTCUSDT', '25000');
            $state->setOrderBookTop('BTCUSDT', 24999.0, 25001.0);
            $orderBook = new FakeExchangeOrderBook($state);

            try {
                $orderBook->movePrice('BTCUSDT', 1.0, 20000.0);
                self::fail('A non-positive bid must reject the price move.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('fake order book requires positive bid < ask', $exception->getMessage());
            }

            $restored = new FakeExchangeStateStore($stateFile);
            self::assertSame('25000', $restored->getMarkPrice('BTCUSDT'));
            self::assertSame(['bid' => 24999.0, 'ask' => 25001.0], $restored->getOrderBookTop('BTCUSDT'));
            self::assertSame('25000', $state->getMarkPrice('BTCUSDT'));
            self::assertSame(['bid' => 24999.0, 'ask' => 25001.0], $state->getOrderBookTop('BTCUSDT'));
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }
}
