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
use App\Provider\Hyperliquid\HyperliquidMetadataProvider;
use App\TradingCore\Execution\Hyperliquid\StrictHyperliquidExecutionStateProvider;
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

    public function testAcceptsFiniteZeroCollateralValues(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides['clearinghouseState'] = [
            'marginSummary' => [
                'accountValue' => '0',
                'totalRawUsd' => '0.0',
                'totalMarginUsed' => '-0',
            ],
            'withdrawable' => '0.00',
            'assetPositions' => [],
        ];

        $account = $this->accountGateway($client)->getAccountInfo();

        self::assertNotNull($account);
        self::assertSame('0', (string) $account->equity);
        self::assertSame('0.00', (string) $account->availableBalance);
        self::assertSame('0', (string) $account->positionDeposit);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedCollateralStates(): iterable
    {
        $valid = [
            'marginSummary' => [
                'accountValue' => '100',
                'totalRawUsd' => '90',
                'totalMarginUsed' => '10',
            ],
            'withdrawable' => '90',
            'assetPositions' => [],
        ];

        yield 'missing margin summary' => [array_diff_key($valid, ['marginSummary' => true])];
        yield 'margin summary list' => [array_replace($valid, ['marginSummary' => []])];
        yield 'missing account value' => [array_replace_recursive($valid, ['marginSummary' => ['accountValue' => null]])];
        yield 'missing total raw usd' => [array_replace_recursive($valid, ['marginSummary' => ['totalRawUsd' => null]])];
        yield 'missing total margin used' => [array_replace_recursive($valid, ['marginSummary' => ['totalMarginUsed' => null]])];
        yield 'missing withdrawable' => [array_diff_key($valid, ['withdrawable' => true])];
        yield 'NaN' => [array_replace_recursive($valid, ['marginSummary' => ['accountValue' => 'NaN']])];
        yield 'positive infinity' => [array_replace_recursive($valid, ['marginSummary' => ['totalRawUsd' => 'INF']])];
        yield 'negative infinity' => [array_replace_recursive($valid, ['marginSummary' => ['totalMarginUsed' => '-INF']])];
        yield 'non numeric' => [array_replace($valid, ['withdrawable' => 'not-a-number'])];
        yield 'numeric array' => [array_replace_recursive($valid, ['marginSummary' => ['accountValue' => ['100']]])];
        yield 'exponent shape' => [array_replace_recursive($valid, ['marginSummary' => ['accountValue' => '1e2']])];
    }

    /** @param array<mixed> $state */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedCollateralStates')]
    public function testRejectsMalformedOrMissingCollateralEvidence(array $state): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides['clearinghouseState'] = $state;

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $this->accountGateway($client)->getAccountInfo();
    }

    public function testEmptyTopLevelAccountStateFailsStrictReadAndHealthIsFalse(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->unknownAccount = true;
        $gateway = $this->accountGateway($client);

        self::assertFalse($gateway->healthCheck());
        self::assertSame(0, $client->exchangeCalls);

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $gateway->getAccountInfo();
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
        self::assertSame('isolated', $positions[0]->metadata['margin_mode'] ?? null);
        self::assertArrayNotHasKey('apiSecret', $positions[0]->metadata);

        self::assertSame('ETHUSDT', $positions[1]->symbol);
        self::assertSame(PositionSide::SHORT, $positions[1]->side);
        self::assertSame('1.5', (string) $positions[1]->size);
        self::assertEquals($positions[0], $gateway->getPosition('BTCUSDT'));

        $client->noPositions = true;
        self::assertSame([], $gateway->getOpenPositions());
    }

    public function testStrictExecutionStateReadsAuthoritativePositionMarginMode(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides['l2Book'] = [
            'time' => 1_720_780_799_000,
            'levels' => [
                [['px' => '99', 'sz' => '1', 'n' => 1]],
                [['px' => '100', 'sz' => '1', 'n' => 1]],
            ],
        ];
        $resolver = new HyperliquidAssetResolver($client);
        $provider = new StrictHyperliquidExecutionStateProvider(
            new HyperliquidMetadataProvider($client, $resolver),
            new HyperliquidAccountGateway($client, $resolver, $this->config()),
        );

        $state = $provider->current('ETHUSDT');

        self::assertSame(5, $state->observedLeverage);
        self::assertSame('cross', $state->observedMarginMode);
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

    public function testStrictPrivateReadsAcceptValidEmptySnapshots(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides = [
            'frontendOpenOrders' => [],
            'userFills' => [],
            'clearinghouseState' => ['assetPositions' => []],
        ];

        self::assertSame([], $this->executionGateway($client)->getOpenOrdersOrFail());
        self::assertSame([], $this->accountGateway($client)->getTrades(limit: 20));
        self::assertSame([], $this->accountGateway($client)->getOpenPositionsOrFail());
    }

    public function testStrictOpenOrdersRejectsMalformedMixedRows(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides['frontendOpenOrders'] = [$this->validOpenOrderRow(), 'malformed'];

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $this->executionGateway($client)->getOpenOrdersOrFail();
    }

    public function testStrictFillsRejectMalformedTopLevelShape(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides['userFills'] = ['rows' => []];

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $this->accountGateway($client)->getTrades(limit: 20);
    }

    public function testStrictPositionsRejectMalformedMixedRows(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides['clearinghouseState'] = [
            'assetPositions' => [['position' => ['coin' => 'BTC', 'szi' => '1']], 'malformed'],
        ];

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $this->accountGateway($client)->getOpenPositionsOrFail();
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedPositionSnapshots(): iterable
    {
        yield 'empty top-level list' => [[]];
        yield 'missing assetPositions' => [['marginSummary' => []]];
        yield 'assetPositions is associative' => [['assetPositions' => ['position' => []]]];
        yield 'assetPositions is scalar' => [['assetPositions' => 'malformed']];
    }

    /** @param array<mixed> $snapshot */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedPositionSnapshots')]
    public function testStrictPositionsRejectMalformedSnapshotShape(array $snapshot): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->overrides['clearinghouseState'] = $snapshot;

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $this->accountGateway($client)->getOpenPositionsOrFail();
    }

    public function testStrictPrivateReadsRejectRowsMissingEndpointFields(): void
    {
        $orders = new FakeHyperliquidPrivateReadClient();
        $orders->overrides['frontendOpenOrders'] = [['coin' => 'BTC']];
        $fills = new FakeHyperliquidPrivateReadClient();
        $fills->overrides['userFills'] = [['coin' => 'BTC']];
        $positions = new FakeHyperliquidPrivateReadClient();
        $positions->overrides['clearinghouseState'] = ['assetPositions' => [['position' => ['coin' => 'BTC']]]];

        foreach ([
            fn () => $this->executionGateway($orders)->getOpenOrdersOrFail(),
            fn () => $this->accountGateway($fills)->getTrades(limit: 20),
            fn () => $this->accountGateway($positions)->getOpenPositionsOrFail(),
        ] as $read) {
            try {
                $read();
                self::fail('Expected malformed endpoint row to fail closed.');
            } catch (HyperliquidProviderUnavailableException) {
                self::addToAssertionCount(1);
            }
        }
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
        self::assertArrayNotHasKey('apiKey', $transactions[0]['raw_reference']['delta']);

        $request = $client->requests[array_key_last($client->requests)];
        self::assertSame('userFunding', $request['type']);
        self::assertIsInt($request['startTime']);
        self::assertGreaterThan(0, $request['startTime']);
        self::assertSame(100, $request['limit']);
    }

    public function testDoesNotReturnFundingRowsAsRealizedPnlTransactions(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->accountGateway($client);

        self::assertSame([], $gateway->getTransactionHistory('BTCUSDT', 2));
        self::assertSame([], $client->requests);
    }

    public function testReadsTradingFeesReadOnly(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $gateway = $this->accountGateway($client);

        $fees = $gateway->getTradingFees('BTCUSDT');

        self::assertSame('hyperliquid', $fees['exchange']);
        self::assertSame('BTCUSDT', $fees['symbol']);
        self::assertSame('BTC', $fees['coin']);
        self::assertSame('USDC', $fees['fee_currency']);
        self::assertSame('0.0002', $fees['maker']);
        self::assertSame('0.0005', $fees['taker']);
        self::assertSame([], $fees['quality_flags']);
        self::assertArrayNotHasKey('apiSecret', $fees['raw_reference']);

        $request = $client->requests[array_key_last($client->requests)];
        self::assertSame('userFees', $request['type']);
        self::assertSame('0xaccount', $request['user']);
        self::assertSame(0, $client->exchangeCalls);
    }

    public function testMissingTradingFeesAreUnknownNotZero(): void
    {
        $client = new FakeHyperliquidPrivateReadClient();
        $client->hideFees = true;
        $gateway = $this->accountGateway($client);

        $fees = $gateway->getTradingFees('BTCUSDT');

        self::assertNull($fees['maker']);
        self::assertNull($fees['taker']);
        self::assertContains('maker_fee_unknown', $fees['quality_flags']);
        self::assertContains('taker_fee_unknown', $fees['quality_flags']);
        self::assertContains('fee_schedule_unknown', $fees['quality_flags']);
        self::assertSame(0, $client->exchangeCalls);
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

    /** @return array<string, mixed> */
    private function validOpenOrderRow(): array
    {
        return [
            'coin' => 'BTC',
            'oid' => 1001,
            'cloid' => 'client-a',
            'side' => 'B',
            'sz' => '0.15',
            'origSz' => '0.2',
            'limitPx' => '25000',
            'timestamp' => 1_704_067_100_000,
        ];
    }
}

final class FakeHyperliquidPrivateReadClient implements HyperliquidRestClientInterface
{
    public bool $unknownAccount = false;
    public bool $unavailable = false;
    public bool $noPositions = false;
    public bool $hideFees = false;
    public int $exchangeCalls = 0;

    /** @var array<string, array<mixed>> */
    public array $overrides = [];

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

        $type = (string) ($request['type'] ?? '');
        if (array_key_exists($type, $this->overrides)) {
            return $this->overrides[$type];
        }

        return match ($type) {
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
            'userFees' => $this->fees(),
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
                        'leverage' => ['type' => 'isolated', 'value' => '7.5'],
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
                        'leverage' => ['type' => 'cross', 'value' => '5'],
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
                'time' => 1_704_067_260_000,
                'delta' => [
                    'coin' => 'BTC',
                    'usdc' => '-0.42',
                    'apiKey' => 'must-not-leak',
                ],
            ],
            [
                'time' => 1_704_067_270_000,
                'delta' => [
                    'coin' => 'ETH',
                    'usdc' => '0.11',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fees(): array
    {
        if ($this->hideFees) {
            return [
                'dailyUserVlm' => [],
                'apiSecret' => 'must-not-leak',
            ];
        }

        return [
            'userAddRate' => '0.0002',
            'userCrossRate' => '0.0005',
            'activeReferralDiscount' => '0.04',
            'feeSchedule' => [
                'cross' => '0.00045',
                'add' => '0.00015',
            ],
            'apiSecret' => 'must-not-leak',
        ];
    }
}
