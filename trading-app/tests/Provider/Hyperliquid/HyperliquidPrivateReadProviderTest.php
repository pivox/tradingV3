<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Provider\Hyperliquid\HyperliquidAccountGateway;
use App\Provider\Hyperliquid\HyperliquidExecutionGateway;
use App\Provider\Hyperliquid\HyperliquidProviderNotReadyException;
use App\Provider\Hyperliquid\HyperliquidProviderUnavailableException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HyperliquidPrivateReadProviderTest extends TestCase
{
    public function testReadsAccountStateAndCollateralWithoutBroadcast(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->accountGateway($client);

        $account = $gateway->getAccountInfo();

        self::assertNotNull($account);
        self::assertSame('USDC', $account->currency);
        self::assertSame('1200.5', (string) $account->equity);
        self::assertSame('975.25', (string) $account->availableBalance);
        self::assertSame('35.5', (string) $account->unrealized);
        self::assertSame('225.25', (string) $account->positionDeposit);
        self::assertSame('hyperliquid_clearinghouse_state', $account->metadata['source']);
        self::assertArrayNotHasKey('secret', $account->metadata);
        self::assertSame(975.25, $gateway->getAccountBalance('USDC'));
        self::assertSame(0.0, $gateway->getAccountBalance('USDT'));
        self::assertSame(0, $client->exchangeCalls);
    }

    public function testUnknownAccountReturnsNullAndHealthIsFalse(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->unknownAccount = true;
        $gateway = $this->accountGateway($client);

        self::assertNull($gateway->getAccountInfo());
        self::assertFalse($gateway->healthCheck());
        self::assertSame(0, $client->exchangeCalls);
    }

    public function testWrongNetworkIsNotReady(): void
    {
        $gateway = $this->accountGateway(
            new FakeHyperliquidPrivateReadClient(),
            new HyperliquidConfig(environment: 'testnet', network: 'mainnet', testnetAccountAddress: '0xaccount'),
        );

        $this->expectException(HyperliquidProviderNotReadyException::class);
        $this->expectExceptionMessage('hyperliquid_account_network_not_testnet');

        $gateway->getAccountInfo();
    }

    public function testAgentAddressCannotBeUsedAsAccountAddress(): void
    {
        $gateway = $this->accountGateway(
            new FakeHyperliquidPrivateReadClient(),
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xagent',
            ),
        );

        $this->expectException(HyperliquidProviderNotReadyException::class);
        $this->expectExceptionMessage('hyperliquid_account_address_matches_agent');

        $gateway->getAccountInfo();
    }

    public function testSignerAccountMismatchIsFlaggedWhenAccountIsMissing(): void
    {
        $gateway = $this->accountGateway(
            new FakeHyperliquidPrivateReadClient(),
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '',
            ),
        );

        $this->expectException(HyperliquidProviderNotReadyException::class);
        $this->expectExceptionMessage('hyperliquid_account_address_missing_for_signer');

        $gateway->getAccountInfo();
    }

    public function testMapsNoPositionAndLongShortPositions(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->accountGateway($client);

        $positions = $gateway->getOpenPositions();

        self::assertCount(2, $positions);
        self::assertSame('BTCUSDT', $positions[0]->symbol);
        self::assertSame(PositionSide::LONG, $positions[0]->side);
        self::assertSame('0.25', (string) $positions[0]->size);
        self::assertSame('25000', (string) $positions[0]->entryPrice);
        self::assertSame('25120', (string) $positions[0]->markPrice);
        self::assertSame('7.5', (string) $positions[0]->leverage);
        self::assertArrayNotHasKey('apiSecret', $positions[0]->metadata);

        self::assertSame('ETHUSDT', $positions[1]->symbol);
        self::assertSame(PositionSide::SHORT, $positions[1]->side);
        self::assertSame('1.5', (string) $positions[1]->size);
        self::assertEquals($positions[0], $gateway->getPosition('BTCUSDT'));

        $client->noPositions = true;
        self::assertSame([], $gateway->getOpenPositions());
    }

    public function testPrivateDataUnavailableIsNotReadyForStrictReadAndTolerantForOpenPositions(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->unavailable = true;
        $gateway = $this->accountGateway($client);

        self::assertSame([], $gateway->getOpenPositions());

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $this->expectExceptionMessage('hyperliquid_private_rate_limited');

        $gateway->getOpenPositionsOrFail();
    }

    public function testReadsOpenOrdersReadOnlyAndFiltersBySymbol(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->executionGateway($client);

        $orders = $gateway->getOpenOrders('BTCUSDT');

        self::assertCount(1, $orders);
        self::assertSame('1001', $orders[0]->orderId);
        self::assertSame('BTCUSDT', $orders[0]->symbol);
        self::assertSame(OrderSide::BUY, $orders[0]->side);
        self::assertSame(OrderType::LIMIT, $orders[0]->type);
        self::assertSame(OrderStatus::PENDING, $orders[0]->status);
        self::assertSame('0.2', (string) $orders[0]->quantity);
        self::assertSame('25000', (string) $orders[0]->price);
        self::assertSame('0.05', (string) $orders[0]->filledQuantity);
        self::assertSame('0.15', (string) $orders[0]->remainingQuantity);
        self::assertSame('client-a', $orders[0]->metadata['client_order_id']);
        self::assertArrayNotHasKey('privateKey', $orders[0]->metadata);
        self::assertEquals($orders[0], $gateway->getOrder('BTCUSDT', 'client-a'));
        self::assertSame(0, $client->exchangeCalls);
    }

    public function testWriteMethodsRemainFailClosed(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->executionGateway($client);

        $this->expectException(HyperliquidProviderNotReadyException::class);
        $this->expectExceptionMessage('hyperliquid_execution_not_ready');

        $gateway->cancelOrder('BTCUSDT', '1001');
    }

    public function testNormalizesFillsWithBoundedLimitAndTimeWindow(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->accountGateway($client);

        $fills = $gateway->getTrades('BTCUSDT', 500, 1_704_067_200, 1_704_153_600);

        self::assertCount(1, $fills);
        self::assertSame('hyperliquid', $fills[0]['exchange']);
        self::assertSame('BTCUSDT', $fills[0]['symbol']);
        self::assertSame('1001', $fills[0]['order_id']);
        self::assertSame('client-a', $fills[0]['client_order_id']);
        self::assertSame('fill-hash-1', $fills[0]['trade_id']);
        self::assertSame('buy', $fills[0]['side']);
        self::assertSame('0.1', $fills[0]['size']);
        self::assertSame('25010', $fills[0]['price']);
        self::assertSame('USDC', $fills[0]['fee_currency']);
        self::assertSame('0.12', $fills[0]['fee']);
        self::assertSame(1_704_067_200_000, $fills[0]['create_time']);
        self::assertArrayNotHasKey('secretToken', $fills[0]['raw_reference']);

        $request = $client->requests[array_key_last($client->requests)];
        self::assertSame('userFillsByTime', $request['type']);
        self::assertSame(1_704_067_200_000, $request['startTime']);
        self::assertSame(1_704_153_600_000, $request['endTime']);
        self::assertSame(200, $request['limit']);
        self::assertSame(0, $client->exchangeCalls);
    }

    public function testNormalizesFundingHistory(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->accountGateway($client);

        $transactions = $gateway->getTransactionHistory('BTCUSDT', 3, 100);

        self::assertCount(1, $transactions);
        self::assertSame('hyperliquid', $transactions[0]['exchange']);
        self::assertSame('BTCUSDT', $transactions[0]['symbol']);
        self::assertSame(3, $transactions[0]['flow_type']);
        self::assertSame('-0.42', $transactions[0]['amount']);
        self::assertSame('USDC', $transactions[0]['currency']);
        self::assertSame(1_704_067_260_000, $transactions[0]['create_time']);
        self::assertArrayNotHasKey('apiKey', $transactions[0]['raw_reference']);
    }

    private function accountGateway(
        FakeHyperliquidPrivateReadClient $client,
        ?HyperliquidConfig $config = null,
    ): HyperliquidAccountGateway {
        return new HyperliquidAccountGateway(
            $client,
            new HyperliquidAssetResolver($client),
            $config ?? $this->config(),
        );
    }

    private function executionGateway(
        FakeHyperliquidPrivateReadClient $client,
        ?HyperliquidConfig $config = null,
    ): HyperliquidExecutionGateway {
        return new HyperliquidExecutionGateway(
            $client,
            new HyperliquidAssetResolver($client),
            $config ?? $this->config(),
        );
    }

    private function config(): HyperliquidConfig
    {
        return new HyperliquidConfig(
            environment: 'testnet',
            network: 'testnet',
            testnetAgentAddress: '0xagent',
            testnetAccountAddress: '0xaccount',
        );
    }
}

final class FakeHyperliquidPrivateReadClient implements HyperliquidRestClientInterface
{
    public bool $unknownAccount = false;
    public bool $unavailable = false;
    public bool $noPositions = false;
    public int $exchangeCalls = 0;

    /** @var list<array<string,mixed>> */
    public array $requests = [];

    /**
     * @param array<string,mixed> $request
     * @return array<mixed>
     */
    public function info(array $request): array
    {
        if ($this->unavailable) {
            throw new \RuntimeException('HTTP 429 Too Many Requests');
        }

        $this->requests[] = $request;

        return match ((string) ($request['type'] ?? '')) {
            'meta' => [
                'universe' => [
                    ['name' => 'BTC', 'szDecimals' => 5, 'maxLeverage' => 50],
                    ['name' => 'ETH', 'szDecimals' => 4, 'maxLeverage' => 25],
                ],
            ],
            'clearinghouseState' => $this->clearinghouseState(),
            'frontendOpenOrders' => $this->openOrders(),
            'userFills', 'userFillsByTime' => $this->fills(),
            'userFunding' => $this->funding(),
            default => throw new \LogicException('Unexpected Hyperliquid info type: ' . (string) ($request['type'] ?? '')),
        };
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function exchange(array $action): array
    {
        ++$this->exchangeCalls;

        throw new \LogicException('Hyperliquid private read tests must not call /exchange.');
    }

    /**
     * @return array<string,mixed>
     */
    private function clearinghouseState(): array
    {
        if ($this->unknownAccount) {
            return [];
        }

        return [
            'marginSummary' => [
                'accountValue' => '1200.5',
                'totalNtlPos' => '6278',
                'totalRawUsd' => '975.25',
                'totalMarginUsed' => '225.25',
            ],
            'crossMarginSummary' => [
                'accountValue' => '1200.5',
                'totalMarginUsed' => '225.25',
            ],
            'withdrawable' => '975.25',
            'secret' => 'must-not-leak',
            'assetPositions' => $this->noPositions ? [] : [
                [
                    'position' => [
                        'coin' => 'BTC',
                        'szi' => '0.25',
                        'entryPx' => '25000',
                        'markPx' => '25120',
                        'unrealizedPnl' => '12.5',
                        'marginUsed' => '120',
                        'leverage' => ['value' => '7.5'],
                        'apiSecret' => 'must-not-leak',
                    ],
                ],
                [
                    'position' => [
                        'coin' => 'ETH',
                        'szi' => '-1.5',
                        'entryPx' => '1800',
                        'markPx' => '1790',
                        'unrealizedPnl' => '23',
                        'marginUsed' => '105.25',
                        'leverage' => ['value' => '5'],
                    ],
                ],
                [
                    'position' => [
                        'coin' => 'SOL',
                        'szi' => '0',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function openOrders(): array
    {
        return [
            [
                'coin' => 'BTC',
                'oid' => 1001,
                'cloid' => 'client-a',
                'side' => 'B',
                'sz' => '0.15',
                'origSz' => '0.2',
                'limitPx' => '25000',
                'timestamp' => 1_704_067_100_000,
                'orderType' => 'Limit',
                'privateKey' => 'must-not-leak',
            ],
            [
                'coin' => 'ETH',
                'oid' => 1002,
                'side' => 'A',
                'sz' => '1',
                'limitPx' => '1801',
                'timestamp' => 1_704_067_101_000,
                'orderType' => 'Limit',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fills(): array
    {
        return [
            [
                'coin' => 'BTC',
                'oid' => 1001,
                'cloid' => 'client-a',
                'hash' => 'fill-hash-1',
                'side' => 'B',
                'sz' => '0.1',
                'px' => '25010',
                'fee' => '0.12',
                'time' => 1_704_067_200_000,
                'secretToken' => 'must-not-leak',
            ],
            [
                'coin' => 'ETH',
                'oid' => 1002,
                'hash' => 'fill-hash-2',
                'side' => 'A',
                'sz' => '1',
                'px' => '1799',
                'fee' => '0.09',
                'time' => 1_704_067_210_000,
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function funding(): array
    {
        return [
            [
                'coin' => 'BTC',
                'usdc' => '-0.42',
                'time' => 1_704_067_260_000,
                'apiKey' => 'must-not-leak',
            ],
            [
                'coin' => 'ETH',
                'usdc' => '0.11',
                'time' => 1_704_067_270_000,
            ],
        ];
    }
}
