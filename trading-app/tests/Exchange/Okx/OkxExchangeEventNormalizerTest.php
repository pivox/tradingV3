<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderCancelled;
use App\Exchange\Event\ExchangeOrderCreated;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangeOrderPartiallyFilled;
use App\Exchange\Event\ExchangeOrderUpdated;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Event\ExchangeProtectionOrderCreated;
use App\Exchange\Event\ExchangeProtectionOrderRejected;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use App\Exchange\Okx\OkxFillId;
use App\Exchange\Okx\OkxInstrumentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(OkxExchangeEventNormalizer::class)]
#[CoversClass(OkxFillId::class)]
#[CoversClass(OkxInstrumentResolver::class)]
#[CoversClass(ExchangeOrderCreated::class)]
#[CoversClass(ExchangeOrderPartiallyFilled::class)]
#[CoversClass(ExchangeOrderFilled::class)]
#[CoversClass(ExchangeOrderUpdated::class)]
#[CoversClass(ExchangeFillReceived::class)]
#[CoversClass(ExchangeProtectionOrderCreated::class)]
#[CoversClass(ExchangeProtectionOrderRejected::class)]
#[CoversClass(ExchangePositionUpdated::class)]
#[CoversClass(ExchangePositionClosed::class)]
final class OkxExchangeEventNormalizerTest extends TestCase
{
    private OkxExchangeEventNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new OkxExchangeEventNormalizer(new OkxInstrumentResolver(), $this->fixedClock());
    }

    public function testNormalizesOrderPartialFillIntoOrderAndFillEvents(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0.2',
                'avgPx' => '25010',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXENTRY',
                'fee' => '-0.05',
                'feeCcy' => 'USDT',
                'fillFee' => '-0.02',
                'fillFeeCcy' => 'USDT',
                'fillPx' => '25010',
                'fillSz' => '0.2',
                'fillTime' => '1767225601123',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'lever' => '3',
                'mgnMode' => 'isolated',
                'ordId' => '12345',
                'ordType' => 'limit',
                'posSide' => 'long',
                'px' => '25000',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'partially_filled',
                'sz' => '1',
                'tradeId' => 'fill-1',
                'uTime' => '1767225601123',
            ]],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangeOrderPartiallyFilled::class, $events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        self::assertSame('exchange.order.partially_filled', $events[0]->eventType());
        self::assertSame('BTCUSDT', $events[0]->order()->symbol);
        self::assertSame(ExchangeOrderStatus::PARTIALLY_FILLED, $events[0]->order()->status);
        self::assertEqualsWithDelta(0.2, $events[0]->order()->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.8, $events[0]->order()->remainingQuantity, 0.000001);
        self::assertSame('1', $events[0]->order()->metadata['quantity_decimal'] ?? null);
        self::assertSame('0.2', $events[0]->order()->metadata['filled_quantity_decimal'] ?? null);
        self::assertSame('0.8', $events[0]->order()->metadata['remaining_quantity_decimal'] ?? null);
        self::assertSame('isolated', $events[0]->order()->metadata['margin_mode'] ?? null);
        self::assertSame('3', $events[0]->order()->metadata['leverage'] ?? null);
        self::assertSame('1767225601.123000', $events[0]->occurredAt()->format('U.u'));
        self::assertSame($this->okxFillId('BTC-USDT-SWAP', 'fill-1'), $events[1]->fill()->fillId);
        self::assertSame(ExchangeOrderSide::BUY, $events[1]->fill()->side);
        self::assertEqualsWithDelta(25010.0, $events[1]->fill()->price, 0.000001);
        self::assertEqualsWithDelta(-0.02, $events[1]->fill()->fee, 0.000001);
        self::assertSame('USDT', $events[1]->fill()->feeCurrency);
        self::assertSame('1767225601.123000', $events[1]->fill()->filledAt->format('U.u'));
    }

    public function testPreservesEighteenDecimalOrderQuantitiesWithoutFloatIntermediary(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0.400000000000000001',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'exact-order',
                'ordType' => 'limit',
                'posSide' => 'long',
                'px' => '25000',
                'side' => 'buy',
                'state' => 'partially_filled',
                'sz' => '1.123456789012345678',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderPartiallyFilled::class, $events[0]);
        self::assertSame('1.123456789012345678', $events[0]->order()->metadata['quantity_decimal'] ?? null);
        self::assertSame('0.400000000000000001', $events[0]->order()->metadata['filled_quantity_decimal'] ?? null);
        self::assertSame('0.723456789012345677', $events[0]->order()->metadata['remaining_quantity_decimal'] ?? null);
    }

    public function testNormalizesStopLossAlgoLiveAsProtectionCreated(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'algoClOrdId' => 'OKXSL',
                'algoId' => '90001',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordType' => 'conditional',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'side' => 'sell',
                'slOrdPx' => '-1',
                'slTriggerPx' => '24800',
                'state' => 'live',
                'sz' => '0.01',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeProtectionOrderCreated::class, $events[0]);
        self::assertSame('algo:90001', $events[0]->order()->exchangeOrderId);
        self::assertSame('OKXSL', $events[0]->order()->clientOrderId);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $events[0]->order()->orderType);
        self::assertSame(ExchangePositionSide::LONG, $events[0]->order()->positionSide);
        self::assertTrue($events[0]->order()->reduceOnly);
        self::assertEqualsWithDelta(24800.0, $events[0]->order()->stopPrice, 0.000001);
    }

    public function testNormalizesFailedAlgoOrderAsProtectionRejected(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'algoClOrdId' => 'OKXSL',
                'algoId' => '90002',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordType' => 'conditional',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'side' => 'sell',
                'slOrdPx' => '-1',
                'slTriggerPx' => '24800',
                'state' => 'order_failed',
                'sz' => '0.01',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeProtectionOrderRejected::class, $events[0]);
        self::assertSame(ExchangeOrderStatus::REJECTED, $events[0]->order()->status);
        self::assertSame('algo:90002', $events[0]->order()->exchangeOrderId);
    }

    public function testNormalizesPartiallyFailedAlgoOrderAsProtectionRejected(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'algoClOrdId' => 'OKXSL',
                'algoId' => '90006',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordType' => 'conditional',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'side' => 'sell',
                'slOrdPx' => '-1',
                'slTriggerPx' => '24800',
                'state' => 'partially_failed',
                'sz' => '0.01',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeProtectionOrderRejected::class, $events[0]);
        self::assertSame(ExchangeOrderStatus::REJECTED, $events[0]->order()->status);
        self::assertSame('algo:90006', $events[0]->order()->exchangeOrderId);
    }

    public function testNormalizesEffectiveAlgoOrderAsTerminalNonFillEvent(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'algoClOrdId' => 'OKXSL',
                'algoId' => '90003',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordType' => 'conditional',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'side' => 'sell',
                'slOrdPx' => '-1',
                'slTriggerPx' => '24800',
                'state' => 'effective',
                'sz' => '0.01',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderUpdated::class, $events[0]);
        self::assertSame(ExchangeOrderStatus::UNKNOWN, $events[0]->order()->status);
        self::assertSame('algo:90003', $events[0]->order()->exchangeOrderId);
        self::assertEqualsWithDelta(0.0, $events[0]->order()->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.01, $events[0]->order()->remainingQuantity, 0.000001);
    }

    public function testNormalizesAlgoLimitExecutionPrice(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'algoClOrdId' => 'OKXTP',
                'algoId' => '90007',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordType' => 'conditional',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'side' => 'sell',
                'state' => 'live',
                'sz' => '0.01',
                'tpOrdPx' => '25550',
                'tpTriggerPx' => '25500',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeProtectionOrderCreated::class, $events[0]);
        self::assertSame(ExchangeOrderType::TAKE_PROFIT, $events[0]->order()->orderType);
        self::assertEqualsWithDelta(25550.0, $events[0]->order()->price, 0.000001);
        self::assertEqualsWithDelta(25500.0, $events[0]->order()->stopPrice, 0.000001);
    }

    public function testNormalizesPartiallyEffectiveAlgoOrderAsUpdate(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'actualSz' => '0.005',
                'algoClOrdId' => 'OKXSL',
                'algoId' => '90005',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordType' => 'conditional',
                'posSide' => 'long',
                'reduceOnly' => 'true',
                'side' => 'sell',
                'slOrdPx' => '-1',
                'slTriggerPx' => '24800',
                'state' => 'partially_effective',
                'sz' => '0.01',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderUpdated::class, $events[0]);
        self::assertSame(ExchangeOrderStatus::UNKNOWN, $events[0]->order()->status);
        self::assertSame('algo:90005', $events[0]->order()->exchangeOrderId);
    }

    public function testNormalOrderChannelPrefersChildOrderIdsWhenAlgoFieldsArePresent(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0.3',
                'algoClOrdId' => 'PARENT-CID',
                'algoId' => 'parent-algo',
                'avgPx' => '25005',
                'cTime' => '1767225600000',
                'clOrdId' => 'CHILD-CID',
                'fillPx' => '25005',
                'fillSz' => '0.3',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'child-order',
                'ordType' => 'market',
                'posSide' => 'long',
                'side' => 'buy',
                'state' => 'filled',
                'sz' => '0.3',
                'tradeId' => 'child-fill',
                'uTime' => '1767225601123',
            ]],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        self::assertSame('child-order', $events[0]->order()->exchangeOrderId);
        self::assertSame('CHILD-CID', $events[0]->order()->clientOrderId);
        self::assertSame('child-order', $events[1]->fill()->exchangeOrderId);
        self::assertSame('CHILD-CID', $events[1]->fill()->clientOrderId);
    }

    public function testNormalizesTriggerAlgoOrderType(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders-algo', 'instType' => 'SWAP'],
            'data' => [[
                'algoClOrdId' => 'OKXTRIGGER',
                'algoId' => '90004',
                'cTime' => '1767225600000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordPx' => '-1',
                'ordType' => 'trigger',
                'posSide' => 'short',
                'reduceOnly' => 'true',
                'side' => 'buy',
                'state' => 'live',
                'sz' => '0.01',
                'triggerPx' => '25200',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeProtectionOrderCreated::class, $events[0]);
        self::assertSame('algo:90004', $events[0]->order()->exchangeOrderId);
        self::assertSame(ExchangeOrderType::TRIGGER, $events[0]->order()->orderType);
        self::assertSame(ExchangePositionSide::SHORT, $events[0]->order()->positionSide);
        self::assertEqualsWithDelta(25200.0, $events[0]->order()->stopPrice, 0.000001);
    }

    public function testNormalizesOptimalLimitIocAsMarketIoc(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXIOC',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'ioc-order',
                'ordType' => 'optimal_limit_ioc',
                'posSide' => 'long',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'live',
                'sz' => '1',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderCreated::class, $events[0]);
        self::assertSame(ExchangeOrderType::MARKET, $events[0]->order()->orderType);
        self::assertSame(ExchangeTimeInForce::IOC, $events[0]->order()->timeInForce);
    }

    public function testCanceledIocWithTradeIdEmitsCancelAndFillEvents(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0.4',
                'avgPx' => '25005.5',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXIOC',
                'fillFee' => '-0.01',
                'fillFeeCcy' => 'USDT',
                'fillPx' => '25005.5',
                'fillSz' => '0.4',
                'fillTime' => '1767225603123',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'ioc-order',
                'ordType' => 'optimal_limit_ioc',
                'posSide' => 'long',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'canceled',
                'sz' => '1',
                'tradeId' => 'ioc-fill',
                'uTime' => '1767225604000',
            ]],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangeOrderCancelled::class, $events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        self::assertEqualsWithDelta(0.4, $events[0]->order()->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $events[0]->order()->remainingQuantity, 0.000001);
        self::assertSame($this->okxFillId('BTC-USDT-SWAP', 'ioc-fill'), $events[1]->fill()->fillId);
        self::assertEqualsWithDelta(0.4, $events[1]->fill()->quantity, 0.000001);
    }

    public function testFilledSwapMarketOrderWithoutTradeIdDoesNotEmitSyntheticFill(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '1',
                'avgPx' => '25005.5',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXMARKET',
                'fillPx' => '',
                'fillSz' => '0',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => '12347',
                'ordType' => 'market',
                'posSide' => 'long',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'filled',
                'sz' => '1',
                'uTime' => '1767225603000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[0]);
        self::assertEqualsWithDelta(1.0, $events[0]->order()->filledQuantity, 0.000001);
    }

    public function testFilledLimitOrderWithoutIncrementalFillDoesNotEmitCumulativeFill(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '1',
                'avgPx' => '25005.5',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXLIMIT',
                'fillPx' => '',
                'fillSz' => '0',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'limit-filled-order',
                'ordType' => 'limit',
                'posSide' => 'long',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'filled',
                'sz' => '1',
                'uTime' => '1767225603000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[0]);
        self::assertEqualsWithDelta(1.0, $events[0]->order()->filledQuantity, 0.000001);
    }

    public function testFilledMarketAmendWithoutIncrementalFillDoesNotEmitCumulativeFill(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '1',
                'avgPx' => '25005.5',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXMARKET',
                'fillPx' => '',
                'fillSz' => '0',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'market-amend-order',
                'ordType' => 'market',
                'posSide' => 'long',
                'reduceOnly' => 'false',
                'reqId' => 'amend-1',
                'side' => 'buy',
                'state' => 'filled',
                'sz' => '1',
                'uTime' => '1767225603000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[0]);
        self::assertEqualsWithDelta(1.0, $events[0]->order()->filledQuantity, 0.000001);
    }

    public function testDuplicateFilledSwapMessagesWithoutTradeIdDoNotEmitSyntheticFills(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [
                [
                    'accFillSz' => '1',
                    'avgPx' => '25005.5',
                    'clOrdId' => 'OKXMARKET',
                    'fillPx' => '',
                    'fillSz' => '0',
                    'instId' => 'BTC-USDT-SWAP',
                    'instType' => 'SWAP',
                    'ordId' => 'duplicate-filled-order',
                    'ordType' => 'market',
                    'posSide' => 'long',
                    'side' => 'buy',
                    'state' => 'filled',
                    'sz' => '1',
                    'uTime' => '1767225603000',
                ],
                [
                    'accFillSz' => '1',
                    'avgPx' => '25005.5',
                    'clOrdId' => 'OKXMARKET',
                    'fillPx' => '',
                    'fillSz' => '0',
                    'instId' => 'BTC-USDT-SWAP',
                    'instType' => 'SWAP',
                    'ordId' => 'duplicate-filled-order',
                    'ordType' => 'market',
                    'posSide' => 'long',
                    'side' => 'buy',
                    'state' => 'filled',
                    'sz' => '1',
                    'uTime' => '1767225603999',
                ],
            ],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[0]);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[1]);
    }

    public function testNormalizesNetModeReduceOnlyOrderSides(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [
                [
                    'accFillSz' => '0.1',
                    'avgPx' => '25000',
                    'clOrdId' => 'CLOSE-LONG',
                    'fillPx' => '25000',
                    'fillSz' => '0.1',
                    'instId' => 'BTC-USDT-SWAP',
                    'instType' => 'SWAP',
                    'ordId' => 'close-long',
                    'ordType' => 'market',
                    'posSide' => 'net',
                    'reduceOnly' => 'true',
                    'side' => 'sell',
                    'state' => 'filled',
                    'sz' => '0.1',
                    'tradeId' => 'close-long-fill',
                    'uTime' => '1767225600000',
                ],
                [
                    'accFillSz' => '0.2',
                    'avgPx' => '25100',
                    'clOrdId' => 'CLOSE-SHORT',
                    'fillPx' => '25100',
                    'fillSz' => '0.2',
                    'instId' => 'BTC-USDT-SWAP',
                    'instType' => 'SWAP',
                    'ordId' => 'close-short',
                    'ordType' => 'market',
                    'posSide' => 'net',
                    'reduceOnly' => 'true',
                    'side' => 'buy',
                    'state' => 'filled',
                    'sz' => '0.2',
                    'tradeId' => 'close-short-fill',
                    'uTime' => '1767225601000',
                ],
            ],
        ]);

        self::assertCount(4, $events);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[2]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[3]);
        self::assertSame(ExchangePositionSide::LONG, $events[0]->order()->positionSide);
        self::assertSame(ExchangePositionSide::LONG, $events[1]->fill()->positionSide);
        self::assertSame(ExchangePositionSide::SHORT, $events[2]->order()->positionSide);
        self::assertSame(ExchangePositionSide::SHORT, $events[3]->fill()->positionSide);
    }

    public function testNetModeNonReduceOnlyOrderLeavesPositionSideUnknown(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0.1',
                'avgPx' => '25000',
                'clOrdId' => 'NET-BUY',
                'fillPx' => '25000',
                'fillSz' => '0.1',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'net-buy-order',
                'ordType' => 'market',
                'posSide' => 'net',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'filled',
                'sz' => '0.1',
                'tradeId' => 'net-buy-fill',
                'uTime' => '1767225600000',
            ]],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangeOrderFilled::class, $events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        self::assertNull($events[0]->order()->positionSide);
        self::assertNull($events[1]->fill()->positionSide);
    }

    public function testNormalizesCancelledOrder(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXCANCEL',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => '12346',
                'ordType' => 'post_only',
                'posSide' => 'long',
                'px' => '25000',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'canceled',
                'sz' => '1',
                'uTime' => '1767225602000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderCancelled::class, $events[0]);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $events[0]->order()->status);
        self::assertTrue($events[0]->order()->postOnly);
    }

    public function testNormalizesMmpCanceledOrderAsCancelled(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0',
                'cTime' => '1767225600000',
                'clOrdId' => 'OKXMMP',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => '12349',
                'ordType' => 'limit',
                'posSide' => 'long',
                'px' => '25000',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'mmp_canceled',
                'sz' => '1',
                'uTime' => '1767225602000',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeOrderCancelled::class, $events[0]);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $events[0]->order()->status);
    }

    public function testNormalizesFillsChannel(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
            'data' => [[
                'clOrdId' => 'OKXENTRY',
                'fee' => '-0.01',
                'feeCcy' => 'USDT',
                'execType' => 'M',
                'fillPx' => '25000.5',
                'fillSz' => '0.1',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => '12345',
                'posSide' => 'long',
                'side' => 'buy',
                'tradeId' => 'trade-1',
                'ts' => '1767225603123',
            ]],
        ]);

        self::assertCount(1, $events);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[0]);
        self::assertSame($this->okxFillId('BTC-USDT-SWAP', 'trade-1'), $events[0]->fill()->fillId);
        self::assertSame('BTCUSDT', $events[0]->fill()->symbol);
        self::assertEqualsWithDelta(0.1, $events[0]->fill()->quantity, 0.000001);
        self::assertSame('1767225603.123000', $events[0]->fill()->filledAt->format('U.u'));
        self::assertSame('okx_ws_fills', $events[0]->fill()->metadata['source'] ?? null);
        self::assertSame('maker', $events[0]->fill()->metadata['liquidity_role'] ?? null);
    }

    public function testPrivateOrderAndDerivedFillNeverExposeProviderRows(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [array_merge([
                'accFillSz' => '0.2',
                'clOrdId' => 'safe-client-order',
                'fillFee' => '-0.02',
                'fillFeeCcy' => 'USDT',
                'fillPx' => '25010',
                'fillSz' => '0.2',
                'fillTime' => '1767225601123',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'lever' => ['nested' => 'secret-array-value-sentinel'],
                'nested' => ['token' => 'secret-nested-value-sentinel'],
                'ordId' => 'safe-order',
                'ordType' => 'limit',
                'posSide' => 'long',
                'px' => '25000',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'partially_filled',
                'sz' => '1',
                'tradeId' => 'safe-trade',
                'uTime' => '1767225601123',
            ], $this->privateSecretFields('secret'))],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangeOrderPartiallyFilled::class, $events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        self::assertSame([
            'source' => 'okx_ws_orders',
            'instrument_id' => 'BTC-USDT-SWAP',
            'exchange_order_id' => 'safe-order',
            'client_order_id' => 'safe-client-order',
        ], $events[0]->payload());
        self::assertSame([
            'source' => 'okx_ws_orders',
            'instrument_id' => 'BTC-USDT-SWAP',
            'exchange_order_id' => 'safe-order',
            'client_order_id' => 'safe-client-order',
            'exchange_fill_id' => 'safe-trade',
        ], $events[1]->payload());
        self::assertSame([
            'source' => 'okx_ws_orders',
            'instrument_id' => 'BTC-USDT-SWAP',
            'quantity_decimal' => '1',
            'filled_quantity_decimal' => '0.2',
            'remaining_quantity_decimal' => '0.8',
        ], $events[0]->order()->metadata);
        self::assertSame([
            'source' => 'okx_ws_orders',
            'instrument_id' => 'BTC-USDT-SWAP',
            'exchange_fill_id' => 'safe-trade',
        ], $events[1]->fill()->metadata);

        $serialized = serialize($events);
        foreach (['secret-', 'apiKey', 'api_secret', 'passphrase', 'signature', 'Authorization', 'token', 'cookie', 'credential', 'nested'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function testPrivateFillAndPositionChannelsUseMinimalSanitizedData(): void
    {
        $fillEvents = $this->normalizer->normalize([
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
            'data' => [array_merge([
                'clOrdId' => 'fill-client',
                'fee' => '-0.01',
                'feeCcy' => 'USDT',
                'fillPx' => '25000.5',
                'fillSz' => '0.1',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'fill-order',
                'posSide' => 'long',
                'side' => 'buy',
                'tradeId' => 'fill-trade',
                'ts' => '1767225603123',
            ], $this->privateSecretFields('fill-secret'))],
        ]);
        $positionEvents = $this->normalizer->normalize([
            'arg' => ['channel' => 'positions', 'instType' => 'SWAP'],
            'data' => [array_merge([
                'avgPx' => '25000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'lever' => '3',
                'markPx' => '25100',
                'margin' => '100',
                'mgnMode' => 'isolated',
                'pos' => '0.5',
                'posSide' => 'long',
                'realizedPnl' => '1.5',
                'uTime' => '1767225604000',
                'upl' => '50',
            ], $this->privateSecretFields('position-secret'))],
        ]);

        self::assertCount(1, $fillEvents);
        self::assertCount(1, $positionEvents);
        self::assertInstanceOf(ExchangePositionUpdated::class, $positionEvents[0]);
        self::assertSame([
            'source' => 'okx_ws_fills',
            'instrument_id' => 'BTC-USDT-SWAP',
            'exchange_order_id' => 'fill-order',
            'client_order_id' => 'fill-client',
            'exchange_fill_id' => 'fill-trade',
        ], $fillEvents[0]->payload());
        self::assertSame([
            'source' => 'okx_ws_positions',
            'instrument_id' => 'BTC-USDT-SWAP',
        ], $positionEvents[0]->payload());
        self::assertSame([
            'source' => 'okx_ws_positions',
            'instrument_id' => 'BTC-USDT-SWAP',
            'margin_mode' => 'isolated',
        ], $positionEvents[0]->position()?->metadata);
        self::assertStringNotContainsString('secret-', serialize([$fillEvents, $positionEvents]));
    }

    public function testOkxTradeFillIdsIncludeInstrument(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'fills', 'instType' => 'ANY'],
            'data' => [
                [
                    'fillPx' => '25000.5',
                    'fillSz' => '0.1',
                    'instId' => 'BTC-USDT-SWAP',
                    'instType' => 'SWAP',
                    'ordId' => 'btc-order',
                    'side' => 'buy',
                    'tradeId' => 'duplicate-trade-id',
                    'ts' => '1767225603000',
                ],
                [
                    'fillPx' => '3500.5',
                    'fillSz' => '0.2',
                    'instId' => 'ETH-USDT-SWAP',
                    'instType' => 'SWAP',
                    'ordId' => 'eth-order',
                    'side' => 'buy',
                    'tradeId' => 'duplicate-trade-id',
                    'ts' => '1767225603000',
                ],
            ],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $events[1]);
        self::assertSame($this->okxFillId('BTC-USDT-SWAP', 'duplicate-trade-id'), $events[0]->fill()->fillId);
        self::assertSame($this->okxFillId('ETH-USDT-SWAP', 'duplicate-trade-id'), $events[1]->fill()->fillId);
        self::assertNotSame($events[0]->fill()->fillId, $events[1]->fill()->fillId);
    }

    public function testDropsFillWhenAllowlistedTradeIdentifierIsNotScalar(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
            'data' => [[
                'fillPx' => '25000.5',
                'fillSz' => '0.1',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'fill-order',
                'side' => 'buy',
                'tradeId' => ['nested' => 'malformed-trade-secret-sentinel'],
                'ts' => '1767225603123',
            ]],
        ]);

        self::assertSame([], $events);
        self::assertStringNotContainsString('malformed-trade-secret-sentinel', serialize($events));
    }

    public function testNormalizesPositionUpdateAndCloseEvents(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'positions', 'instType' => 'SWAP'],
            'data' => [
                [
                    'avgPx' => '25000',
                    'instId' => 'BTC-USDT-SWAP',
                    'instType' => 'SWAP',
                    'lever' => '3',
                    'markPx' => '25100',
                    'margin' => '100',
                    'pos' => '0.5',
                    'posSide' => 'long',
                    'realizedPnl' => '0',
                    'uTime' => '1767225604000',
                    'upl' => '50',
                ],
                [
                    'avgPx' => '0',
                    'instId' => 'ETH-USDT-SWAP',
                    'instType' => 'SWAP',
                    'pos' => '0',
                    'posSide' => 'short',
                    'uTime' => '1767225605000',
                ],
            ],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangePositionUpdated::class, $events[0]);
        self::assertSame('BTCUSDT', $events[0]->symbol());
        self::assertSame(ExchangePositionSide::LONG, $events[0]->side());
        self::assertEqualsWithDelta(0.5, $events[0]->size(), 0.000001);
        self::assertInstanceOf(ExchangePositionClosed::class, $events[1]);
        self::assertSame('ETHUSDT', $events[1]->symbol());
        self::assertSame(ExchangePositionSide::SHORT, $events[1]->side());
        self::assertEqualsWithDelta(0.0, $events[1]->size(), 0.000001);
    }

    public function testNormalizesNetModeZeroPositionCloseForBothSides(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'positions', 'instType' => 'SWAP'],
            'data' => [[
                'avgPx' => '0',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'pos' => '0',
                'posSide' => 'net',
                'token' => 'net-close-secret-sentinel',
                'unknown' => ['credential' => 'net-close-nested-sentinel'],
                'uTime' => '1767225605000',
            ]],
        ]);

        self::assertCount(2, $events);
        self::assertInstanceOf(ExchangePositionClosed::class, $events[0]);
        self::assertInstanceOf(ExchangePositionClosed::class, $events[1]);
        self::assertSame([ExchangePositionSide::LONG, ExchangePositionSide::SHORT], [
            $events[0]->side(),
            $events[1]->side(),
        ]);
        self::assertSame([
            'source' => 'okx_ws_positions',
            'instrument_id' => 'BTC-USDT-SWAP',
        ], $events[0]->payload());
        self::assertSame($events[0]->payload(), $events[1]->payload());
        self::assertStringNotContainsString('net-close-secret-sentinel', serialize($events));
        self::assertStringNotContainsString('net-close-nested-sentinel', serialize($events));
    }

    public function testIgnoresAmbiguousZeroPositionWithoutSide(): void
    {
        self::assertSame([], $this->normalizer->normalize([
            'arg' => ['channel' => 'positions', 'instType' => 'SWAP'],
            'data' => [[
                'avgPx' => '0',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'pos' => '0',
                'posSide' => '',
                'uTime' => '1767225605000',
            ]],
        ]));
    }

    public function testIgnoresUnsupportedChannelsAndNonSwapRows(): void
    {
        self::assertFalse($this->normalizer->supports(['arg' => ['channel' => 'tickers'], 'data' => []]));
        self::assertFalse($this->normalizer->supports([
            'arg' => ['channel' => 'orders', 'instType' => 'SPOT'],
            'data' => [['instId' => 'BTC-USDT']],
        ]));
        self::assertSame([], $this->normalizer->normalize([
            'arg' => ['channel' => 'orders'],
            'data' => [[
                'instId' => 'BTC-USDT',
                'instType' => 'SPOT',
                'ordId' => 'spot-order',
                'side' => 'buy',
                'state' => 'live',
                'sz' => '1',
            ]],
        ]));
    }

    public function testAcceptsAnyInstTypeSubscriptionsAndFiltersRows(): void
    {
        $events = $this->normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'ANY'],
            'data' => [
                [
                    'accFillSz' => '0',
                    'clOrdId' => 'OKXSWAP',
                    'instId' => 'BTC-USDT-SWAP',
                    'instType' => 'SWAP',
                    'ordId' => '12348',
                    'ordType' => 'limit',
                    'posSide' => 'long',
                    'px' => '25000',
                    'reduceOnly' => 'false',
                    'side' => 'buy',
                    'state' => 'live',
                    'sz' => '1',
                    'uTime' => '1767225604000',
                ],
                [
                    'instId' => 'BTC-USDT',
                    'instType' => 'SPOT',
                    'ordId' => 'spot-order',
                    'side' => 'buy',
                    'state' => 'live',
                    'sz' => '1',
                ],
                [
                    'instId' => 'ETH-USDT',
                    'ordId' => 'spot-without-inst-type',
                    'side' => 'buy',
                    'state' => 'live',
                    'sz' => '1',
                ],
            ],
        ]);

        self::assertCount(1, $events);
        self::assertSame('BTCUSDT', $events[0]->symbol());
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }

    private function okxFillId(string $instId, string $tradeId): string
    {
        return OkxFillId::fromTradeId($instId, $tradeId) ?? '';
    }

    /** @return array<string,mixed> */
    private function privateSecretFields(string $prefix): array
    {
        return [
            'apiKey' => $prefix . '-api-key-sentinel',
            'api_secret' => $prefix . '-api-secret-sentinel',
            'passphrase' => $prefix . '-passphrase-sentinel',
            'signature' => $prefix . '-signature-sentinel',
            'Authorization' => $prefix . '-authorization-sentinel',
            'token' => $prefix . '-token-sentinel',
            'cookie' => $prefix . '-cookie-sentinel',
            'credential' => $prefix . '-credential-sentinel',
            'nested' => ['unknown' => $prefix . '-nested-sentinel'],
        ];
    }
}
