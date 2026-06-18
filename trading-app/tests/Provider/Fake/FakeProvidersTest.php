<?php

declare(strict_types=1);

namespace App\Tests\Provider\Fake;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Provider\Fake\FakeAccountProvider;
use App\Provider\Fake\FakeContractProvider;
use App\Provider\Fake\FakeKlineProvider;
use App\Provider\Fake\FakeOrderProvider;
use App\Provider\Fake\FakeSystemProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeAccountProvider::class)]
#[CoversClass(FakeOrderProvider::class)]
#[CoversClass(FakeContractProvider::class)]
#[CoversClass(FakeKlineProvider::class)]
#[CoversClass(FakeSystemProvider::class)]
final class FakeProvidersTest extends TestCase
{
    public function testAccountProviderReportsEmptyState(): void
    {
        $provider = new FakeAccountProvider();

        self::assertNull($provider->getAccountInfo());
        self::assertSame(0.0, $provider->getAccountBalance());
        self::assertSame([], $provider->getOpenPositions());
        // Variante fail-closed : retourne [] sans jamais lever (source fiable, vide).
        self::assertSame([], $provider->getOpenPositionsOrFail());
        self::assertNull($provider->getPosition('BTCUSDT'));
        self::assertSame([], $provider->getTradeHistory('BTCUSDT'));
        self::assertSame([], $provider->getTrades());
        self::assertSame([], $provider->getTransactionHistory());
        self::assertSame([], $provider->getTradingFees('BTCUSDT'));
    }

    public function testOrderProviderIsAnEmptyNoOpExchange(): void
    {
        $provider = new FakeOrderProvider();

        self::assertSame([], $provider->getOpenOrders());
        self::assertSame([], $provider->getOpenOrders('BTCUSDT'));
        // Variante fail-closed : retourne [] sans jamais lever (source fiable, vide).
        self::assertSame([], $provider->getOpenOrdersOrFail());
        self::assertNull($provider->getOrder('BTCUSDT', '123'));
        self::assertSame([], $provider->getOrderHistory('BTCUSDT'));

        // Placement is a no-op that never creates an order.
        self::assertNull(
            $provider->placeOrder('BTCUSDT', OrderSide::BUY, OrderType::LIMIT, 1.0, 100.0)
        );

        // Cancel/leverage operations succeed without side effects (no throw).
        self::assertTrue($provider->cancelOrder('BTCUSDT', '123'));
        self::assertTrue($provider->cancelAllOrders('BTCUSDT'));
        self::assertTrue($provider->submitLeverage('BTCUSDT', 10));

        $top = $provider->getOrderBookTop('BTCUSDT');
        self::assertInstanceOf(SymbolBidAskDto::class, $top);
        self::assertSame('BTCUSDT', $top->symbol);
        self::assertSame(0.0, $top->bid);
        self::assertSame(0.0, $top->ask);
    }

    public function testContractProviderExposesNoContracts(): void
    {
        $provider = new FakeContractProvider();

        self::assertSame([], $provider->getContracts());
        self::assertNull($provider->getContractDetails('BTCUSDT'));
        self::assertNull($provider->getLastPrice('BTCUSDT'));
        self::assertSame([], $provider->getOrderBook('BTCUSDT'));
        self::assertSame([], $provider->getRecentTrades('BTCUSDT'));
        self::assertSame([], $provider->getMarkPriceKline('BTCUSDT'));
        self::assertSame([], $provider->getLeverageBrackets('BTCUSDT'));

        self::assertSame(
            ['upserted' => 0, 'total_fetched' => 0, 'errors' => []],
            $provider->syncContracts(),
        );
    }

    public function testKlineProviderReturnsEmptySets(): void
    {
        $provider = new FakeKlineProvider();

        self::assertSame([], $provider->getKlines('BTCUSDT', Timeframe::TF_1M));
        self::assertSame(
            [],
            $provider->getKlinesInWindow(
                'BTCUSDT',
                Timeframe::TF_1M,
                new \DateTimeImmutable('-1 hour'),
                new \DateTimeImmutable('now'),
            ),
        );
        self::assertNull($provider->getLastKline('BTCUSDT', Timeframe::TF_1M));
        self::assertFalse($provider->hasGaps('BTCUSDT', Timeframe::TF_1M));
        self::assertSame([], $provider->getGaps('BTCUSDT', Timeframe::TF_1M));

        // Saves are no-ops and must not throw.
        $kline = new KlineDto(
            'BTCUSDT',
            Timeframe::TF_1M,
            new \DateTimeImmutable('now'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
        );
        $provider->saveKline($kline);
        $provider->saveKlines([$kline], 'BTCUSDT', Timeframe::TF_1M);
        $this->addToAssertionCount(1);
    }

    public function testSystemProviderReturnsCurrentTime(): void
    {
        $provider = new FakeSystemProvider();

        $before = (int) (microtime(true) * 1000);
        $now = $provider->getSystemTimeMs();
        $after = (int) (microtime(true) * 1000);

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
