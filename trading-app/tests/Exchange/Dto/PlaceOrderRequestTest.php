<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceOrderRequest::class)]
final class PlaceOrderRequestTest extends TestCase
{
    public function testCreatesTypedBusinessOrderIntent(): void
    {
        $request = $this->createRequest();

        self::assertSame(Exchange::BITMART, $request->exchange);
        self::assertSame(MarketType::PERPETUAL, $request->marketType);
        self::assertSame('BTCUSDT', $request->symbol);
        self::assertSame(ExchangeOrderSide::BUY, $request->side);
        self::assertSame(ExchangePositionSide::LONG, $request->positionSide);
        self::assertSame(ExchangeOrderType::LIMIT, $request->orderType);
        self::assertSame(ExchangeTimeInForce::GTC, $request->timeInForce);
        self::assertSame('cid-1', $request->clientOrderId);
    }

    public function testRejectsBlankClientOrderId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clientOrderId cannot be blank');

        $this->createRequest(clientOrderId: ' ');
    }

    public function testLimitOrderRequiresPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit orders require a positive price');

        $this->createRequest(price: null);
    }

    private function createRequest(?float $price = 25000.0, string $clientOrderId = 'cid-1'): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 10.0,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
        );
    }
}
