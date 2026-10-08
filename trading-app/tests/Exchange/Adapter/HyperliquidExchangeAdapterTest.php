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
        self::assertFalse($capabilities->supportsWebSocketPrivate);
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

    public function testWritesAreRefusedWithoutAGateProof(): void
    {
        $client = new FakeHyperliquidClient();
        $adapter = $this->adapter($client, proven: false);
        $calls = [
            fn () => $adapter->placeOrder($this->placeOrderRequest()),
            fn () => $adapter->cancelOrder(new CancelOrderRequest(
                exchange: Exchange::HYPERLIQUID,
                marketType: MarketType::PERPETUAL,
                symbol: 'BTCUSDC',
                exchangeOrderId: null,
                clientOrderId: 'cid-hl-1',
            )),
            fn () => $adapter->setLeverage('BTCUSDC', 2, 'isolated'),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Write must be refused without a readiness proof.');
            } catch (\LogicException $exception) {
                self::assertSame('hyperliquid_mutation_requires_testnet_port', $exception->getMessage());
            }
        }
        self::assertSame([], $client->lastExchangeAction);
    }

    /** @return iterable<string, array{string}> */
    public static function scopeViolations(): iterable
    {
        yield 'symbol' => ['proof_scope_symbol'];
        yield 'notional' => ['proof_scope_notional'];
        yield 'side' => ['proof_scope_side'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scopeViolations')]
    public function testPlaceOrderIsRefusedOutsideTheProofScope(string $violation): void
    {
        $client = new FakeHyperliquidClient();
        $cleared = match ($violation) {
            'proof_scope_symbol' => new \App\Tests\Support\ClearedHyperliquidMutationGate(),
            'proof_scope_notional' => new \App\Tests\Support\ClearedHyperliquidMutationGate(maxNotional: 100.0),
            default => new \App\Tests\Support\ClearedHyperliquidMutationGate(side: 'short'),
        };
        $request = $this->placeOrderRequest();
        if ($violation === 'proof_scope_symbol') {
            $request = new PlaceOrderRequest(...[...get_object_vars($request), 'symbol' => 'ETHUSDC']);
        }

        try {
            $this->adapter($client, cleared: $cleared)->placeOrder($request);
            self::fail('Out-of-scope write must be refused.');
        } catch (\LogicException $exception) {
            self::assertSame($violation, $exception->getMessage());
        }
        self::assertSame([], $client->lastExchangeAction);
    }

    public function testCancelAndLeverageAreCheckedForSymbolOnly(): void
    {
        $client = new FakeHyperliquidClient();
        $adapter = $this->adapter($client, cleared: new \App\Tests\Support\ClearedHyperliquidMutationGate(side: 'short', maxNotional: 1.0));

        self::assertTrue($adapter->setLeverage('BTCUSDC', 2, 'isolated'));
        foreach ([
            fn () => $adapter->setLeverage('ETHUSDC', 2, 'isolated'),
            fn () => $adapter->cancelOrder(new CancelOrderRequest(Exchange::HYPERLIQUID, MarketType::PERPETUAL, 'ETHUSDC', null, 'cid-hl-1')),
        ] as $call) {
            try {
                $call();
                self::fail('Out-of-scope symbol must be refused.');
            } catch (\LogicException $exception) {
                self::assertSame('proof_scope_symbol', $exception->getMessage());
            }
        }
        self::assertTrue($adapter->cancelOrder(new CancelOrderRequest(Exchange::HYPERLIQUID, MarketType::PERPETUAL, 'BTCUSDC', null, 'cid-hl-1'))->cancelled);
    }

    public function testExpiredProofIsRefused(): void
    {
        $clock = new \Symfony\Component\Clock\MockClock('2026-01-01T00:00:00Z');
        $cleared = new \App\Tests\Support\ClearedHyperliquidMutationGate(clock: $clock, lifetime: 60);
        $client = new FakeHyperliquidClient();
        $adapter = $this->adapter($client, cleared: $cleared);

        self::assertTrue($adapter->placeOrder($this->placeOrderRequest())->accepted);
        $clock->sleep(61);

        $this->expectExceptionObject(new \LogicException('hyperliquid_mutation_requires_testnet_port'));
        $adapter->placeOrder($this->placeOrderRequest());
    }

    public function testFreshProofIsRefusedWhenTheDurableKillSwitchIsTripped(): void
    {
        $client = new FakeHyperliquidClient();
        $killSwitch = new \App\Tests\Support\ToggleHyperliquidKillSwitch();
        $adapter = $this->adapter($client, killSwitch: $killSwitch);
        $killSwitch->tripped = true;

        try {
            $adapter->placeOrder($this->placeOrderRequest());
            self::fail('Tripped kill switch must refuse writes.');
        } catch (\LogicException $exception) {
            self::assertSame('hyperliquid_mutation_kill_switch_tripped', $exception->getMessage());
        }
        self::assertSame([], $client->lastExchangeAction);
    }

    public function testHandBuiltProofIsRejectedByTheAdapter(): void
    {
        $client = new FakeHyperliquidClient();
        $forged = new \App\Exchange\Hyperliquid\HyperliquidMutationReadinessProof('p', str_repeat('a', 64), 'long', ['BTCUSDT'], 1.0e6, time(), str_repeat('0', 64));
        $adapter = $this->adapter($client, proven: false)->withMutationProof($forged);

        try {
            $adapter->placeOrder($this->placeOrderRequest());
            self::fail('Forged proof must be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('hyperliquid_mutation_requires_testnet_port', $exception->getMessage());
        }
        self::assertSame([], $client->lastExchangeAction);
    }

    public function testProofFromAnotherGateIsRejected(): void
    {
        $client = new FakeHyperliquidClient();
        $other = new \App\Tests\Support\ClearedHyperliquidMutationGate();
        $adapter = $this->adapter($client, proven: false)->withMutationProof($other->proof);

        $this->expectException(\LogicException::class);
        $adapter->placeOrder($this->placeOrderRequest());
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

    private function adapter(?FakeHyperliquidClient $client = null, bool $proven = true, ?\App\Tests\Support\ClearedHyperliquidMutationGate $cleared = null, ?\App\Tests\Support\ToggleHyperliquidKillSwitch $killSwitch = null): HyperliquidExchangeAdapter
    {
        $cleared ??= new \App\Tests\Support\ClearedHyperliquidMutationGate();
        $client ??= new FakeHyperliquidClient();

        $adapter = new HyperliquidExchangeAdapter(
            $client,
            new HyperliquidAssetResolver($client),
            new HyperliquidActionFactory(),
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
                testnetAgentAddress: '0x0000000000000000000000000000000000000002',
                testnetAccountAddress: '0x0000000000000000000000000000000000000001',
            ),
            $this->fixedClock(),
            $cleared->gate,
            $killSwitch ?? new \App\Tests\Support\ToggleHyperliquidKillSwitch(),
        );

        return $proven ? $adapter->withMutationProof($cleared->proof) : $adapter;
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
