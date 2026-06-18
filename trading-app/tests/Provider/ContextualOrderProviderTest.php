<?php

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\ContextualOrderProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Provider\Context\ExchangeContext;
use App\Provider\ContextualOrderProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ContextualOrderProviderTest extends TestCase
{
    public function testPlaceOrderInjectsContextIntoOptions(): void
    {
        $inner = new class implements ContextualOrderProviderInterface {
            /** @var array<string,mixed> */
            public array $lastOptions = [];
            public ?ExchangeContext $lastContext = null;

            public function placeOrder(
                string $symbol,
                OrderSide $side,
                OrderType $type,
                float $quantity,
                ?float $price = null,
                ?float $stopPrice = null,
                array $options = []
            ): ?OrderDto {
                $this->lastOptions = $options;
                return null;
            }

            public function cancelOrder(string $symbol, string $orderId, ?ExchangeContext $context = null): bool
            {
                $this->lastContext = $context;
                return true;
            }

            public function getOrder(string $symbol, string $orderId, ?ExchangeContext $context = null): ?OrderDto
            {
                $this->lastContext = $context;
                return null;
            }

            public function getOpenOrders(?string $symbol = null, ?ExchangeContext $context = null): array
            {
                $this->lastContext = $context;
                return [];
            }

            public function getOpenOrdersOrFail(?string $symbol = null): array
            {
                return [];
            }

            public function getOrderHistory(string $symbol, int $limit = 100, ?ExchangeContext $context = null): array
            {
                $this->lastContext = $context;
                return [];
            }

            public function cancelAllOrders(string $symbol): bool
            {
                return true;
            }

            public function getOrderBookTop(string $symbol): SymbolBidAskDto
            {
                return new SymbolBidAskDto($symbol, 1.0, 1.1, new \DateTimeImmutable());
            }

            public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
            {
                return true;
            }
        };
        $spot = new ExchangeContext(Exchange::BITMART, MarketType::SPOT);
        $provider = new ContextualOrderProvider($inner, $spot);

        self::assertSame($inner, $provider->innerOrderProvider());

        $provider->placeOrder('BTCUSDT', OrderSide::BUY, OrderType::LIMIT, 1.0, options: ['client_order_id' => 'ctx-test']);

        self::assertSame('bitmart', $inner->lastOptions['exchange']);
        self::assertSame('spot', $inner->lastOptions['market_type']);
        self::assertSame('ctx-test', $inner->lastOptions['client_order_id']);

        $provider->getOrder('BTCUSDT', '123');
        self::assertTrue($inner->lastContext?->equals($spot));
    }
}
