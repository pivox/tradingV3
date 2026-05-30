<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Exchange\Adapter\BitmartExchangeAdapter;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\ExchangeProviderBundle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(BitmartExchangeAdapter::class)]
final class BitmartExchangeAdapterTest extends TestCase
{
    public function testMapsLongEntryToLegacyOpenLongSide(): void
    {
        $capturedOptions = null;
        $adapter = $this->createAdapter(function (array $options) use (&$capturedOptions): void {
            $capturedOptions = $options;
        });

        $result = $adapter->placeOrder($this->placeOrderRequest(ExchangePositionSide::LONG, ExchangeOrderSide::BUY));

        self::assertSame(ExchangeOrderStatus::PENDING, $result->status);
        self::assertSame(1, $capturedOptions['side'] ?? null);
        self::assertSame('cid-1', $capturedOptions['client_order_id'] ?? null);
    }

    public function testMapsShortEntryToLegacyOpenShortSide(): void
    {
        $capturedOptions = null;
        $adapter = $this->createAdapter(function (array $options) use (&$capturedOptions): void {
            $capturedOptions = $options;
        });

        $adapter->placeOrder($this->placeOrderRequest(ExchangePositionSide::SHORT, ExchangeOrderSide::SELL));

        self::assertSame(4, $capturedOptions['side'] ?? null);
    }

    /**
     * @param callable(array<string,mixed>): void $captureOptions
     */
    private function createAdapter(callable $captureOptions): BitmartExchangeAdapter
    {
        $orderProvider = $this->createMock(OrderProviderInterface::class);
        $orderProvider
            ->expects($this->once())
            ->method('placeOrder')
            ->with(
                'BTCUSDT',
                $this->isInstanceOf(OrderSide::class),
                OrderType::LIMIT,
                10.0,
                25000.0,
                null,
                $this->callback(function (array $options) use ($captureOptions): bool {
                    $captureOptions($options);

                    return true;
                }),
            )
            ->willReturn($this->providerOrder());

        $bundle = new ExchangeProviderBundle(
            new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
            $this->createMock(KlineProviderInterface::class),
            $this->createMock(ContractProviderInterface::class),
            $orderProvider,
            $this->createMock(AccountProviderInterface::class),
            $this->createMock(SystemProviderInterface::class),
        );

        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry->method('get')->willReturn($bundle);

        return new BitmartExchangeAdapter($registry, new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        });
    }

    private function placeOrderRequest(ExchangePositionSide $positionSide, ExchangeOrderSide $side): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            positionSide: $positionSide,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 10.0,
            price: 25000.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-1',
        );
    }

    private function providerOrder(): OrderDto
    {
        return new OrderDto(
            orderId: 'ex-1',
            symbol: 'BTCUSDT',
            side: OrderSide::BUY,
            type: OrderType::LIMIT,
            status: OrderStatus::PENDING,
            quantity: BigDecimal::of('10'),
            price: BigDecimal::of('25000'),
            stopPrice: null,
            filledQuantity: BigDecimal::of('0'),
            remainingQuantity: BigDecimal::of('10'),
            averagePrice: null,
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
            metadata: ['client_order_id' => 'cid-1'],
        );
    }
}
