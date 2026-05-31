<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Registry;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Registry\ExchangeAdapterNotFoundException;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeAdapterRegistry::class)]
#[CoversClass(ExchangeAdapterNotFoundException::class)]
final class ExchangeAdapterRegistryTest extends TestCase
{
    public function testReturnsAdapterForExchangeAndMarketType(): void
    {
        $adapter = $this->createMock(ExchangeAdapterInterface::class);
        $adapter->method('exchange')->willReturn(Exchange::BITMART);
        $adapter->method('marketType')->willReturn(MarketType::PERPETUAL);

        $registry = new ExchangeAdapterRegistry([$adapter]);

        self::assertSame($adapter, $registry->get(Exchange::BITMART, MarketType::PERPETUAL));
    }

    public function testThrowsWhenAdapterIsMissing(): void
    {
        $registry = new ExchangeAdapterRegistry([]);

        $this->expectException(ExchangeAdapterNotFoundException::class);
        $this->expectExceptionMessage('No exchange adapter registered');

        $registry->get(Exchange::BITMART, MarketType::PERPETUAL);
    }
}
