<?php

declare(strict_types=1);

namespace App\Tests\Provider\Okx;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Provider\Okx\OkxAccountGateway;
use App\Provider\Okx\OkxOrderGateway;
use App\Provider\Okx\OkxPositionGateway;
use App\Provider\Okx\OkxProviderNotReadyException;
use App\Provider\Okx\OkxProviderUnavailableException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OkxPrivateReadProviderTest extends TestCase
{
    public function testReadsAccountBalanceWithoutSecretsInMetadata(): void
    {
        $gateway = new OkxAccountGateway($this->client());

        $account = $gateway->getAccountInfo();

        self::assertNotNull($account);
        self::assertSame('USDT', $account->currency);
        self::assertSame('100.5', (string) $account->availableBalance);
        self::assertSame('120.75', (string) $account->equity);
        self::assertSame('3.25', (string) $account->unrealized);
        self::assertArrayNotHasKey('apiKey', $account->metadata);
        self::assertSame(100.5, $gateway->getAccountBalance('USDT'));
    }

    public function testReadsAccountBalanceFromFallbackWhenPrimaryFieldIsEmpty(): void
    {
        $client = $this->client();
        $client->emptyAvailableEquity = true;
        $gateway = new OkxAccountGateway($client);

        $account = $gateway->getAccountInfo();

        self::assertNotNull($account);
        self::assertSame('99.5', (string) $account->availableBalance);
        self::assertSame(99.5, $gateway->getAccountBalance('USDT'));
    }

    public function testReadsOpenPositions(): void
    {
        $gateway = new OkxPositionGateway($this->client());

        $positions = $gateway->getOpenPositions('BTCUSDT');

        self::assertCount(1, $positions);
        self::assertSame('BTCUSDT', $positions[0]->symbol);
        self::assertSame(PositionSide::LONG, $positions[0]->side);
        self::assertSame('0.25', (string) $positions[0]->size);
        self::assertSame('25100', (string) $positions[0]->entryPrice);
        self::assertSame('25200', (string) $positions[0]->markPrice);
        self::assertSame('12.5', (string) $positions[0]->unrealizedPnl);
        self::assertSame('3', (string) $positions[0]->leverage);
    }

    public function testOpenPositionsIsTolerantAndOrFailPropagates(): void
    {
        $gateway = new OkxPositionGateway();

        self::assertSame([], $gateway->getOpenPositions('BTCUSDT'));

        $this->expectException(OkxProviderNotReadyException::class);
        $gateway->getOpenPositionsOrFail('BTCUSDT');
    }

    public function testReadsOpenOrdersWithoutUsingWriteEndpoint(): void
    {
        $client = $this->client();
        $gateway = new OkxOrderGateway($client);

        $orders = $gateway->getOpenOrdersOrFail('BTCUSDT');

        self::assertCount(2, $orders);
        self::assertSame('ord-1', $orders[0]->orderId);
        self::assertSame(OrderSide::BUY, $orders[0]->side);
        self::assertSame(OrderType::LIMIT, $orders[0]->type);
        self::assertSame(OrderStatus::PENDING, $orders[0]->status);
        self::assertSame('1', (string) $orders[0]->quantity);
        self::assertSame('0.4', (string) $orders[0]->filledQuantity);
        self::assertSame('client-1', $orders[0]->metadata['client_order_id'] ?? null);
        self::assertSame('algo:algo-1', $orders[1]->orderId);
        self::assertSame(OrderType::STOP, $orders[1]->type);
        self::assertSame('algo-client-1', $orders[1]->metadata['client_order_id'] ?? null);
        self::assertSame(0, $client->privatePostCalls);
    }

    public function testOpenOrdersIsTolerantAndOrFailPropagates(): void
    {
        $gateway = new OkxOrderGateway();

        self::assertSame([], $gateway->getOpenOrders('BTCUSDT'));

        $this->expectException(OkxProviderNotReadyException::class);
        $gateway->getOpenOrdersOrFail('BTCUSDT');
    }

    public function testFindsOpenOrderByNormalizedClientOrderId(): void
    {
        $gateway = new OkxOrderGateway($this->client());

        $order = $gateway->getOrder('BTCUSDT', 'client-1');
        $algoOrder = $gateway->getOrder('BTCUSDT', 'algo-client-1');

        self::assertNotNull($order);
        self::assertSame('ord-1', $order->orderId);
        self::assertNotNull($algoOrder);
        self::assertSame('algo:algo-1', $algoOrder->orderId);
    }

    public function testFindsTerminalOrderByDetailFallback(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'ord-filled');

        self::assertNotNull($order);
        self::assertSame('ord-filled', $order->orderId);
        self::assertSame(OrderStatus::FILLED, $order->status);
        self::assertSame('/api/v5/trade/order', $client->lastPrivateGetPath);
    }

    public function testReadsHistoricalFills(): void
    {
        $client = $this->client();
        $gateway = new OkxAccountGateway($client);

        $fills = $gateway->getTrades('BTCUSDT');

        self::assertCount(1, $fills);
        self::assertSame('/api/v5/trade/fills', $client->lastPrivateGetPath);
        self::assertSame('BTCUSDT', $fills[0]['symbol'] ?? null);
        self::assertSame('trade-1', $fills[0]['trade_id'] ?? null);
        self::assertSame(2, $fills[0]['open_type'] ?? null);
        self::assertSame('0.4', $fills[0]['size'] ?? null);
        self::assertSame('24990', $fills[0]['price'] ?? null);
        self::assertSame('-0.01', $fills[0]['fee'] ?? null);
        self::assertSame('USDT', $fills[0]['fee_currency'] ?? null);
    }

    public function testUsesHistoricalFillsEndpointForOlderWindows(): void
    {
        $client = $this->client();
        $gateway = new OkxAccountGateway($client);

        $gateway->getTrades('BTCUSDT', 100, time() - (7 * 24 * 60 * 60), time());

        self::assertSame('/api/v5/trade/fills-history', $client->lastPrivateGetPath);
    }

    public function testReadsSwapTradingFeesWithInstrumentFamily(): void
    {
        $client = $this->client();
        $gateway = new OkxAccountGateway($client);

        $fees = $gateway->getTradingFees('BTCUSDT');

        self::assertSame('/api/v5/account/trade-fee', $client->lastPrivateGetPath);
        self::assertSame('BTC-USDT', $client->lastPrivateGetQuery['instFamily'] ?? null);
        self::assertArrayNotHasKey('instId', $client->lastPrivateGetQuery);
        self::assertSame('-0.0002', $fees['maker'] ?? null);
    }

    public function testUsesBillsArchiveEndpointForOlderWindows(): void
    {
        $client = $this->client();
        $gateway = new OkxAccountGateway($client);

        $transactions = $gateway->getTransactionHistory('BTCUSDT', 2, 100, time() - (14 * 24 * 60 * 60), time());

        self::assertSame('/api/v5/account/bills-archive', $client->lastPrivateGetPath);
        self::assertSame(2, $transactions[0]['flow_type'] ?? null);
        self::assertSame('1.23', $transactions[0]['amount'] ?? null);
    }

    public function testWriteMethodsRemainBlocked(): void
    {
        $gateway = new OkxOrderGateway($this->client());

        $this->expectException(OkxProviderNotReadyException::class);
        $this->expectExceptionMessage('okx_order_write_not_implemented');

        $gateway->cancelOrder('BTCUSDT', 'ord-1');
    }

    public function testPrivateApiBodyRateLimitIsPreserved(): void
    {
        $client = $this->client();
        $client->rateLimited = true;
        $gateway = new OkxAccountGateway($client);

        $this->expectException(OkxProviderUnavailableException::class);
        $this->expectExceptionMessage('okx_private_rate_limited');

        $gateway->getAccountInfo();
    }

    private function client(): FakeOkxPrivateReadClient
    {
        return new FakeOkxPrivateReadClient();
    }
}

final class FakeOkxPrivateReadClient implements OkxRestClientInterface
{
    public bool $rateLimited = false;
    public bool $emptyAvailableEquity = false;
    public bool $hidePendingOrders = false;
    public int $privatePostCalls = 0;
    public string $lastPrivateGetPath = '';

    /** @var array<string,mixed> */
    public array $lastPrivateGetQuery = [];

    public function publicGet(string $path, array $query = []): array
    {
        throw new \LogicException('Public OKX read is not part of this private-read fixture.');
    }

    public function privateGet(string $path, array $query = []): array
    {
        $this->lastPrivateGetPath = $path;
        $this->lastPrivateGetQuery = $query;
        if ($this->rateLimited) {
            return ['code' => '50011', 'msg' => 'Too Many Requests', 'data' => []];
        }

        return match ($path) {
            '/api/v5/account/balance' => $this->balance(),
            '/api/v5/account/positions' => $this->positions($query),
            '/api/v5/trade/orders-pending' => $this->orders($query),
            '/api/v5/trade/orders-algo-pending' => $this->algoOrders($query),
            '/api/v5/trade/order' => $this->orderDetail($query),
            '/api/v5/trade/orders-history' => $this->orderDetail($query),
            '/api/v5/trade/fills' => $this->fills($query),
            '/api/v5/trade/fills-history' => $this->fills($query),
            '/api/v5/account/bills' => $this->bills($query),
            '/api/v5/account/bills-archive' => $this->bills($query),
            '/api/v5/account/trade-fee' => $this->tradingFees($query),
            default => ['code' => '404', 'msg' => 'unexpected path ' . $path, 'data' => []],
        };
    }

    public function privatePost(string $path, array $body = []): array
    {
        ++$this->privatePostCalls;

        return ['code' => '0', 'data' => []];
    }

    /**
     * @return array<string,mixed>
     */
    private function balance(): array
    {
        return ['code' => '0', 'data' => [[
            'totalEq' => '120.75',
            'details' => [[
                'ccy' => 'USDT',
                'availEq' => $this->emptyAvailableEquity ? '' : '100.5',
                'availBal' => '99.5',
                'frozenBal' => '1.5',
                'eq' => '120.75',
                'upl' => '3.25',
                'imr' => '10',
                'apiKey' => 'must-not-leak',
            ]],
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function positions(array $query): array
    {
        $this->assertQueryValue($query, 'instType', 'SWAP');
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');

        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'posSide' => 'long',
            'pos' => '0.25',
            'avgPx' => '25100',
            'markPx' => '25200',
            'upl' => '12.5',
            'realizedPnl' => '1.2',
            'imr' => '20',
            'lever' => '3',
            'uTime' => '1767225600000',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function orders(array $query): array
    {
        $this->assertQueryValue($query, 'instType', 'SWAP');
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        if ($this->hidePendingOrders) {
            return ['code' => '0', 'data' => []];
        }

        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'ordId' => 'ord-1',
            'clOrdId' => 'client-1',
            'side' => 'buy',
            'posSide' => 'long',
            'ordType' => 'limit',
            'state' => 'live',
            'sz' => '1',
            'accFillSz' => '0.4',
            'px' => '25000',
            'avgPx' => '24990',
            'reduceOnly' => 'false',
            'cTime' => '1767225600000',
            'uTime' => '1767225660000',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function orderDetail(array $query): array
    {
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        if (($query['ordId'] ?? null) !== 'ord-filled') {
            return ['code' => '0', 'data' => []];
        }

        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'ordId' => 'ord-filled',
            'clOrdId' => 'client-filled',
            'side' => 'buy',
            'posSide' => 'long',
            'ordType' => 'limit',
            'state' => 'filled',
            'sz' => '1',
            'accFillSz' => '1',
            'px' => '25000',
            'avgPx' => '24990',
            'cTime' => '1767225600000',
            'uTime' => '1767225660000',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function algoOrders(array $query): array
    {
        $this->assertQueryValue($query, 'instType', 'SWAP');
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        $this->assertQueryValue($query, 'ordType', 'conditional');

        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'algoId' => 'algo-1',
            'algoClOrdId' => 'algo-client-1',
            'side' => 'sell',
            'posSide' => 'long',
            'ordType' => 'conditional',
            'state' => 'live',
            'sz' => '1',
            'accFillSz' => '0',
            'slTriggerPx' => '24000',
            'reduceOnly' => 'true',
            'cTime' => '1767225600000',
            'uTime' => '1767225660000',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function fills(array $query): array
    {
        $this->assertQueryValue($query, 'instType', 'SWAP');
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');

        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'ordId' => 'ord-1',
            'clOrdId' => 'client-1',
            'tradeId' => 'trade-1',
            'side' => 'sell',
            'posSide' => 'long',
            'fillSz' => '0.4',
            'fillPx' => '24990',
            'fee' => '-0.01',
            'feeCcy' => 'USDT',
            'ts' => '1767225660000',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function tradingFees(array $query): array
    {
        $this->assertQueryValue($query, 'instType', 'SWAP');
        $this->assertQueryValue($query, 'instFamily', 'BTC-USDT');
        if (array_key_exists('instId', $query)) {
            throw new \RuntimeException('OKX SWAP fee query must use instFamily, not instId.');
        }

        return ['code' => '0', 'data' => [[
            'instType' => 'SWAP',
            'instFamily' => 'BTC-USDT',
            'maker' => '-0.0002',
            'taker' => '-0.0005',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function bills(array $query): array
    {
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');

        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'type' => (string) ($query['type'] ?? ''),
            'balChg' => '1.23',
            'ts' => '1767225660000',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     */
    private function assertQueryValue(array $query, string $key, string $expected): void
    {
        $actual = (string) ($query[$key] ?? '');
        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('Expected OKX private query %s=%s, got %s.', $key, $expected, $actual));
        }
    }
}
