<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\OkxDemoGuardedExchangeAdapter;
use App\Exchange\Adapter\OkxExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Okx\Demo\OkxDemoWriteRefusedException;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Tests\Exchange\Okx\Demo\OkxDemoWriteHarness;
use App\Tests\Exchange\Okx\Demo\RecordingOkxClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxDemoGuardedExchangeAdapter::class)]
final class OkxDemoGuardedExchangeAdapterTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string,mixed>, string}>
     */
    public static function configRefusals(): iterable
    {
        yield 'env not demo' => [['environment' => 'live', 'live' => true], 'okx_env_not_demo'];
        yield 'env blank' => [['environment' => ''], 'okx_env_not_demo'];
        yield 'simulated off' => [['simulated' => false], 'okx_simulated_trading_disabled'];
        yield 'okx demo disabled' => [['demoEnabled' => false], 'okx_demo_trading_disabled'];
        yield 'global demo disabled' => [['globalEnabled' => false], 'demo_trading_disabled'];
        yield 'live enabled' => [['live' => true], 'okx_live_enabled'];
        yield 'endpoint not allowed' => [['apiBaseUri' => 'https://www.okx.com'], 'okx_private_rest_endpoint_not_allowed'];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    #[DataProvider('configRefusals')]
    public function testPlaceCancelAndLeverageAreRefusedWithoutHittingTheExchange(array $overrides, string $reason): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config(...$overrides));

        foreach ([
            fn () => $adapter->placeOrder($this->limitRequest()),
            fn () => $adapter->cancelOrder($this->cancelRequest()),
        ] as $call) {
            try {
                $call();
                self::fail('write must be refused');
            } catch (OkxDemoWriteRefusedException $e) {
                self::assertContains($reason, $e->reasons);
            }
        }
        self::assertFalse($adapter->setLeverage('BTCUSDT', 3, 'isolated'));

        self::assertSame([], $client->posts);
        $before = array_values(array_filter($harness->events, static fn (array $e): bool => ($e['phase'] ?? null) === 'before'));
        self::assertCount(3, $before);
        foreach ($before as $event) {
            self::assertFalse($event['allowed']);
            self::assertSame('refused', $event['outcome']);
            self::assertContains($reason, $event['reasons']);
        }
        self::assertSame([], array_filter($harness->events, static fn (array $e): bool => ($e['phase'] ?? null) === 'after'));
    }

    public function testKillSwitchTrippedRefusesWrite(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config(), killSwitchOkx: false);

        try {
            $adapter->placeOrder($this->limitRequest());
            self::fail('write must be refused');
        } catch (OkxDemoWriteRefusedException $e) {
            self::assertContains('okx_demo_trading_disabled', $e->reasons);
        }

        self::assertSame([], $client->posts);
        $mine = $this->adapterEvents($harness);
        self::assertCount(1, $mine);
        self::assertSame('refused', $mine[0]['outcome']);
    }

    public function testStaleOrMissingPrivateStreamRefusesWrite(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());
        $harness->healthyPrivateStream = false;

        try {
            $adapter->placeOrder($this->limitRequest());
            self::fail('write must be refused');
        } catch (OkxDemoWriteRefusedException $e) {
            self::assertContains('private_ws_not_connected', $e->reasons);
        }
        self::assertSame([], $client->posts);
    }

    public function testNotionalOverCapIsRefused(): void
    {
        [$adapter, $client] = $this->build(OkxDemoWriteHarness::config(), maxNotional: 100.0);

        try {
            $adapter->placeOrder($this->limitRequest());
            self::fail('write must be refused');
        } catch (OkxDemoWriteRefusedException $e) {
            self::assertContains('max_notional_exceeded', $e->reasons);
        }
        self::assertSame([], $client->posts);
    }

    public function testAuditFailureBeforeWriteFailsClosed(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());
        $harness->failAudit = true;

        try {
            $adapter->placeOrder($this->limitRequest());
            self::fail('write must be refused');
        } catch (OkxDemoWriteRefusedException $e) {
            self::assertContains('audit_failed', $e->reasons);
        }
        self::assertSame([], $client->posts);
    }

    public function testAllowedPlaceOrderEmitsBeforeAndAfterRecordsWithoutSecrets(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());

        $result = $adapter->placeOrder($this->limitRequest());

        self::assertTrue($result->accepted);
        self::assertSame('/api/v5/trade/order', $client->posts[0][0]);
        $events = $this->adapterEvents($harness);
        self::assertCount(2, $events);
        [$before, $after] = $events;
        self::assertSame('before', $before['phase']);
        self::assertTrue($before['allowed']);
        self::assertSame('attempted', $before['outcome']);
        self::assertSame('after', $after['phase']);
        self::assertSame('accepted', $after['outcome']);
        foreach ([$before, $after] as $event) {
            self::assertSame('okx', $event['exchange']);
            self::assertSame('demo', $event['environment']);
            self::assertSame('place_order', $event['action']);
            self::assertSame('BTCUSDT', $event['symbol']);
            self::assertSame('buy', $event['side']);
            self::assertSame(0.01, $event['quantity']);
            self::assertSame(25000.0, $event['price']);
            self::assertSame('OKX1', $event['client_order_id']);
            self::assertSame('decision-1', $event['decision_id']);
            self::assertSame('42', $event['order_intent_id']);
        }
        self::assertSame('12345', $after['exchange_order_id']);
        self::assertStringNotContainsString(OkxDemoWriteHarness::SECRET, json_encode($harness->events, JSON_THROW_ON_ERROR));
    }

    public function testStopLossPlacementIsCoveredByTheEnvelope(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());

        $result = $adapter->placeOrder(new PlaceOrderRequest(
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
            clientOrderId: 'OKXSL',
        ));

        self::assertTrue($result->accepted);
        self::assertSame('/api/v5/trade/order-algo', $client->posts[0][0]);
        $events = $this->adapterEvents($harness);
        self::assertSame(['before', 'after'], array_column($events, 'phase'));
        self::assertTrue($events[0]['reduce_only']);
        self::assertSame(24800.0, $events[0]['stop_price']);
        self::assertSame('algo:90001', $events[1]['exchange_order_id']);
    }

    public function testMarketOrderNotionalUsesOrderBook(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());

        $adapter->placeOrder($this->limitRequest(ExchangeOrderType::MARKET, null));

        self::assertCount(1, $client->posts);
        self::assertEqualsWithDelta(0.01 * 25000.5, $this->adapterEvents($harness)[0]['notional'], 0.0001);
    }

    public function testCancelAndLeverageAreAuditedAndForwarded(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());

        $cancel = $adapter->cancelOrder($this->cancelRequest());
        $leverage = $adapter->setLeverage('BTCUSDT', 3, 'isolated');

        self::assertTrue($cancel->cancelled);
        self::assertTrue($leverage);
        $events = $this->adapterEvents($harness);
        self::assertSame(['cancel_order', 'cancel_order', 'set_leverage', 'set_leverage'], array_column($events, 'action'));
        self::assertSame(['before', 'after', 'before', 'after'], array_column($events, 'phase'));
        self::assertNotEmpty($client->posts);
    }

    public function testReadsAreNotGatedNorAudited(): void
    {
        [$adapter, , $harness] = $this->build(OkxDemoWriteHarness::config(live: true));

        self::assertSame([], $adapter->getOpenOrders('BTCUSDT'));
        self::assertSame([], $this->adapterEvents($harness));
    }

    public function testInnerExceptionIsAuditedAndRethrown(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());
        $client->throwOnPost = true;

        try {
            $adapter->placeOrder($this->limitRequest());
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $events = $this->adapterEvents($harness);
        self::assertSame('error', $events[1]['outcome']);
        self::assertSame(\RuntimeException::class, $events[1]['error_class']);
        self::assertArrayNotHasKey('error', $events[1]);
    }

    public function testWriteKindIsDerivedFromTheRequestAndRecordedWithTheExemption(): void
    {
        [$adapter, , $harness] = $this->build(OkxDemoWriteHarness::config());
        $harness->healthyPrivateStream = false;

        $stop = $this->reduceOnly(ExchangeOrderType::STOP_LOSS, 'OKXSL', stopPrice: 24800.0);
        $close = $this->reduceOnly(ExchangeOrderType::MARKET, 'OKXEM');
        $adapter->placeOrder($stop);
        $adapter->placeOrder($close);
        $adapter->cancelOrder(new CancelOrderRequest(Exchange::OKX, MarketType::PERPETUAL, 'BTCUSDT', '12345', 'OKX1', ['write_kind' => 'protective']));

        $before = array_values(array_filter($this->adapterEvents($harness), static fn (array $e): bool => $e['phase'] === 'before'));
        self::assertSame(['protective', 'protective', 'protective'], array_column($before, 'write_kind'));
        foreach ($before as $event) {
            self::assertTrue($event['allowed']);
            self::assertTrue($event['exemption_applied']);
            self::assertContains('private_ws_not_connected', $event['exempted_reasons']);
        }

        foreach ([
            fn () => $adapter->placeOrder($this->reduceOnly(ExchangeOrderType::TAKE_PROFIT, 'OKXTP', stopPrice: 26000.0)),
            fn () => $adapter->placeOrder($this->limitRequest()),
            fn () => $adapter->cancelOrder($this->cancelRequest()),
        ] as $call) {
            try {
                $call();
                self::fail('non protective write must be refused on a stale stream');
            } catch (OkxDemoWriteRefusedException $e) {
                self::assertContains('private_ws_not_connected', $e->reasons);
            }
        }
        $refused = array_values(array_filter($this->adapterEvents($harness), static fn (array $e): bool => ($e['outcome'] ?? '') === 'refused'));
        self::assertSame(['take_profit', 'entry', 'entry'], array_column($refused, 'write_kind'));
        self::assertSame([false, false, false], array_column($refused, 'exemption_applied'));
    }

    public function testExplicitProtectiveKindWithoutReduceOnlyIsRefusedAndNeverSent(): void
    {
        [$adapter, $client] = $this->build(OkxDemoWriteHarness::config());
        $request = new PlaceOrderRequest(
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
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'OKX1',
            metadata: ['write_kind' => 'protective'],
        );

        try {
            $adapter->placeOrder($request);
            self::fail('refused');
        } catch (OkxDemoWriteRefusedException $e) {
            self::assertSame(['protective_requires_reduce_only'], $e->reasons);
        }
        self::assertSame([], $client->posts);
    }

    public function testProtectiveStopIsAllowedOverTheNotionalCapAndAuditsTheExemption(): void
    {
        [$adapter, , $harness] = $this->build(OkxDemoWriteHarness::config(), maxNotional: 10.0);

        $adapter->placeOrder($this->reduceOnly(ExchangeOrderType::STOP_LOSS, 'OKXSL', stopPrice: 24800.0));

        $before = $this->adapterEvents($harness)[0];
        self::assertTrue($before['allowed']);
        self::assertSame(['max_notional_exceeded'], $before['exempted_reasons']);
    }

    public function testTrippedMarkerRefusesProtectiveWritesToo(): void
    {
        [$adapter, $client, $harness] = $this->build(OkxDemoWriteHarness::config());
        $harness->tripped = true;

        try {
            $adapter->placeOrder($this->reduceOnly(ExchangeOrderType::STOP_LOSS, 'OKXSL', stopPrice: 24800.0));
            self::fail('tripped');
        } catch (OkxDemoWriteRefusedException $e) {
            self::assertSame(['okx_demo_tripped'], $e->reasons);
        }
        self::assertSame([], $client->posts);
    }

    private function reduceOnly(ExchangeOrderType $type, string $clientOrderId, ?float $stopPrice = null): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            orderType: $type,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 0.01,
            price: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function adapterEvents(OkxDemoWriteHarness $harness): array
    {
        return array_values(array_filter($harness->events, static fn (array $e): bool => isset($e['phase'])));
    }

    /**
     * @return array{0: OkxDemoGuardedExchangeAdapter, 1: RecordingOkxClient, 2: OkxDemoWriteHarness}
     */
    private function build(\App\Exchange\Okx\OkxConfig $config, bool $killSwitchOkx = true, float $maxNotional = 1000.0): array
    {
        $harness = new OkxDemoWriteHarness($config, true, $killSwitchOkx, $maxNotional);
        $client = new RecordingOkxClient();
        $inner = new OkxExchangeAdapter($client, new OkxInstrumentResolver(), new OkxActionFactory(), $config, $harness->clock());

        return [new OkxDemoGuardedExchangeAdapter($inner, $harness->gate(), $harness->sink()), $client, $harness];
    }

    private function limitRequest(ExchangeOrderType $type = ExchangeOrderType::LIMIT, ?float $price = 25000.0): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: $type,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 0.01,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'OKX1',
            metadata: ['decision_key' => 'decision-1', 'order_intent_id' => 42],
        );
    }

    private function cancelRequest(): CancelOrderRequest
    {
        return new CancelOrderRequest(Exchange::OKX, MarketType::PERPETUAL, 'BTCUSDT', '12345', 'OKX1');
    }
}
