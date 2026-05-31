<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\OkxExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(OkxExchangeAdapter::class)]
#[CoversClass(OkxActionFactory::class)]
#[CoversClass(OkxInstrumentResolver::class)]
#[CoversClass(OkxConfig::class)]
final class OkxExchangeAdapterTest extends TestCase
{
    public function testCapabilitiesAdvertiseDemoAndProtectionBoundaries(): void
    {
        $capabilities = $this->adapter()->capabilities();

        self::assertTrue($capabilities->supportsTestnet);
        self::assertTrue($capabilities->supportsClientOrderId);
        self::assertTrue($capabilities->supportsCancelByClientOrderId);
        self::assertFalse($capabilities->supportsAttachedStopLossOnEntry);
        self::assertTrue($capabilities->supportsTriggerOrders);
        self::assertFalse($capabilities->supportsModifyOrder);
    }

    public function testBuildsLimitOrderAndMapsAcceptedResponse(): void
    {
        $client = new FakeOkxClient();
        $adapter = $this->adapter($client);

        $result = $adapter->placeOrder($this->limitRequest());

        self::assertTrue($result->accepted);
        self::assertSame('12345', $result->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::PENDING, $result->status);
        self::assertSame('/api/v5/trade/order', $client->lastPostPath);
        self::assertSame('BTC-USDT-SWAP', $client->lastPostBody['instId'] ?? null);
        self::assertSame('isolated', $client->lastPostBody['tdMode'] ?? null);
        self::assertSame('buy', $client->lastPostBody['side'] ?? null);
        self::assertSame('long', $client->lastPostBody['posSide'] ?? null);
        self::assertSame('post_only', $client->lastPostBody['ordType'] ?? null);
        self::assertSame($this->expectedClientOrderId('cid-okx-1'), $client->lastPostBody['clOrdId'] ?? null);
    }

    public function testBuildsStopLossAlgoAndMapsPendingAlgoOrder(): void
    {
        $client = new FakeOkxClient();
        $adapter = $this->adapter($client);

        $result = $adapter->placeOrder($this->stopLossRequest());

        self::assertTrue($result->accepted);
        self::assertSame('algo:90001', $result->exchangeOrderId);
        self::assertSame('/api/v5/trade/order-algo', $client->lastPostPath);
        self::assertSame('conditional', $client->lastPostBody['ordType'] ?? null);
        self::assertSame('24800', $client->lastPostBody['slTriggerPx'] ?? null);
        self::assertSame('-1', $client->lastPostBody['slOrdPx'] ?? null);
        self::assertSame('true', $client->lastPostBody['reduceOnly'] ?? null);

        $stopOrders = array_values(array_filter(
            $adapter->getOpenOrders('BTCUSDT'),
            static fn ($order): bool => $order->orderType === ExchangeOrderType::STOP_LOSS,
        ));

        self::assertCount(1, $stopOrders);
        self::assertSame('algo:90001', $stopOrders[0]->exchangeOrderId);
        self::assertSame(ExchangeOrderSide::SELL, $stopOrders[0]->side);
        self::assertSame(ExchangePositionSide::LONG, $stopOrders[0]->positionSide);
        self::assertTrue($stopOrders[0]->reduceOnly);
        self::assertEqualsWithDelta(24800.0, $stopOrders[0]->stopPrice, 0.000001);
    }

    public function testMapsRestSnapshots(): void
    {
        $adapter = $this->adapter();

        $top = $adapter->getOrderBookTop('BTCUSDT');
        $balances = $adapter->getBalances();
        $positions = $adapter->getOpenPositions('BTCUSDT');
        $fills = $adapter->getFillsSnapshot('BTCUSDT');

        self::assertSame(24999.5, $top->bid);
        self::assertSame(25000.5, $top->ask);
        self::assertCount(1, $balances);
        self::assertSame('USDT', $balances[0]->currency);
        self::assertCount(1, $positions);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertSame(0.2, $positions[0]->size);
        self::assertCount(1, $fills);
        self::assertSame('fill-1', $fills[0]->fillId);
        self::assertSame(ExchangeOrderSide::BUY, $fills[0]->side);
    }

    public function testCancelsNormalAndAlgoOrders(): void
    {
        $client = new FakeOkxClient();
        $adapter = $this->adapter($client);

        $normal = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: null,
            clientOrderId: 'cid-okx-1',
        ));
        self::assertTrue($normal->cancelled);
        self::assertSame('/api/v5/trade/cancel-order', $client->lastPostPath);
        self::assertSame($this->expectedClientOrderId('cid-okx-1'), $client->lastPostBody['clOrdId'] ?? null);

        $algo = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'algo:90001',
            clientOrderId: 'cid-okx-sl',
        ));
        self::assertTrue($algo->cancelled);
        self::assertSame('/api/v5/trade/cancel-algos', $client->lastPostPath);
        self::assertSame('90001', $client->lastPostBody[0]['algoId'] ?? null);

        $algoByClientId = $adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: null,
            clientOrderId: 'OKXSL',
        ));
        self::assertTrue($algoByClientId->cancelled);
        self::assertSame('/api/v5/trade/cancel-algos', $client->lastPostPath);
        self::assertSame('OKXSL', $client->lastPostBody[0]['algoClOrdId'] ?? null);
    }

    public function testLiveAndDemoTradingAreDisabledByDefault(): void
    {
        $live = new OkxConfig(environment: 'live');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('live trading is disabled');
        $live->assertLiveAllowed();
    }

    public function testDemoTradingRequiresExplicitFlag(): void
    {
        $demo = new OkxConfig(
            environment: 'demo',
            apiKey: 'test-key',
            apiSecret: 'test-secret',
            apiPassphrase: 'test-passphrase',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('demo trading is disabled');
        $demo->assertTradingConfigured();
    }

    private function adapter(?FakeOkxClient $client = null): OkxExchangeAdapter
    {
        $client ??= new FakeOkxClient();

        return new OkxExchangeAdapter(
            $client,
            new OkxInstrumentResolver(),
            new OkxActionFactory(),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                demoTradingEnabled: true,
            ),
            $this->fixedClock(),
        );
    }

    private function limitRequest(): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
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
            clientOrderId: 'cid-okx-1',
        );
    }

    private function stopLossRequest(): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
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
            clientOrderId: 'cid-okx-sl',
        );
    }

    private function expectedClientOrderId(string $clientOrderId): string
    {
        return 'OKX' . substr(strtoupper(hash('sha256', $clientOrderId)), 0, 29);
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

final class FakeOkxClient implements OkxRestClientInterface
{
    public ?string $lastPostPath = null;

    /** @var array<mixed> */
    public array $lastPostBody = [];

    public function publicGet(string $path, array $query = []): array
    {
        if ($path === '/api/v5/market/books') {
            return ['code' => '0', 'data' => [[
                'bids' => [['24999.5', '1']],
                'asks' => [['25000.5', '1']],
            ]]];
        }

        return ['code' => '0', 'data' => []];
    }

    public function privateGet(string $path, array $query = []): array
    {
        return match ($path) {
            '/api/v5/account/balance' => ['code' => '0', 'data' => [[
                'details' => [[
                    'ccy' => 'USDT',
                    'availEq' => '1000',
                    'eq' => '1200',
                    'upl' => '100',
                ]],
            ]]],
            '/api/v5/account/positions' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'posSide' => 'long',
                'pos' => '0.2',
                'avgPx' => '24500',
                'markPx' => '25000',
                'upl' => '100',
                'margin' => '200',
                'lever' => '3',
                'uTime' => '1767225600000',
            ]],
            ],
            '/api/v5/trade/orders-pending' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'ordId' => '12345',
                'clOrdId' => 'OKX1',
                'side' => 'buy',
                'posSide' => 'long',
                'ordType' => 'post_only',
                'state' => 'live',
                'sz' => '0.01',
                'accFillSz' => '0',
                'px' => '25000',
                'reduceOnly' => 'false',
                'cTime' => '1767225600000',
                'uTime' => '1767225600000',
            ]],
            ],
            '/api/v5/trade/orders-algo-pending' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'algoId' => '90001',
                'algoClOrdId' => 'OKXSL',
                'side' => 'sell',
                'posSide' => 'long',
                'ordType' => 'conditional',
                'state' => 'live',
                'sz' => '0.01',
                'slTriggerPx' => '24800',
                'slOrdPx' => '-1',
                'reduceOnly' => 'true',
                'cTime' => '1767225600000',
                'uTime' => '1767225600000',
            ]],
            ],
            '/api/v5/trade/fills' => ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'ordId' => '12345',
                'clOrdId' => 'OKX1',
                'tradeId' => 'fill-1',
                'side' => 'buy',
                'posSide' => 'long',
                'fillSz' => '0.01',
                'fillPx' => '25000',
                'fee' => '-0.01',
                'feeCcy' => 'USDT',
                'ts' => '1767225600000',
            ]],
            ],
            default => ['code' => '0', 'data' => []],
        };
    }

    public function privatePost(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return match ($path) {
            '/api/v5/trade/order' => ['code' => '0', 'data' => [[
                'ordId' => '12345',
                'clOrdId' => (string)($body['clOrdId'] ?? ''),
                'sCode' => '0',
            ]]],
            '/api/v5/trade/order-algo' => ['code' => '0', 'data' => [[
                'algoId' => '90001',
                'algoClOrdId' => (string)($body['algoClOrdId'] ?? ''),
                'sCode' => '0',
            ]]],
            default => ['code' => '0', 'data' => [['sCode' => '0']]],
        };
    }
}
