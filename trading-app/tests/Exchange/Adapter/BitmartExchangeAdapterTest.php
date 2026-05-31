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
use App\Exchange\Dto\CancelOrderRequest;
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
    public function testDoesNotAdvertiseStandaloneTriggerOrders(): void
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $adapter = new BitmartExchangeAdapter($registry, $this->fixedClock());

        self::assertFalse($adapter->capabilities()->supportsTriggerOrders);
        self::assertFalse($adapter->capabilities()->supportsModifyOrder);
        self::assertTrue($adapter->capabilities()->supportsAttachedStopLossOnEntry);
        self::assertTrue($adapter->capabilities()->supportsAttachedTakeProfitOnEntry);
    }

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
        self::assertArrayNotHasKey('reduce_only', $capturedOptions);
        self::assertArrayNotHasKey('post_only', $capturedOptions);
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

    public function testMapsReduceOnlyLongExitToLegacyCloseLongSide(): void
    {
        $capturedOptions = null;
        $adapter = $this->createAdapter(function (array $options) use (&$capturedOptions): void {
            $capturedOptions = $options;
        });

        $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::LONG,
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
        ));

        self::assertSame(3, $capturedOptions['side'] ?? null);
    }

    public function testMapsReduceOnlyShortExitToLegacyCloseShortSide(): void
    {
        $capturedOptions = null;
        $adapter = $this->createAdapter(function (array $options) use (&$capturedOptions): void {
            $capturedOptions = $options;
        });

        $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::SHORT,
            side: ExchangeOrderSide::BUY,
            reduceOnly: true,
            postOnly: false,
        ));

        self::assertSame(2, $capturedOptions['side'] ?? null);
    }

    public function testMapsFokToLegacyMode(): void
    {
        $capturedOptions = null;
        $adapter = $this->createAdapter(function (array $options) use (&$capturedOptions): void {
            $capturedOptions = $options;
        });

        $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::LONG,
            side: ExchangeOrderSide::BUY,
            timeInForce: ExchangeTimeInForce::FOK,
            postOnly: false,
        ));

        self::assertSame(2, $capturedOptions['mode'] ?? null);
    }

    public function testRejectsPostOnlyWithIocOrFok(): void
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry->expects($this->never())->method('get');
        $adapter = new BitmartExchangeAdapter($registry, $this->fixedClock());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postOnly cannot be combined with IOC or FOK');

        $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::LONG,
            side: ExchangeOrderSide::BUY,
            timeInForce: ExchangeTimeInForce::IOC,
            postOnly: true,
        ));
    }

    public function testRejectsPostOnlyMarketOrders(): void
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry->expects($this->never())->method('get');
        $adapter = new BitmartExchangeAdapter($registry, $this->fixedClock());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postOnly is only supported for limit orders');

        $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::LONG,
            side: ExchangeOrderSide::BUY,
            orderType: ExchangeOrderType::MARKET,
            postOnly: true,
        ));
    }

    public function testRejectsSidePositionMismatchBeforeProviderSubmission(): void
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry->expects($this->never())->method('get');
        $adapter = new BitmartExchangeAdapter($registry, $this->fixedClock());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid order side "buy" for entry short position intent');

        $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::SHORT,
            side: ExchangeOrderSide::BUY,
            postOnly: false,
        ));
    }

    public function testRejectsStandaloneTriggerOrdersBeforeProviderSubmission(): void
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry->expects($this->never())->method('get');
        $adapter = new BitmartExchangeAdapter($registry, $this->fixedClock());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support standalone trigger orders');

        $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::LONG,
            side: ExchangeOrderSide::BUY,
            orderType: ExchangeOrderType::TRIGGER,
            postOnly: false,
        ));
    }

    public function testMapsReturnedShortEntryFromRawBitmartSideCode(): void
    {
        $adapter = $this->createAdapter(
            static function (): void {
            },
            $this->providerOrder([
                'client_order_id' => 'cid-1',
                'side' => 4,
            ]),
        );

        $result = $adapter->placeOrder($this->placeOrderRequest(ExchangePositionSide::SHORT, ExchangeOrderSide::SELL));

        self::assertSame(ExchangeOrderSide::SELL, $result->order?->side);
        self::assertSame(ExchangePositionSide::SHORT, $result->order?->positionSide);
        self::assertFalse($result->order?->reduceOnly);
    }

    public function testMapsReturnedLongExitFromRawBitmartSideCode(): void
    {
        $adapter = $this->createAdapter(
            static function (): void {
            },
            $this->providerOrder([
                'client_order_id' => 'cid-1',
                'side' => 3,
                'mode' => 3,
            ]),
        );

        $result = $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::LONG,
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
        ));

        self::assertSame(ExchangeOrderSide::SELL, $result->order?->side);
        self::assertSame(ExchangePositionSide::LONG, $result->order?->positionSide);
        self::assertTrue($result->order?->reduceOnly);
        self::assertSame(ExchangeTimeInForce::IOC, $result->order?->timeInForce);
    }

    public function testMapsReturnedGtcMode(): void
    {
        $adapter = $this->createAdapter(
            static function (): void {
            },
            $this->providerOrder([
                'client_order_id' => 'cid-1',
                'side' => 1,
                'mode' => 1,
            ]),
        );

        $result = $adapter->placeOrder($this->placeOrderRequest(ExchangePositionSide::LONG, ExchangeOrderSide::BUY));

        self::assertSame(ExchangeTimeInForce::GTC, $result->order?->timeInForce);
    }

    public function testMapsReturnedPostOnlyModeAsGtc(): void
    {
        $adapter = $this->createAdapter(
            static function (): void {
            },
            $this->providerOrder([
                'client_order_id' => 'cid-1',
                'side' => 1,
                'mode' => 4,
            ]),
        );

        $result = $adapter->placeOrder($this->placeOrderRequest(ExchangePositionSide::LONG, ExchangeOrderSide::BUY));

        self::assertTrue($result->order?->postOnly);
        self::assertSame(ExchangeTimeInForce::GTC, $result->order?->timeInForce);
    }

    public function testFallbackSubmitOnlyOrderPreservesSubmittedIntent(): void
    {
        $adapter = $this->createAdapter(
            static function (): void {
            },
            $this->providerOrder([
                'provider' => 'bitmart',
                'submit_only' => true,
            ]),
        );

        $result = $adapter->placeOrder($this->placeOrderRequest(
            positionSide: ExchangePositionSide::LONG,
            side: ExchangeOrderSide::SELL,
            timeInForce: ExchangeTimeInForce::IOC,
            reduceOnly: true,
            postOnly: false,
        ));

        self::assertSame('cid-1', $result->order?->clientOrderId);
        self::assertSame(ExchangeOrderSide::SELL, $result->order?->side);
        self::assertSame(ExchangePositionSide::LONG, $result->order?->positionSide);
        self::assertTrue($result->order?->reduceOnly);
        self::assertSame(ExchangeTimeInForce::IOC, $result->order?->timeInForce);
        self::assertSame(3, $result->order?->metadata['side'] ?? null);
        self::assertTrue($result->order?->metadata['submit_only'] ?? false);
    }

    public function testCancelFailureWithExchangeOrderIdDoesNotReportClientIdUnsupported(): void
    {
        $orderProvider = $this->createMock(OrderProviderInterface::class);
        $orderProvider
            ->expects($this->once())
            ->method('cancelOrder')
            ->with('BTCUSDT', 'ex-1')
            ->willReturn(false);

        $adapter = $this->createAdapterWithOrderProvider($orderProvider);

        $result = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'ex-1',
            clientOrderId: 'cid-1',
        ));

        self::assertFalse($result->cancelled);
        self::assertSame('exchange_order_cancel_failed', $result->metadata['reason'] ?? null);
    }

    /**
     * @param callable(array<string,mixed>): void $captureOptions
     */
    private function createAdapter(callable $captureOptions, ?OrderDto $providerOrder = null): BitmartExchangeAdapter
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
            ->willReturn($providerOrder ?? $this->providerOrder());

        return $this->createAdapterWithOrderProvider($orderProvider);
    }

    private function createAdapterWithOrderProvider(OrderProviderInterface $orderProvider): BitmartExchangeAdapter
    {
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

        return new BitmartExchangeAdapter($registry, $this->fixedClock());
    }

    private function placeOrderRequest(
        ExchangePositionSide $positionSide,
        ExchangeOrderSide $side,
        ExchangeTimeInForce $timeInForce = ExchangeTimeInForce::GTC,
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        bool $reduceOnly = false,
        bool $postOnly = true,
    ): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: $timeInForce,
            quantity: 10.0,
            price: 25000.0,
            stopPrice: null,
            reduceOnly: $reduceOnly,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-1',
        );
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

    /**
     * @param array<string,mixed> $metadata
     */
    private function providerOrder(array $metadata = ['client_order_id' => 'cid-1']): OrderDto
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
            metadata: $metadata,
        );
    }
}
