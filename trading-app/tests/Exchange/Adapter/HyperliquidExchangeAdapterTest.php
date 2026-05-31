<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\HyperliquidExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(HyperliquidExchangeAdapter::class)]
#[CoversClass(HyperliquidActionFactory::class)]
#[CoversClass(HyperliquidAssetResolver::class)]
#[CoversClass(HyperliquidConfig::class)]
final class HyperliquidExchangeAdapterTest extends TestCase
{
    public function testCapabilitiesAdvertiseTestnetAndClientOrderIds(): void
    {
        $capabilities = $this->adapter()->capabilities();

        self::assertTrue($capabilities->supportsTestnet);
        self::assertTrue($capabilities->supportsClientOrderId);
        self::assertTrue($capabilities->supportsCancelByClientOrderId);
        self::assertFalse($capabilities->supportsAttachedStopLossOnEntry);
        self::assertTrue($capabilities->supportsTriggerOrders);
        self::assertFalse($capabilities->supportsModifyOrder);
    }

    public function testBuildsOrderActionAndMapsAcceptedResponse(): void
    {
        $client = new FakeHyperliquidClient();
        $adapter = $this->adapter($client);

        $result = $adapter->placeOrder($this->placeOrderRequest());

        self::assertTrue($result->accepted);
        self::assertSame('12345', $result->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::PENDING, $result->status);
        self::assertSame('order', $client->lastExchangeAction['type'] ?? null);
        self::assertSame(0, $client->lastExchangeAction['orders'][0]['a'] ?? null);
        self::assertTrue($client->lastExchangeAction['orders'][0]['b'] ?? false);
        self::assertSame('Alo', $client->lastExchangeAction['orders'][0]['t']['limit']['tif'] ?? null);
        self::assertSame($this->expectedCloid('cid-hl-1'), $client->lastExchangeAction['orders'][0]['c'] ?? null);
    }

    public function testMapsCancelByClientOrderId(): void
    {
        $client = new FakeHyperliquidClient();
        $adapter = $this->adapter($client);

        $result = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDC',
            exchangeOrderId: null,
            clientOrderId: 'cid-hl-1',
        ));

        self::assertTrue($result->cancelled);
        self::assertSame('cancelByCloid', $client->lastExchangeAction['type'] ?? null);
        self::assertSame($this->expectedCloid('cid-hl-1'), $client->lastExchangeAction['cancels'][0]['cloid'] ?? null);
    }

    public function testBuildsMarketOrderWithBookDerivedSlippageCap(): void
    {
        $client = new FakeHyperliquidClient();
        $adapter = $this->adapter($client);

        $adapter->placeOrder($this->marketOrderRequest());

        self::assertSame('order', $client->lastExchangeAction['type'] ?? null);
        self::assertSame('26250', $client->lastExchangeAction['orders'][0]['p'] ?? null);
        self::assertSame('Ioc', $client->lastExchangeAction['orders'][0]['t']['limit']['tif'] ?? null);
    }

    public function testBuildsStopLossTriggerAndMapsFrontendOrder(): void
    {
        $client = new FakeHyperliquidClient();
        $adapter = $this->adapter($client);

        $adapter->placeOrder($this->stopLossRequest());

        self::assertSame('order', $client->lastExchangeAction['type'] ?? null);
        self::assertSame('23560', $client->lastExchangeAction['orders'][0]['p'] ?? null);
        self::assertSame('24800', $client->lastExchangeAction['orders'][0]['t']['trigger']['triggerPx'] ?? null);
        self::assertTrue($client->lastExchangeAction['orders'][0]['t']['trigger']['isMarket'] ?? false);
        self::assertSame('sl', $client->lastExchangeAction['orders'][0]['t']['trigger']['tpsl'] ?? null);

        $stopOrders = array_values(array_filter(
            $adapter->getOpenOrders('BTCUSDC'),
            static fn ($order): bool => $order->orderType === ExchangeOrderType::STOP_LOSS,
        ));

        self::assertCount(1, $stopOrders);
        self::assertSame(ExchangeOrderSide::SELL, $stopOrders[0]->side);
        self::assertSame(ExchangePositionSide::LONG, $stopOrders[0]->positionSide);
        self::assertTrue($stopOrders[0]->reduceOnly);
        self::assertEqualsWithDelta(24800.0, $stopOrders[0]->stopPrice, 0.000001);
    }

    public function testMapsOrderBookAndPositions(): void
    {
        $adapter = $this->adapter();

        $top = $adapter->getOrderBookTop('BTCUSDC');
        $positions = $adapter->getOpenPositions('BTCUSDC');

        self::assertSame(24999.5, $top->bid);
        self::assertSame(25000.5, $top->ask);
        self::assertCount(1, $positions);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertSame(0.2, $positions[0]->size);
        self::assertSame(24500.0, $positions[0]->entryPrice);
    }

    public function testMainnetIsDisabledByDefault(): void
    {
        $config = new HyperliquidConfig(environment: 'mainnet');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mainnet is disabled');

        $config->assertMainnetAllowed();
    }

    private function adapter(?FakeHyperliquidClient $client = null): HyperliquidExchangeAdapter
    {
        $client ??= new FakeHyperliquidClient();

        return new HyperliquidExchangeAdapter(
            $client,
            new HyperliquidAssetResolver($client),
            new HyperliquidActionFactory(),
            new HyperliquidConfig(
                environment: 'testnet',
                accountAddress: '0x0000000000000000000000000000000000000001',
                privateKey: 'test-private-key',
            ),
            $this->fixedClock(),
        );
    }

    private function placeOrderRequest(): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDC',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 0.01,
            price: 25000.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-hl-1',
        );
    }

    private function marketOrderRequest(): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDC',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::IOC,
            quantity: 0.01,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-market',
        );
    }

    private function stopLossRequest(): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDC',
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::STOP_LOSS,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 0.01,
            price: null,
            stopPrice: 24800.0,
            reduceOnly: true,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-stop',
        );
    }

    private function expectedCloid(string $clientOrderId): string
    {
        return '0x' . substr(hash('sha256', $clientOrderId), 0, 32);
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
}

final class FakeHyperliquidClient implements HyperliquidRestClientInterface
{
    /** @var array<string,mixed> */
    public array $lastExchangeAction = [];

    public function info(array $request): array
    {
        return match ($request['type'] ?? null) {
            'meta' => ['universe' => [['name' => 'BTC'], ['name' => 'ETH']]],
            'l2Book' => ['levels' => [
                [['px' => '24999.5', 'sz' => '1']],
                [['px' => '25000.5', 'sz' => '1']],
            ]],
            'clearinghouseState' => [
                'withdrawable' => '1000',
                'marginSummary' => ['accountValue' => '1200', 'totalNtlPos' => '5000'],
                'assetPositions' => [[
                    'position' => [
                        'coin' => 'BTC',
                        'szi' => '0.2',
                        'entryPx' => '24500',
                        'markPx' => '25000',
                        'unrealizedPnl' => '100',
                        'marginUsed' => '200',
                        'leverage' => ['value' => 3],
                    ],
                ]],
            ],
            'openOrders', 'frontendOpenOrders' => [[
                'coin' => 'BTC',
                'oid' => 12345,
                'cloid' => '0x70cbb9b0f9837bdbb41f93be2d77a2e7',
                'side' => 'B',
                'sz' => '0.01',
                'origSz' => '0.01',
                'limitPx' => '25000',
                'orderType' => 'Limit',
                'tif' => 'Alo',
                'timestamp' => 1767225600000,
            ], [
                'coin' => 'BTC',
                'oid' => 12346,
                'cloid' => '0x827bdc348063ff94f8839dfff50e121d',
                'side' => 'A',
                'sz' => '0.01',
                'origSz' => '0.01',
                'limitPx' => '23560',
                'orderType' => 'Stop Market',
                'isTrigger' => true,
                'reduceOnly' => true,
                'tif' => 'Gtc',
                'triggerPx' => '24800',
                'triggerCondition' => 'Stop Loss',
                'timestamp' => 1767225600000,
            ]],
            'userFills' => [],
            default => [],
        };
    }

    public function exchange(array $action): array
    {
        $this->lastExchangeAction = $action;

        return [
            'status' => 'ok',
            'response' => [
                'type' => 'order',
                'data' => ['statuses' => [['resting' => ['oid' => 12345]]]],
            ],
        ];
    }
}
