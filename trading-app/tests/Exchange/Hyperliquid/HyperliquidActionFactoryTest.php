<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidActionFactory::class)]
#[CoversClass(PlaceOrderRequest::class)]
final class HyperliquidActionFactoryTest extends TestCase
{
    public function testBuildsPositionTpslWithEntryThenReduceOnlyStop(): void
    {
        $factory = new HyperliquidActionFactory();
        $entry = $this->entry();
        $stop = $this->stop();

        $action = $factory->positionTpsl(0, $entry, $stop);

        self::assertSame('order', $action['type']);
        self::assertSame('positionTpsl', $action['grouping']);
        self::assertCount(2, $action['orders']);
        self::assertSame($factory->cloid($entry->clientOrderId), $action['orders'][0]['c']);
        self::assertSame($factory->cloid($stop->clientOrderId), $action['orders'][1]['c']);
        self::assertFalse($action['orders'][0]['r']);
        self::assertTrue($action['orders'][1]['r']);
        self::assertFalse($action['orders'][1]['b']);
        self::assertSame('sl', $action['orders'][1]['t']['trigger']['tpsl']);
        self::assertSame('98', $action['orders'][1]['t']['trigger']['triggerPx']);
    }

    public function testRejectsNegativeAssetId(): void
    {
        $this->expectExceptionMessage('hyperliquid_asset_id_must_be_non_negative');

        (new HyperliquidActionFactory())->positionTpsl(-1, $this->entry(), $this->stop());
    }

    public function testRejectsStopOnSameSideAsEntry(): void
    {
        $this->expectExceptionMessage('hyperliquid_stop_must_close_entry_side');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(),
            $this->stop(side: ExchangeOrderSide::BUY),
        );
    }

    public function testRejectsReduceOnlyEntry(): void
    {
        $this->expectExceptionMessage('hyperliquid_entry_must_not_be_reduce_only');

        (new HyperliquidActionFactory())->positionTpsl(0, $this->entry(reduceOnly: true), $this->stop());
    }

    #[DataProvider('unsupportedEntryOrderTypeProvider')]
    public function testRejectsNonLimitEntryOrderTypes(
        ExchangeOrderType $orderType,
        ExchangeOrderSide $side,
        ExchangePositionSide $positionSide,
    ): void {
        $stopSide = $side === ExchangeOrderSide::BUY ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
        $stopPrice = $side === ExchangeOrderSide::BUY ? 98.0 : 102.0;
        $this->expectExceptionMessage('hyperliquid_grouped_entry_must_be_limit');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(orderType: $orderType, side: $side, positionSide: $positionSide),
            $this->stop(side: $stopSide, positionSide: $positionSide, stopPrice: $stopPrice),
        );
    }

    /**
     * @return iterable<string, array{ExchangeOrderType, ExchangeOrderSide, ExchangePositionSide}>
     */
    public static function unsupportedEntryOrderTypeProvider(): iterable
    {
        yield 'long market cap is not a reference price' => [
            ExchangeOrderType::MARKET,
            ExchangeOrderSide::BUY,
            ExchangePositionSide::LONG,
        ];
        yield 'short market cap is not a reference price' => [
            ExchangeOrderType::MARKET,
            ExchangeOrderSide::SELL,
            ExchangePositionSide::SHORT,
        ];
        yield 'stop loss' => [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderSide::BUY,
            ExchangePositionSide::LONG,
        ];
        yield 'take profit' => [
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderSide::BUY,
            ExchangePositionSide::LONG,
        ];
        yield 'generic trigger' => [
            ExchangeOrderType::TRIGGER,
            ExchangeOrderSide::BUY,
            ExchangePositionSide::LONG,
        ];
    }

    public function testRejectsNonReduceOnlyStop(): void
    {
        $this->expectExceptionMessage('hyperliquid_stop_must_be_reduce_only');

        (new HyperliquidActionFactory())->positionTpsl(0, $this->entry(), $this->stop(reduceOnly: false));
    }

    public function testRejectsStopThatIsNotStopLoss(): void
    {
        $this->expectExceptionMessage('hyperliquid_stop_must_be_stop_loss');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(),
            $this->stop(orderType: ExchangeOrderType::TAKE_PROFIT),
        );
    }

    #[DataProvider('invalidStopDirectionProvider')]
    public function testRejectsStopThatDoesNotProtectEntry(
        ExchangeOrderSide $entrySide,
        ExchangePositionSide $positionSide,
        float $stopPrice,
    ): void {
        $stopSide = $entrySide === ExchangeOrderSide::BUY
            ? ExchangeOrderSide::SELL
            : ExchangeOrderSide::BUY;
        $this->expectExceptionMessage('hyperliquid_stop_price_must_protect_entry');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(side: $entrySide, positionSide: $positionSide),
            $this->stop(side: $stopSide, positionSide: $positionSide, stopPrice: $stopPrice),
        );
    }

    /**
     * @return iterable<string, array{ExchangeOrderSide, ExchangePositionSide, float}>
     */
    public static function invalidStopDirectionProvider(): iterable
    {
        yield 'long inverted' => [ExchangeOrderSide::BUY, ExchangePositionSide::LONG, 101.0];
        yield 'long boundary' => [ExchangeOrderSide::BUY, ExchangePositionSide::LONG, 100.0];
        yield 'short inverted' => [ExchangeOrderSide::SELL, ExchangePositionSide::SHORT, 99.0];
        yield 'short boundary' => [ExchangeOrderSide::SELL, ExchangePositionSide::SHORT, 100.0];
    }

    #[DataProvider('canonicalStopBoundaryProvider')]
    public function testRejectsStopEqualToEntryAfterWireNormalization(
        ExchangeOrderSide $entrySide,
        ExchangePositionSide $positionSide,
        float $entryPrice,
        float $stopPrice,
    ): void {
        $stopSide = $entrySide === ExchangeOrderSide::BUY
            ? ExchangeOrderSide::SELL
            : ExchangeOrderSide::BUY;
        $this->expectExceptionMessage('hyperliquid_stop_price_must_protect_entry');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(side: $entrySide, positionSide: $positionSide, price: $entryPrice),
            $this->stop(side: $stopSide, positionSide: $positionSide, stopPrice: $stopPrice),
        );
    }

    /**
     * @return iterable<string, array{ExchangeOrderSide, ExchangePositionSide, float, float}>
     */
    public static function canonicalStopBoundaryProvider(): iterable
    {
        yield 'long raw below but canonical equal' => [
            ExchangeOrderSide::BUY,
            ExchangePositionSide::LONG,
            100.0000000000005,
            100.0,
        ];
        yield 'long inverse canonical equal' => [
            ExchangeOrderSide::BUY,
            ExchangePositionSide::LONG,
            100.0,
            100.0000000000005,
        ];
        yield 'short raw above but canonical equal' => [
            ExchangeOrderSide::SELL,
            ExchangePositionSide::SHORT,
            100.0,
            100.0000000000005,
        ];
        yield 'short inverse canonical equal' => [
            ExchangeOrderSide::SELL,
            ExchangePositionSide::SHORT,
            100.0000000000005,
            100.0,
        ];
    }

    #[DataProvider('invalidLimitReferencePriceProvider')]
    public function testRejectsLimitEntryWithoutUsableReferencePrice(float $price): void
    {
        $this->expectExceptionMessage('hyperliquid_entry_requires_positive_finite_reference_price');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(price: $price),
            $this->stop(),
        );
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidLimitReferencePriceProvider(): iterable
    {
        yield 'limit price infinite' => [INF];
        yield 'limit price not a number' => [NAN];
    }

    public function testRejectsStopWithoutUsableStopPrice(): void
    {
        $this->expectExceptionMessage('hyperliquid_stop_requires_positive_finite_stop_price');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(),
            $this->stop(stopPrice: INF),
        );
    }

    public function testRejectsDuplicateWireCloid(): void
    {
        $clientOrderId = '0xABCDEF0123456789ABCDEF0123456789';
        $this->expectExceptionMessage('hyperliquid_order_cloids_must_be_distinct');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(clientOrderId: $clientOrderId),
            $this->stop(clientOrderId: strtolower($clientOrderId)),
        );
    }

    /**
     * @param callable(PlaceOrderRequest): PlaceOrderRequest $changeStop
     */
    #[DataProvider('incompatibleOrderProvider')]
    public function testRejectsIncompatibleEntryAndStop(
        string $message,
        callable $changeStop,
    ): void {
        $this->expectExceptionMessage($message);

        (new HyperliquidActionFactory())->positionTpsl(0, $this->entry(), $changeStop($this->stop()));
    }

    /**
     * @return iterable<string, array{string, callable(PlaceOrderRequest): PlaceOrderRequest}>
     */
    public static function incompatibleOrderProvider(): iterable
    {
        yield 'exchange' => [
            'hyperliquid_orders_must_use_same_exchange',
            static fn (PlaceOrderRequest $stop): PlaceOrderRequest => self::copy($stop, exchange: Exchange::OKX),
        ];
        yield 'market type' => [
            'hyperliquid_orders_must_use_same_market_type',
            static fn (PlaceOrderRequest $stop): PlaceOrderRequest => self::copy($stop, marketType: MarketType::SPOT),
        ];
        yield 'normalized symbol' => [
            'hyperliquid_orders_must_use_same_symbol',
            static fn (PlaceOrderRequest $stop): PlaceOrderRequest => self::copy($stop, symbol: 'ETHUSDT'),
        ];
        yield 'quantity' => [
            'hyperliquid_orders_must_use_same_quantity',
            static fn (PlaceOrderRequest $stop): PlaceOrderRequest => self::copy($stop, quantity: 2.0),
        ];
        yield 'position side' => [
            'hyperliquid_orders_must_use_same_position_side',
            static fn (PlaceOrderRequest $stop): PlaceOrderRequest => self::copy(
                $stop,
                positionSide: ExchangePositionSide::SHORT,
            ),
        ];
    }

    public function testAcceptsEquivalentNormalizedSymbols(): void
    {
        $action = (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(symbol: ' btc-usdt '),
            $this->stop(symbol: 'BTC/USDC'),
        );

        self::assertCount(2, $action['orders']);
    }

    public function testAcceptsQuantitiesWithTheSameWireDecimal(): void
    {
        $action = (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(quantity: 0.1 + 0.2),
            $this->stop(quantity: 0.3),
        );

        self::assertSame('0.3', $action['orders'][0]['s']);
        self::assertSame('0.3', $action['orders'][1]['s']);
    }

    public function testBuildsShortPositionTpsl(): void
    {
        $action = (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(side: ExchangeOrderSide::SELL, positionSide: ExchangePositionSide::SHORT),
            $this->stop(
                side: ExchangeOrderSide::BUY,
                positionSide: ExchangePositionSide::SHORT,
                stopPrice: 102.0,
            ),
        );

        self::assertFalse($action['orders'][0]['b']);
        self::assertTrue($action['orders'][1]['b']);
        self::assertSame('102', $action['orders'][1]['t']['trigger']['triggerPx']);
    }

    public function testRequiresHyperliquidPerpetualOrders(): void
    {
        $this->expectExceptionMessage('hyperliquid_orders_require_hyperliquid_perpetual');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(exchange: Exchange::OKX),
            $this->stop(exchange: Exchange::OKX),
        );
    }

    public function testRejectsEntrySideIncompatibleWithPosition(): void
    {
        $this->expectExceptionMessage('hyperliquid_entry_side_incompatible_with_position');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(side: ExchangeOrderSide::SELL),
            $this->stop(side: ExchangeOrderSide::BUY),
        );
    }

    public function testRejectsAttachedProtectionThroughOrderBuilder(): void
    {
        $this->expectExceptionMessage('Hyperliquid adapter does not support attached TP/SL on entry');

        (new HyperliquidActionFactory())->positionTpsl(
            0,
            $this->entry(attachedTakeProfitPrice: 103.0),
            $this->stop(),
        );
    }

    public function testBuildsEmergencyCloseAsSingleReduceOnlyIocOrder(): void
    {
        $action = (new HyperliquidActionFactory())->emergencyClose(3, $this->emergencyClose());

        self::assertSame('order', $action['type']);
        self::assertSame('na', $action['grouping']);
        self::assertCount(1, $action['orders']);
        self::assertSame(3, $action['orders'][0]['a']);
        self::assertFalse($action['orders'][0]['b']);
        self::assertSame('1.25', $action['orders'][0]['s']);
        self::assertSame('95.5', $action['orders'][0]['p']);
        self::assertTrue($action['orders'][0]['r']);
        self::assertSame(['limit' => ['tif' => 'Ioc']], $action['orders'][0]['t']);
    }

    public function testBuildsShortEmergencyCloseAsBuyIoc(): void
    {
        $action = (new HyperliquidActionFactory())->emergencyClose(
            3,
            self::makeEmergencyClose(
                side: ExchangeOrderSide::BUY,
                positionSide: ExchangePositionSide::SHORT,
            ),
        );

        self::assertTrue($action['orders'][0]['b']);
        self::assertTrue($action['orders'][0]['r']);
        self::assertSame(['limit' => ['tif' => 'Ioc']], $action['orders'][0]['t']);
    }

    #[DataProvider('invalidEmergencyCloseProvider')]
    public function testRejectsInvalidEmergencyCloseRequest(string $message, PlaceOrderRequest $request): void
    {
        $this->expectExceptionMessage($message);

        (new HyperliquidActionFactory())->emergencyClose(0, $request);
    }

    /**
     * @return iterable<string, array{string, PlaceOrderRequest}>
     */
    public static function invalidEmergencyCloseProvider(): iterable
    {
        $valid = self::makeEmergencyClose();

        yield 'non-reduce-only' => [
            'hyperliquid_emergency_close_must_be_reduce_only',
            self::copy($valid, reduceOnly: false),
        ];
        yield 'not market' => [
            'hyperliquid_emergency_close_must_be_market',
            self::copy($valid, orderType: ExchangeOrderType::LIMIT),
        ];
        yield 'not IOC' => [
            'hyperliquid_emergency_close_must_be_ioc',
            self::copy($valid, timeInForce: ExchangeTimeInForce::GTC),
        ];
        yield 'post-only' => [
            'hyperliquid_emergency_close_must_not_be_post_only',
            self::copy($valid, postOnly: true),
        ];
        yield 'non-finite quantity' => [
            'hyperliquid_emergency_close_requires_positive_finite_quantity',
            self::copy($valid, quantity: NAN),
        ];
        yield 'missing price' => [
            'hyperliquid_emergency_close_requires_positive_finite_slippage_cap_price',
            self::copy($valid, price: null, replacePrice: true),
        ];
        yield 'non-finite price' => [
            'hyperliquid_emergency_close_requires_positive_finite_slippage_cap_price',
            self::copy($valid, price: INF, replacePrice: true),
        ];
        yield 'wrong closing side' => [
            'hyperliquid_emergency_close_must_close_position_side',
            self::copy($valid, side: ExchangeOrderSide::BUY),
        ];
        yield 'wrong exchange' => [
            'hyperliquid_orders_require_hyperliquid_perpetual',
            self::copy($valid, exchange: Exchange::OKX),
        ];
        yield 'wrong market' => [
            'hyperliquid_orders_require_hyperliquid_perpetual',
            self::copy($valid, marketType: MarketType::SPOT),
        ];
    }

    public function testPlaceOrderRequestRejectsNonPositiveEmergencyQuantityAndPrice(): void
    {
        $this->expectExceptionMessage('quantity must be greater than zero');

        self::makeEmergencyClose(quantity: 0.0, price: -1.0);
    }

    private function entry(
        Exchange $exchange = Exchange::HYPERLIQUID,
        MarketType $marketType = MarketType::PERPETUAL,
        string $symbol = 'BTCUSDT',
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        float $quantity = 1.25,
        ?float $price = 100.0,
        bool $reduceOnly = false,
        string $clientOrderId = 'entry-1',
        ?float $attachedTakeProfitPrice = null,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: $exchange,
            marketType: $marketType,
            symbol: $symbol,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: $price,
            stopPrice: null,
            reduceOnly: $reduceOnly,
            postOnly: false,
            leverage: 3,
            marginMode: 'cross',
            clientOrderId: $clientOrderId,
            attachedTakeProfitPrice: $attachedTakeProfitPrice,
        );
    }

    private function stop(
        Exchange $exchange = Exchange::HYPERLIQUID,
        MarketType $marketType = MarketType::PERPETUAL,
        string $symbol = 'BTCUSDT',
        ExchangeOrderSide $side = ExchangeOrderSide::SELL,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
        ExchangeOrderType $orderType = ExchangeOrderType::STOP_LOSS,
        float $quantity = 1.25,
        ?float $stopPrice = 98.0,
        bool $reduceOnly = true,
        string $clientOrderId = 'stop-1',
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: $exchange,
            marketType: $marketType,
            symbol: $symbol,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: null,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: false,
            leverage: 3,
            marginMode: 'cross',
            clientOrderId: $clientOrderId,
        );
    }

    private function emergencyClose(): PlaceOrderRequest
    {
        return self::makeEmergencyClose();
    }

    private static function makeEmergencyClose(
        float $quantity = 1.25,
        ?float $price = 95.5,
        ExchangeOrderSide $side = ExchangeOrderSide::SELL,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            positionSide: $positionSide,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::IOC,
            quantity: $quantity,
            price: $price,
            stopPrice: null,
            reduceOnly: true,
            postOnly: false,
            leverage: 3,
            marginMode: 'cross',
            clientOrderId: 'emergency-close-1',
        );
    }

    private static function copy(
        PlaceOrderRequest $request,
        ?Exchange $exchange = null,
        ?MarketType $marketType = null,
        ?string $symbol = null,
        ?ExchangeOrderSide $side = null,
        ?ExchangePositionSide $positionSide = null,
        ?ExchangeOrderType $orderType = null,
        ?ExchangeTimeInForce $timeInForce = null,
        ?float $quantity = null,
        ?float $price = null,
        bool $replacePrice = false,
        ?bool $reduceOnly = null,
        ?bool $postOnly = null,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: $exchange ?? $request->exchange,
            marketType: $marketType ?? $request->marketType,
            symbol: $symbol ?? $request->symbol,
            side: $side ?? $request->side,
            positionSide: $positionSide ?? $request->positionSide,
            orderType: $orderType ?? $request->orderType,
            timeInForce: $timeInForce ?? $request->timeInForce,
            quantity: $quantity ?? $request->quantity,
            price: $replacePrice ? $price : $request->price,
            stopPrice: $request->stopPrice,
            reduceOnly: $reduceOnly ?? $request->reduceOnly,
            postOnly: $postOnly ?? $request->postOnly,
            leverage: $request->leverage,
            marginMode: $request->marginMode,
            clientOrderId: $request->clientOrderId,
            attachedStopLossPrice: $request->attachedStopLossPrice,
            attachedTakeProfitPrice: $request->attachedTakeProfitPrice,
            metadata: $request->metadata,
        );
    }
}
