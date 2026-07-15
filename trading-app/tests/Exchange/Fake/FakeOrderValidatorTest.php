<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeInstrument;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeInstrumentProviderInterface;
use App\Exchange\Fake\FakeOrderValidationResult;
use App\Exchange\Fake\FakeOrderValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeInstrument::class)]
#[CoversClass(FakeOrderValidationResult::class)]
#[CoversClass(FakeOrderValidator::class)]
final class FakeOrderValidatorTest extends TestCase
{
    /**
     * @return iterable<string,array{PlaceOrderRequest,float,float,string}>
     */
    public static function rejectionCases(): iterable
    {
        yield 'unknown instrument wins before market validation' => [
            self::request(symbol: 'SOLUSDT', marketType: MarketType::SPOT),
            25000.0,
            1000.0,
            'instrument_unknown',
        ];
        yield 'unsupported market' => [
            self::request(marketType: MarketType::SPOT),
            25000.0,
            1000.0,
            'market_type_not_supported',
        ];
        yield 'unsupported order type' => [
            self::request(orderType: ExchangeOrderType::TRIGGER, price: null),
            25000.0,
            1000.0,
            'order_type_not_supported',
        ];
        yield 'limit price is never rounded to the tick' => [
            self::request(price: 25000.11),
            25000.0,
            1000.0,
            'price_not_quantized',
        ];
        yield 'NaN price fails closed' => [
            self::request(price: NAN),
            25000.0,
            1000.0,
            'price_not_quantized',
        ];
        yield 'infinite price fails closed' => [
            self::request(price: INF),
            25000.0,
            1000.0,
            'price_not_quantized',
        ];
        yield 'stop price is never rounded to the tick' => [
            self::request(
                orderType: ExchangeOrderType::STOP_LOSS,
                price: null,
                stopPrice: 24999.99,
                reduceOnly: true,
            ),
            25000.0,
            0.0,
            'stop_price_not_quantized',
        ];
        yield 'NaN stop price fails closed' => [
            self::request(orderType: ExchangeOrderType::STOP_LOSS, price: null, stopPrice: NAN),
            25000.0,
            1000.0,
            'stop_price_not_quantized',
        ];
        yield 'infinite stop price fails closed' => [
            self::request(orderType: ExchangeOrderType::STOP_LOSS, price: null, stopPrice: INF),
            25000.0,
            1000.0,
            'stop_price_not_quantized',
        ];
        yield 'quantity is never rounded to the step' => [
            self::request(quantity: 0.0011),
            25000.0,
            1000.0,
            'quantity_not_quantized',
        ];
        yield 'quantity beyond fourteen decimals is never rounded to the step' => [
            self::request(quantity: 0.0010000000000001),
            25000.0,
            1000.0,
            'quantity_not_quantized',
        ];
        yield 'sub-step quantity is rejected before minimum check' => [
            self::request(quantity: 0.000000000000001),
            25000.0,
            1000.0,
            'quantity_not_quantized',
        ];
        yield 'NaN quantity fails closed' => [
            self::request(quantity: NAN),
            25000.0,
            1000.0,
            'quantity_not_quantized',
        ];
        yield 'infinite quantity fails closed' => [
            self::request(quantity: INF),
            25000.0,
            1000.0,
            'quantity_not_quantized',
        ];
        yield 'market notional uses reference price' => [
            self::request(orderType: ExchangeOrderType::MARKET, price: null, quantity: 0.001),
            4000.0,
            1000.0,
            'notional_below_minimum',
        ];
        yield 'NaN reference price fails closed' => [
            self::request(orderType: ExchangeOrderType::MARKET, price: null),
            NAN,
            1000.0,
            'notional_below_minimum',
        ];
        yield 'infinite reference price fails closed' => [
            self::request(orderType: ExchangeOrderType::MARKET, price: null),
            INF,
            1000.0,
            'notional_below_minimum',
        ];
        yield 'zero reference price fails closed' => [
            self::request(orderType: ExchangeOrderType::MARKET, price: null),
            0.0,
            1000.0,
            'notional_below_minimum',
        ];
        yield 'negative reference price fails closed' => [
            self::request(orderType: ExchangeOrderType::MARKET, price: null),
            -1.0,
            1000.0,
            'notional_below_minimum',
        ];
        yield 'leverage above instrument cap' => [
            self::request(leverage: 101),
            25000.0,
            1000.0,
            'leverage_above_maximum',
        ];
        yield 'unsupported margin mode' => [
            self::request(marginMode: 'portfolio'),
            25000.0,
            1000.0,
            'margin_mode_not_supported',
        ];
        yield 'insufficient available margin' => [
            self::request(quantity: 0.001, leverage: null),
            25000.0,
            24.99,
            'insufficient_balance',
        ];
        yield 'NaN available margin fails closed' => [
            self::request(),
            25000.0,
            NAN,
            'insufficient_balance',
        ];
        yield 'infinite available margin fails closed' => [
            self::request(),
            25000.0,
            INF,
            'insufficient_balance',
        ];
        yield 'negative available margin fails closed' => [
            self::request(),
            25000.0,
            -1.0,
            'insufficient_balance',
        ];
    }

    #[DataProvider('rejectionCases')]
    public function testRejectsWithStableOrderedReason(
        PlaceOrderRequest $request,
        float $referencePrice,
        float $availableMargin,
        string $reason,
    ): void {
        $result = $this->validator()->validate($request, $referencePrice, $availableMargin);

        self::assertFalse($result->accepted);
        self::assertSame($reason, $result->reason);
        self::assertSame([], $result->metadata);
        self::assertTrue((new \ReflectionClass($result))->isReadOnly());
    }

    public function testAcceptsExactLimitOrderForBothSupportedMarginModes(): void
    {
        $validator = $this->validator();

        foreach (['isolated', 'cross'] as $marginMode) {
            $result = $validator->validate(
                self::request(price: 25000.0, quantity: 0.001, leverage: 10, marginMode: $marginMode),
                26000.0,
                2.50,
            );

            self::assertTrue($result->accepted);
            self::assertNull($result->reason);
            self::assertSame([], $result->metadata);
        }
    }

    public function testValidatesAttachedStopLossPriceWithoutMutatingRequest(): void
    {
        $request = self::request(
            price: 25000.0,
            attachedStopLossPrice: NAN,
            attachedTakeProfitPrice: 25100.0,
        );

        $result = $this->validator()->validate($request, 25000.0, 1000.0);

        self::assertFalse($result->accepted);
        self::assertSame('stop_price_not_quantized', $result->reason);
        self::assertSame(25000.0, $request->price);
        self::assertNan($request->attachedStopLossPrice);
        self::assertSame(25100.0, $request->attachedTakeProfitPrice);
    }

    public function testValidatesAttachedTakeProfitPriceWithoutMutatingRequest(): void
    {
        $request = self::request(
            price: 25000.0,
            attachedStopLossPrice: 24900.0,
            attachedTakeProfitPrice: INF,
        );

        $result = $this->validator()->validate($request, 25000.0, 1000.0);

        self::assertFalse($result->accepted);
        self::assertSame('stop_price_not_quantized', $result->reason);
        self::assertSame(25000.0, $request->price);
        self::assertSame(24900.0, $request->attachedStopLossPrice);
        self::assertInfinite($request->attachedTakeProfitPrice);
    }

    public function testAcceptsExactlyQuantizedPriceFromJsonDecimal(): void
    {
        $result = $this->validator()->validate(
            self::request(price: 25000.1),
            25000.0,
            1000.0,
        );

        self::assertTrue($result->accepted);
    }

    public function testDoesNotRoundNonQuantizedPriceToTick(): void
    {
        $result = $this->validator()->validate(
            self::request(price: 25000.100000000002),
            25000.0,
            1000.0,
        );

        self::assertFalse($result->accepted);
        self::assertSame('price_not_quantized', $result->reason);
    }

    public function testDoesNotRoundAvailableMarginUp(): void
    {
        $result = $this->validator()->validate(
            self::request(price: 25000.0, quantity: 0.001, leverage: null),
            25000.0,
            24.999999999999996,
        );

        self::assertFalse($result->accepted);
        self::assertSame('insufficient_balance', $result->reason);
    }

    #[DataProvider('invalidAvailableMarginCases')]
    public function testInvalidAvailableMarginFailsClosedWithoutInitialMargin(float $availableMargin): void
    {
        $result = $this->validator()->validate(
            self::request(reduceOnly: true),
            25000.0,
            $availableMargin,
        );

        self::assertFalse($result->accepted);
        self::assertSame('insufficient_balance', $result->reason);
    }

    /** @return iterable<string,array{float}> */
    public static function invalidAvailableMarginCases(): iterable
    {
        yield 'NaN' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative value' => [-1.0];
    }

    public function testLimitNotionalUsesLimitPriceInsteadOfReferencePrice(): void
    {
        $result = $this->validator()->validate(
            self::request(price: 5000.0, quantity: 0.001, leverage: 10),
            4000.0,
            0.50,
        );

        self::assertTrue($result->accepted);
    }

    public function testRejectsQuantizedQuantityBelowProviderMinimum(): void
    {
        $result = $this->validatorForInstrument(self::instrument(
            quantityStep: '0.01',
            minQuantity: '0.10',
        ))->validate(
            self::request(symbol: 'TESTUSDT', price: 400.0, quantity: 0.01),
            400.0,
            1000.0,
        );

        self::assertFalse($result->accepted);
        self::assertSame('quantity_below_minimum', $result->reason);
    }

    public function testProviderContractSizeContributesToNotional(): void
    {
        $result = $this->validatorForInstrument(self::instrument(
            quantityStep: '0.01',
            minQuantity: '0.01',
            contractSize: '0.10',
        ))->validate(
            self::request(symbol: 'TESTUSDT', price: 400.0, quantity: 0.10, leverage: 10),
            400.0,
            1000.0,
        );

        self::assertFalse($result->accepted);
        self::assertSame('notional_below_minimum', $result->reason);
    }

    public function testReduceOnlyAndProtectionOrdersDoNotRequireInitialMargin(): void
    {
        $validator = $this->validator();
        $requests = [
            self::request(quantity: 0.001, reduceOnly: true, leverage: null),
            self::request(
                orderType: ExchangeOrderType::STOP_LOSS,
                price: null,
                quantity: 0.001,
                stopPrice: 24900.0,
                reduceOnly: false,
                leverage: null,
            ),
            self::request(
                orderType: ExchangeOrderType::TAKE_PROFIT,
                price: null,
                quantity: 0.001,
                stopPrice: 25100.0,
                reduceOnly: false,
                leverage: null,
            ),
        ];

        foreach ($requests as $request) {
            self::assertTrue($validator->validate($request, 25000.0, 0.0)->accepted);
        }
    }

    private function validator(): FakeOrderValidator
    {
        return new FakeOrderValidator(new FakeInstrumentCatalog());
    }

    private function validatorForInstrument(FakeInstrument $instrument): FakeOrderValidator
    {
        $provider = new class($instrument) implements FakeInstrumentProviderInterface {
            public function __construct(private readonly FakeInstrument $instrument)
            {
            }

            public function find(string $symbol): ?FakeInstrument
            {
                return $symbol === $this->instrument->symbol ? $this->instrument : null;
            }
        };

        return new FakeOrderValidator($provider);
    }

    private static function instrument(
        string $quantityStep,
        string $minQuantity,
        string $contractSize = '1',
    ): FakeInstrument {
        return new FakeInstrument(
            symbol: 'TESTUSDT',
            marketType: MarketType::PERPETUAL,
            baseAsset: 'TEST',
            quoteAsset: 'USDT',
            settleAsset: 'USDT',
            priceTick: '0.01',
            quantityStep: $quantityStep,
            minQuantity: $minQuantity,
            minNotional: '5',
            contractSize: $contractSize,
            maxLeverage: 20,
            maintenanceMarginRate: '0.005',
            allowedOrderTypes: [ExchangeOrderType::LIMIT],
        );
    }

    private static function request(
        string $symbol = 'BTCUSDT',
        MarketType $marketType = MarketType::PERPETUAL,
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ?float $price = 25000.0,
        float $quantity = 0.001,
        ?float $stopPrice = null,
        bool $reduceOnly = false,
        ?int $leverage = 10,
        string $marginMode = 'isolated',
        ?float $attachedStopLossPrice = null,
        ?float $attachedTakeProfitPrice = null,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: $marketType,
            symbol: $symbol,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: false,
            leverage: $leverage,
            marginMode: $marginMode,
            clientOrderId: 'validator-test',
            attachedStopLossPrice: $attachedStopLossPrice,
            attachedTakeProfitPrice: $attachedTakeProfitPrice,
        );
    }
}
