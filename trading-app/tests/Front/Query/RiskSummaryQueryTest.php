<?php

declare(strict_types=1);

namespace App\Tests\Front\Query;

use App\Front\Query\RiskSummaryQuery;
use App\Front\ViewModel\FrontAlert;
use App\Front\ViewModel\RiskSummaryView;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RiskSummaryQuery::class)]
#[CoversClass(RiskSummaryView::class)]
#[CoversClass(FrontAlert::class)]
final class RiskSummaryQueryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE positions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    side VARCHAR(10) NOT NULL,
    size NUMERIC NULL,
    avg_entry_price NUMERIC NULL,
    leverage INTEGER NULL,
    unrealized_pnl NUMERIC NULL,
    status VARCHAR(16) NOT NULL,
    payload TEXT NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE futures_order (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    side INTEGER NULL,
    type VARCHAR(20) NULL,
    status VARCHAR(30) NULL,
    price NUMERIC NULL,
    size INTEGER NULL,
    client_order_id VARCHAR(80) NULL,
    order_id VARCHAR(80) NULL,
    updated_at DATETIME NOT NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE futures_plan_order (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    side INTEGER NULL,
    type VARCHAR(20) NULL,
    status VARCHAR(30) NULL,
    trigger_price NUMERIC NULL,
    price NUMERIC NULL,
    size INTEGER NULL,
    client_order_id VARCHAR(80) NULL,
    order_id VARCHAR(80) NULL,
    plan_type VARCHAR(20) NULL,
    raw_data TEXT NOT NULL DEFAULT '{}',
    updated_at DATETIME NOT NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE symbol_execution_lock (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    status VARCHAR(24) NOT NULL,
    owner_profile VARCHAR(80) NULL,
    owner_decision_key VARCHAR(255) NULL,
    locked_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    released_at DATETIME NULL
)
SQL);
    }

    public function testOpenPositionWithoutStopLossProducesCriticalAlert(): void
    {
        $this->connection->insert('positions', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'LINKUSDT',
            'side' => 'LONG',
            'size' => '42',
            'avg_entry_price' => '14.25',
            'leverage' => 8,
            'unrealized_pnl' => '-3.10',
            'status' => 'OPEN',
            'payload' => json_encode(['source' => 'exchange'], JSON_THROW_ON_ERROR),
            'updated_at' => '2026-06-01 10:00:00',
        ]);

        $view = (new RiskSummaryQuery(
            $this->connection,
            new MockClock('2026-06-01 10:05:00 UTC'),
        ))->getSummary();

        self::assertSame(1, $view->openPositionCount);
        self::assertSame(1, $view->criticalAlertCount);
        self::assertSame('position_without_stop_loss', $view->alerts[0]->code);
        self::assertSame('LINKUSDT', $view->alerts[0]->symbol);
        self::assertStringContainsString('sans SL', $view->alerts[0]->message);
    }

    public function testExpiredExecutionLockIsReportedAsStale(): void
    {
        $this->connection->insert('symbol_execution_lock', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'status' => 'ACTIVE',
            'owner_profile' => 'scalper_micro',
            'owner_decision_key' => 'decision-stale',
            'locked_at' => '2026-06-01 09:30:00',
            'expires_at' => '2026-06-01 09:45:00',
            'released_at' => null,
        ]);

        $view = (new RiskSummaryQuery(
            $this->connection,
            new MockClock('2026-06-01 10:05:00 UTC'),
        ))->getSummary();

        self::assertSame(1, $view->staleLockCount);
        self::assertSame('stale_symbol_lock', $view->alerts[0]->code);
        self::assertSame('BTCUSDT', $view->alerts[0]->symbol);
    }

    public function testOpenOrdersIncludeSentAndExchangeNumericStatesInTotals(): void
    {
        foreach (['sent', '1', '2'] as $index => $status) {
            $this->connection->insert('futures_order', [
                'exchange' => 'bitmart',
                'market_type' => 'perpetual',
                'symbol' => 'ETHUSDT',
                'side' => 1,
                'type' => 'limit',
                'status' => $status,
                'price' => '3500',
                'size' => 1,
                'client_order_id' => 'order-' . $index,
                'order_id' => 'exchange-order-' . $index,
                'updated_at' => '2026-06-01 10:0' . $index . ':00',
            ]);
        }

        $this->connection->insert('futures_plan_order', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'ETHUSDT',
            'side' => 1,
            'type' => 'take_profit',
            'status' => 'active',
            'trigger_price' => '3600',
            'price' => '3600',
            'size' => 1,
            'client_order_id' => 'plan-order',
            'order_id' => 'exchange-plan-order',
            'plan_type' => 'take_profit',
            'raw_data' => '{}',
            'updated_at' => '2026-06-01 10:05:00',
        ]);

        $view = (new RiskSummaryQuery(
            $this->connection,
            new MockClock('2026-06-01 10:05:00 UTC'),
        ))->getSummary();

        self::assertSame(3, $view->openOrderCount);
        self::assertSame(1, $view->openPlanOrderCount);
        self::assertSame(4, $view->openOrderTotalCount);
        self::assertSame(4, $view->toArray()['open_order_total_count']);
    }

    public function testActiveStopLossPlanOrderSuppressesMissingStopLossAlert(): void
    {
        $this->connection->insert('positions', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'LINKUSDT',
            'side' => 'LONG',
            'size' => '42',
            'avg_entry_price' => '14.25',
            'leverage' => 8,
            'unrealized_pnl' => '-3.10',
            'status' => 'OPEN',
            'payload' => json_encode(['source' => 'exchange'], JSON_THROW_ON_ERROR),
            'updated_at' => '2026-06-01 10:00:00',
        ]);
        $this->connection->insert('futures_plan_order', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'LINKUSDT',
            'side' => 2,
            'type' => 'stop_loss',
            'status' => 'active',
            'trigger_price' => '13.90',
            'price' => '13.90',
            'size' => 42,
            'client_order_id' => 'sl-plan-order',
            'order_id' => 'exchange-sl-plan-order',
            'plan_type' => 'stop_loss',
            'raw_data' => '{}',
            'updated_at' => '2026-06-01 10:01:00',
        ]);

        $view = (new RiskSummaryQuery(
            $this->connection,
            new MockClock('2026-06-01 10:05:00 UTC'),
        ))->getSummary();

        self::assertTrue($view->positions[0]['has_stop_loss']);
        self::assertSame(0, $view->criticalAlertCount);
    }
}
