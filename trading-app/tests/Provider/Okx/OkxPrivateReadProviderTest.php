<?php

declare(strict_types=1);

namespace App\Tests\Provider\Okx;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Provider\Okx\OkxPrivateReadMapper;
use App\Provider\Okx\OkxAccountGateway;
use App\Provider\Okx\OkxOrderGateway;
use App\Provider\Okx\OkxPositionGateway;
use App\Provider\Okx\OkxProviderNotReadyException;
use App\Provider\Okx\OkxProviderUnavailableException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testHealthCheckAcceptsReadableDemoAccountWithoutBalances(): void
    {
        $client = $this->client();
        $client->emptyBalanceDetails = true;
        $gateway = new OkxAccountGateway($client);

        self::assertTrue($gateway->healthCheck());
        self::assertNull($gateway->getAccountInfo());
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

    /** @param array<string,mixed> $row */
    #[DataProvider('unknownPrivateEnumProvider')]
    public function testUnknownPresentPrivateEnumsFailTheRestMapper(array $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_value_invalid');

        (new OkxPrivateReadMapper())->order($row, false);
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('unknownPrivateEnumProvider')]
    public function testLegacyTradeRejectsUnknownPresentPrivateEnums(array $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_value_invalid');

        (new OkxPrivateReadMapper())->legacyTrade($row);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function missingRequiredOrderEnumProvider(): iterable
    {
        $row = [
            'instId' => 'BTC-USDT-SWAP',
            'ordId' => 'ord-missing',
            'side' => 'buy',
            'ordType' => 'limit',
            'state' => 'live',
            'sz' => '1',
            'accFillSz' => '0',
            'cTime' => '1767225600000',
        ];
        foreach (['side', 'ordType', 'state'] as $field) {
            $candidate = $row;
            unset($candidate[$field]);
            yield $field => [$candidate];
        }
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('missingRequiredOrderEnumProvider')]
    public function testMissingRequiredOrderEnumsFailWithoutImplicitFallback(array $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_rest_snapshot_value_invalid');

        (new OkxPrivateReadMapper())->order($row, false);
    }

    #[DataProvider('terminalAlgoStatusProvider')]
    public function testMapsTerminalAlgoHistoryStates(string $state, OrderStatus $expected): void
    {
        $order = (new OkxPrivateReadMapper())->order([
            'instId' => 'BTC-USDT-SWAP',
            'algoId' => 'algo-terminal',
            'side' => 'sell',
            'ordType' => 'conditional',
            'state' => $state,
            'sz' => '1',
            'accFillSz' => '0',
            'cTime' => '1767225600000',
        ], true);

        self::assertSame($expected, $order->status);
    }

    /** @return iterable<string,array{string,OrderStatus}> */
    public static function terminalAlgoStatusProvider(): iterable
    {
        yield 'effective' => ['effective', OrderStatus::FILLED];
        yield 'order failed' => ['order_failed', OrderStatus::REJECTED];
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function unknownPrivateEnumProvider(): iterable
    {
        $base = [
            'instId' => 'BTC-USDT-SWAP',
            'ordId' => 'ord-unknown',
            'side' => 'buy',
            'ordType' => 'limit',
            'state' => 'live',
            'sz' => '1',
            'accFillSz' => '0',
            'cTime' => '1767225600000',
        ];

        foreach ([
            ['side', 'hold'],
            ['ordType', 'unexpected'],
            ['state', 'garbage'],
            ['posSide', 'unknown'],
            ['tdMode', 'unknown'],
            ['reduceOnly', 'unexpected'],
            ['reduceOnly', ''],
        ] as [$field, $value]) {
            $row = $base;
            $row[$field] = $value;
            yield $field . ':' . (string) $value => [$row];
        }
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

    public function testFindsTerminalOrderByHistoryScanWithoutUnsupportedIdFilters(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $client->hideOrderDetail = true;
        $client->paginateOrderHistory = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'ord-historical');

        self::assertNotNull($order);
        self::assertSame('ord-historical', $order->orderId);
        self::assertSame('/api/v5/trade/orders-history', $client->lastPrivateGetPath);
        self::assertSame('ord-old-1', $client->lastPrivateGetQuery['after'] ?? null);
        self::assertArrayNotHasKey('ordId', $client->lastPrivateGetQuery);
        self::assertArrayNotHasKey('clOrdId', $client->lastPrivateGetQuery);
    }

    public function testOrderDetailNotFoundCodeFallsBackToHistory(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $client->orderDetailReturnsNotFoundCode = true;
        $client->paginateOrderHistory = true;
        $gateway = new OkxOrderGateway($client);

        try {
            $order = $gateway->getOrder('BTCUSDT', 'ord-historical');
        } catch (OkxProviderUnavailableException $exception) {
            self::fail('Order detail not-found code should fall back to history, got: ' . $exception->getMessage());
        }

        self::assertNotNull($order);
        self::assertSame('ord-historical', $order->orderId);
        self::assertSame('/api/v5/trade/orders-history', $client->lastPrivateGetPath);
    }

    public function testFindsArchivedTerminalOrderAfterRecentHistoryMiss(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $client->hideOrderDetail = true;
        $client->useOrderHistoryArchive = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'ord-archived');

        self::assertNotNull($order);
        self::assertSame('ord-archived', $order->orderId);
        self::assertSame('/api/v5/trade/orders-history-archive', $client->lastPrivateGetPath);
        self::assertArrayNotHasKey('ordId', $client->lastPrivateGetQuery);
        self::assertArrayNotHasKey('clOrdId', $client->lastPrivateGetQuery);
    }

    public function testFindsTerminalAlgoOrderByDetailFallback(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'algo:algo-triggered');

        self::assertNotNull($order);
        self::assertSame('algo:algo-triggered', $order->orderId);
        self::assertSame(OrderStatus::CANCELLED, $order->status);
        self::assertSame('/api/v5/trade/order-algo', $client->lastPrivateGetPath);
    }

    public function testFindsTerminalAlgoOrderByClientOrderIdFallback(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'algo-client-triggered');

        self::assertNotNull($order);
        self::assertSame('algo:algo-triggered', $order->orderId);
        self::assertSame(OrderStatus::CANCELLED, $order->status);
        self::assertSame('/api/v5/trade/order-algo', $client->lastPrivateGetPath);
    }

    public function testFindsTerminalAlgoOrderByHistoryFallback(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $client->hideAlgoDetail = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'algo:algo-triggered');

        self::assertNotNull($order);
        self::assertSame('algo:algo-triggered', $order->orderId);
        self::assertSame('/api/v5/trade/orders-algo-history', $client->lastPrivateGetPath);
        self::assertSame('conditional', $client->lastPrivateGetQuery['ordType'] ?? null);
    }

    public function testAlgoOrderDetailNotFoundCodeFallsBackToHistory(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $client->algoOrderDetailReturnsNotFoundCode = true;
        $gateway = new OkxOrderGateway($client);

        try {
            $order = $gateway->getOrder('BTCUSDT', 'algo:algo-triggered');
        } catch (OkxProviderUnavailableException $exception) {
            self::fail('Algo order detail not-found code should fall back to history, got: ' . $exception->getMessage());
        }

        self::assertNotNull($order);
        self::assertSame('algo:algo-triggered', $order->orderId);
        self::assertSame('/api/v5/trade/orders-algo-history', $client->lastPrivateGetPath);
    }

    public function testFindsTerminalAlgoClientOrderByHistoryStateScan(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $client->hideAlgoDetail = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'algo-client-triggered');

        self::assertNotNull($order);
        self::assertSame('algo:algo-triggered', $order->orderId);
        self::assertSame('/api/v5/trade/orders-algo-history', $client->lastPrivateGetPath);
        self::assertSame('conditional', $client->lastPrivateGetQuery['ordType'] ?? null);
        self::assertSame('canceled', $client->lastPrivateGetQuery['state'] ?? null);
        self::assertArrayNotHasKey('algoClOrdId', $client->lastPrivateGetQuery);
    }

    public function testPaginatesTerminalAlgoClientHistoryStateScan(): void
    {
        $client = $this->client();
        $client->hidePendingOrders = true;
        $client->hideAlgoDetail = true;
        $client->paginateAlgoHistory = true;
        $gateway = new OkxOrderGateway($client);

        $order = $gateway->getOrder('BTCUSDT', 'algo-client-triggered');

        self::assertNotNull($order);
        self::assertSame('algo:algo-triggered', $order->orderId);
        self::assertSame('/api/v5/trade/orders-algo-history', $client->lastPrivateGetPath);
        self::assertSame('algo-old-1', $client->lastPrivateGetQuery['after'] ?? null);
        self::assertArrayNotHasKey('algoClOrdId', $client->lastPrivateGetQuery);
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
    public bool $emptyBalanceDetails = false;
    public bool $hidePendingOrders = false;
    public bool $hideOrderDetail = false;
    public bool $orderDetailReturnsNotFoundCode = false;
    public bool $hideAlgoDetail = false;
    public bool $algoOrderDetailReturnsNotFoundCode = false;
    public bool $paginateOrderHistory = false;
    public bool $useOrderHistoryArchive = false;
    public bool $paginateAlgoHistory = false;
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
            '/api/v5/trade/orders-history' => $this->orderHistory($query),
            '/api/v5/trade/orders-history-archive' => $this->orderHistory($query),
            '/api/v5/trade/order-algo' => $this->algoOrderDetail($query),
            '/api/v5/trade/orders-algo-history' => $this->algoOrderHistory($query),
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
            'details' => $this->emptyBalanceDetails ? [] : [[
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
        if ($this->orderDetailReturnsNotFoundCode) {
            return ['code' => '51603', 'msg' => 'Order does not exist', 'data' => []];
        }

        if ($this->hideOrderDetail || ($query['ordId'] ?? null) !== 'ord-filled') {
            return ['code' => '0', 'data' => []];
        }

        return $this->filledOrder('ord-filled', 'client-filled');
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function orderHistory(array $query): array
    {
        $this->assertQueryValue($query, 'instType', 'SWAP');
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        if (array_key_exists('ordId', $query) || array_key_exists('clOrdId', $query)) {
            throw new \RuntimeException('OKX order history query must not use unsupported order id filters.');
        }
        if ($this->useOrderHistoryArchive) {
            if ($this->lastPrivateGetPath === '/api/v5/trade/orders-history-archive') {
                return $this->filledOrder('ord-archived', 'client-archived');
            }

            return ['code' => '0', 'data' => []];
        }

        if ($this->paginateOrderHistory && !isset($query['after'])) {
            return ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'ordId' => 'ord-old-1',
                'clOrdId' => 'client-old-1',
                'side' => 'buy',
                'posSide' => 'long',
                'ordType' => 'limit',
                'state' => 'filled',
                'sz' => '1',
                'accFillSz' => '1',
                'px' => '25000',
                'avgPx' => '24990',
                'cTime' => '1767225500000',
                'uTime' => '1767225560000',
            ]]];
        }

        if (($query['after'] ?? null) !== 'ord-old-1' && $this->paginateOrderHistory) {
            return ['code' => '0', 'data' => []];
        }

        if ($this->paginateOrderHistory) {
            return $this->filledOrder('ord-historical', 'client-historical');
        }

        return ['code' => '0', 'data' => []];
    }

    /**
     * @return array<string,mixed>
     */
    private function filledOrder(string $orderId, string $clientOrderId): array
    {
        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'ordId' => $orderId,
            'clOrdId' => $clientOrderId,
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
    private function algoOrderDetail(array $query): array
    {
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        if ($this->algoOrderDetailReturnsNotFoundCode) {
            return ['code' => '51603', 'msg' => 'Order does not exist', 'data' => []];
        }

        if ($this->hideAlgoDetail || !$this->matchesTriggeredAlgoDetail($query)) {
            return ['code' => '0', 'data' => []];
        }

        return $this->triggeredAlgoOrder();
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function algoOrderHistory(array $query): array
    {
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        $this->assertQueryValue($query, 'ordType', 'conditional');
        if (array_key_exists('algoClOrdId', $query)) {
            throw new \RuntimeException('OKX algo history query must not use unsupported algoClOrdId filter.');
        }
        if ($this->paginateAlgoHistory && ($query['state'] ?? null) === 'canceled' && !isset($query['after'])) {
            return ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP',
                'algoId' => 'algo-old-1',
                'algoClOrdId' => 'algo-client-old-1',
                'side' => 'sell',
                'posSide' => 'long',
                'ordType' => 'conditional',
                'state' => 'canceled',
                'sz' => '1',
                'accFillSz' => '0',
                'slTriggerPx' => '24100',
                'reduceOnly' => 'true',
                'cTime' => '1767225500000',
                'uTime' => '1767225560000',
            ]]];
        }
        if (!$this->matchesTriggeredAlgoHistory($query)) {
            return ['code' => '0', 'data' => []];
        }

        return $this->triggeredAlgoOrder();
    }

    /**
     * @param array<string,mixed> $query
     */
    private function matchesTriggeredAlgoDetail(array $query): bool
    {
        return ($query['algoId'] ?? null) === 'algo-triggered'
            || ($query['algoClOrdId'] ?? null) === 'algo-client-triggered';
    }

    /**
     * @param array<string,mixed> $query
     */
    private function matchesTriggeredAlgoHistory(array $query): bool
    {
        return ($query['algoId'] ?? null) === 'algo-triggered'
            || (($query['state'] ?? null) === 'canceled' && !array_key_exists('algoId', $query));
    }

    /**
     * @return array<string,mixed>
     */
    private function triggeredAlgoOrder(): array
    {
        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'algoId' => 'algo-triggered',
            'algoClOrdId' => 'algo-client-triggered',
            'side' => 'sell',
            'posSide' => 'long',
            'ordType' => 'conditional',
            'state' => 'canceled',
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
