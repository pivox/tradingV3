<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxInstrumentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxActionFactory::class)]
#[CoversClass(OkxInstrumentResolver::class)]
final class OkxMappingTest extends TestCase
{
    public function testInstrumentResolverMapsInternalSymbolsToSwapInstruments(): void
    {
        $resolver = new OkxInstrumentResolver();

        self::assertSame('BTC-USDT-SWAP', $resolver->instId('btcusdt'));
        self::assertSame('ETH-USDC-SWAP', $resolver->instId('ETHUSDC'));
        self::assertSame('BTC-USD-SWAP', $resolver->instId('BTC-USD'));
        self::assertSame('BTC-USDT-SWAP', $resolver->instId('BTC-USDT-SWAP'));
    }

    public function testInstrumentResolverMapsSwapInstrumentsBackToInternalSymbols(): void
    {
        $resolver = new OkxInstrumentResolver();

        self::assertSame('BTCUSDT', $resolver->symbol('BTC-USDT-SWAP'));
        self::assertSame('ETHUSDC', $resolver->symbol('eth-usdc-swap'));
        self::assertSame('BTCUSD', $resolver->symbol('BTC-USD'));
    }

    public function testInstrumentResolverRejectsBlankSymbols(): void
    {
        $resolver = new OkxInstrumentResolver();

        $this->expectException(\InvalidArgumentException::class);

        $resolver->instId(' ');
    }

    public function testInstrumentResolverRejectsUnsupportedSymbols(): void
    {
        $resolver = new OkxInstrumentResolver();

        $this->expectException(\InvalidArgumentException::class);

        $resolver->instId('BTCBTC');
    }

    public function testInstrumentResolverRejectsUnsupportedHyphenatedSymbols(): void
    {
        $resolver = new OkxInstrumentResolver();

        $this->expectException(\InvalidArgumentException::class);

        $resolver->instId('BTC-BTC');
    }

    public function testOrderMappingCoversOrderTypeTimeInForceAndReduceOnlyFields(): void
    {
        $factory = new OkxActionFactory();

        $limit = $factory->order('BTC-USDT-SWAP', $this->request(
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
        ));
        $postOnly = $factory->order('BTC-USDT-SWAP', $this->request(
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            postOnly: true,
        ));
        $ioc = $factory->order('BTC-USDT-SWAP', $this->request(
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::IOC,
        ));
        $fok = $factory->order('BTC-USDT-SWAP', $this->request(
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::FOK,
        ));
        $marketReduce = $factory->order('BTC-USDT-SWAP', $this->request(
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            price: null,
            reduceOnly: true,
        ));

        self::assertSame('limit', $limit['ordType']);
        self::assertSame('25000', $limit['px']);
        self::assertSame('post_only', $postOnly['ordType']);
        self::assertSame('ioc', $ioc['ordType']);
        self::assertSame('fok', $fok['ordType']);
        self::assertSame('market', $marketReduce['ordType']);
        self::assertArrayNotHasKey('px', $marketReduce);
        self::assertSame('sell', $marketReduce['side']);
        self::assertSame('long', $marketReduce['posSide']);
        self::assertSame('true', $marketReduce['reduceOnly']);
    }

    public function testAlgoOrderMappingSeparatesStopLossAndTakeProfitFields(): void
    {
        $factory = new OkxActionFactory();

        $stopLoss = $factory->algoOrder('BTC-USDT-SWAP', $this->request(
            side: ExchangeOrderSide::SELL,
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            stopPrice: 24800.0,
            reduceOnly: true,
        ));
        $takeProfit = $factory->algoOrder('BTC-USDT-SWAP', $this->request(
            side: ExchangeOrderSide::SELL,
            orderType: ExchangeOrderType::TAKE_PROFIT,
            price: null,
            stopPrice: 26000.0,
            reduceOnly: true,
        ));
        $shortStopLoss = $factory->algoOrder('BTC-USDT-SWAP', $this->request(
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::SHORT,
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            stopPrice: 25200.0,
            reduceOnly: true,
        ));

        self::assertSame('conditional', $stopLoss['ordType']);
        self::assertSame('sell', $stopLoss['side']);
        self::assertSame('long', $stopLoss['posSide']);
        self::assertSame('true', $stopLoss['reduceOnly']);
        self::assertSame('24800', $stopLoss['slTriggerPx']);
        self::assertSame('-1', $stopLoss['slOrdPx']);
        self::assertArrayNotHasKey('tpTriggerPx', $stopLoss);
        self::assertSame('sell', $takeProfit['side']);
        self::assertSame('long', $takeProfit['posSide']);
        self::assertSame('true', $takeProfit['reduceOnly']);
        self::assertSame('26000', $takeProfit['tpTriggerPx']);
        self::assertSame('-1', $takeProfit['tpOrdPx']);
        self::assertArrayNotHasKey('slTriggerPx', $takeProfit);
        self::assertSame('buy', $shortStopLoss['side']);
        self::assertSame('short', $shortStopLoss['posSide']);
        self::assertSame('true', $shortStopLoss['reduceOnly']);
        self::assertSame('25200', $shortStopLoss['slTriggerPx']);
    }

    public function testClientOrderIdPreservesOkxCompatibleIdsAndHashesUnsafeIds(): void
    {
        $factory = new OkxActionFactory();
        $unsafe = 'decision:key:with:colons:and-way-too-many-characters';
        $normalized = $factory->clientOrderId($unsafe);

        self::assertSame('SAFE123', $factory->clientOrderId('SAFE123'));
        self::assertSame($normalized, $factory->clientOrderId($unsafe));
        self::assertNotSame($unsafe, $normalized);
        self::assertLessThanOrEqual(32, strlen($normalized));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $normalized);
    }

    private function request(
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ExchangeTimeInForce $timeInForce = ExchangeTimeInForce::GTC,
        ?float $price = 25000.0,
        ?float $stopPrice = null,
        bool $reduceOnly = false,
        bool $postOnly = false,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: $timeInForce,
            quantity: 0.01,
            price: $price,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-okx-mapping',
        );
    }
}
