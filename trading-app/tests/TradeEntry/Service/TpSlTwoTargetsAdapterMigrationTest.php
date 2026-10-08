<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Service;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\OkxDemoGuardedExchangeAdapter;
use App\Exchange\Adapter\OkxExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Okx\Demo\OkxDemoWriteRefusedException;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Tests\Exchange\Okx\Demo\OkxDemoWriteHarness;
use App\Tests\Exchange\Okx\Demo\RecordingOkxClient;
use App\Tests\TradeEntry\Support\ScriptedExchangeAdapter;
use App\TradeEntry\Service\TpSlTwoTargetsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TpSlTwoTargetsService::class)]
final class TpSlTwoTargetsAdapterMigrationTest extends TestCase
{
    public function testTakeProfitAndStopLossAreSubmittedAsReduceOnlyAlgoOrdersThroughTheEnvelope(): void
    {
        [$adapter, $client, $harness] = $this->guarded();
        $service = (new \ReflectionClass(TpSlTwoTargetsService::class))->newInstanceWithoutConstructor();

        $tp = $this->invoke($service, 'placeClosingOrder', $adapter, 'BTCUSDT', ExchangeOrderSide::SELL, ExchangePositionSide::LONG, ExchangeOrderType::TAKE_PROFIT, 0.02, 26000.0, 26000.0, 'cid-TP1');
        $sl = $this->invoke($service, 'placeClosingOrder', $adapter, 'BTCUSDT', ExchangeOrderSide::SELL, ExchangePositionSide::LONG, ExchangeOrderType::STOP_LOSS, 0.03, 24800.0, 24800.0, 'cid-SL');

        self::assertSame('algo:90001', $tp);
        self::assertSame('algo:90001', $sl);
        self::assertSame('/api/v5/trade/order-algo', $client->posts[0][0]);
        self::assertSame('26000', $client->posts[0][1]['tpTriggerPx']);
        self::assertSame('26000', $client->posts[0][1]['tpOrdPx']);
        self::assertSame('true', $client->posts[0][1]['reduceOnly']);
        self::assertSame('24800', $client->posts[1][1]['slTriggerPx']);
        $kinds = array_values(array_unique(array_column(array_filter($harness->events, static fn (array $e): bool => ($e['phase'] ?? '') === 'before'), 'write_kind')));
        self::assertSame(['take_profit', 'protective'], $kinds);
    }

    public function testTakeProfitIsRefusedOnAStaleStreamWhileStopLossIsExempt(): void
    {
        [$adapter, $client, $harness] = $this->guarded();
        $harness->healthyPrivateStream = false;
        $service = (new \ReflectionClass(TpSlTwoTargetsService::class))->newInstanceWithoutConstructor();

        $sl = $this->invoke($service, 'placeClosingOrder', $adapter, 'BTCUSDT', ExchangeOrderSide::SELL, ExchangePositionSide::LONG, ExchangeOrderType::STOP_LOSS, 0.03, 24800.0, 24800.0, 'cid-SL');
        self::assertSame('algo:90001', $sl);

        $this->expectException(OkxDemoWriteRefusedException::class);
        try {
            $this->invoke($service, 'placeClosingOrder', $adapter, 'BTCUSDT', ExchangeOrderSide::SELL, ExchangePositionSide::LONG, ExchangeOrderType::TAKE_PROFIT, 0.02, 26000.0, 26000.0, 'cid-TP1');
        } finally {
            self::assertCount(1, $client->posts);
        }
    }

    public function testCancelCarriesTheWriteKindToTheAdapter(): void
    {
        $scripted = new ScriptedExchangeAdapter();
        $service = (new \ReflectionClass(TpSlTwoTargetsService::class))->newInstanceWithoutConstructor();
        $order = $scripted->order('algo:1', \App\Exchange\Enum\ExchangeOrderStatus::OPEN, 0.0, 1.0);

        self::assertTrue($this->invoke($service, 'cancelClosingOrder', $scripted, $order, 'take_profit'));
        self::assertTrue($this->invoke($service, 'cancelClosingOrder', $scripted, $order, 'protective'));

        self::assertContainsOnlyInstancesOf(CancelOrderRequest::class, $scripted->cancelled);
        self::assertSame(['take_profit', 'protective'], array_map(static fn (CancelOrderRequest $r): mixed => $r->metadata['write_kind'], $scripted->cancelled));
    }

    /**
     * @return array{0: OkxDemoGuardedExchangeAdapter, 1: RecordingOkxClient, 2: OkxDemoWriteHarness}
     */
    private function guarded(): array
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config());
        $client = new RecordingOkxClient();
        $inner = new OkxExchangeAdapter($client, new OkxInstrumentResolver(), new OkxActionFactory(), $harness->config, $harness->clock());

        return [new OkxDemoGuardedExchangeAdapter($inner, $harness->gate(), $harness->sink()), $client, $harness];
    }

    private function invoke(object $service, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($service, $method))->invoke($service, ...$args);
    }
}
