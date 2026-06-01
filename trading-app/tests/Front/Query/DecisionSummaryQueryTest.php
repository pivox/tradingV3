<?php

declare(strict_types=1);

namespace App\Tests\Front\Query;

use App\Front\Query\DecisionSummaryQuery;
use App\Front\ViewModel\DecisionSummaryView;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecisionSummaryQuery::class)]
#[CoversClass(DecisionSummaryView::class)]
final class DecisionSummaryQueryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE mtf_run (
    run_id VARCHAR(64) PRIMARY KEY,
    status VARCHAR(64) NOT NULL,
    execution_time_seconds NUMERIC NOT NULL,
    symbols_requested INTEGER NOT NULL,
    symbols_processed INTEGER NOT NULL,
    symbols_successful INTEGER NOT NULL,
    symbols_failed INTEGER NOT NULL,
    symbols_skipped INTEGER NOT NULL,
    success_rate NUMERIC NOT NULL,
    dry_run BOOLEAN NOT NULL,
    force_run BOOLEAN NOT NULL,
    current_tf VARCHAR(8) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    workers INTEGER NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE mtf_run_symbol (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id VARCHAR(64) NOT NULL,
    symbol VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL,
    execution_tf VARCHAR(8) NULL,
    blocking_tf VARCHAR(8) NULL,
    signal_side VARCHAR(8) NULL,
    current_price NUMERIC NULL,
    trading_decision TEXT NULL,
    error TEXT NULL,
    context TEXT NULL,
    created_at DATETIME NOT NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE order_intent (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    timeframe VARCHAR(10) NULL,
    side INTEGER NULL,
    type VARCHAR(20) NULL,
    status VARCHAR(30) NOT NULL,
    price NUMERIC NULL,
    size INTEGER NULL,
    client_order_id VARCHAR(80) NULL,
    order_id VARCHAR(80) NULL,
    exchange_order_id VARCHAR(80) NULL,
    failure_reason TEXT NULL,
    decision_key VARCHAR(255) NULL,
    strategy_profile VARCHAR(80) NULL,
    strategy_version VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    sent_at DATETIME NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE trade_zone_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    happened_at DATETIME NOT NULL,
    reason VARCHAR(80) NOT NULL,
    decision_key VARCHAR(255) NULL,
    timeframe VARCHAR(10) NULL,
    config_profile VARCHAR(80) NULL,
    zone_min NUMERIC NULL,
    zone_max NUMERIC NULL,
    candidate_price NUMERIC NULL,
    zone_dev_pct NUMERIC NULL,
    zone_max_dev_pct NUMERIC NULL,
    atr_pct NUMERIC NULL,
    spread_bps NUMERIC NULL,
    volume_ratio NUMERIC NULL,
    vwap_distance_pct NUMERIC NULL,
    entry_zone_width_pct NUMERIC NULL,
    mtf_level VARCHAR(20) NULL,
    category VARCHAR(80) NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE trade_lifecycle_event (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol VARCHAR(50) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    run_id VARCHAR(80) NULL,
    order_id VARCHAR(80) NULL,
    client_order_id VARCHAR(80) NULL,
    side VARCHAR(10) NULL,
    qty NUMERIC NULL,
    price NUMERIC NULL,
    timeframe VARCHAR(10) NULL,
    config_profile VARCHAR(80) NULL,
    config_version VARCHAR(80) NULL,
    reason_code VARCHAR(80) NULL,
    extra TEXT NULL,
    happened_at DATETIME NOT NULL
)
SQL);
    }

    public function testRejectedSymbolShowsReadablePrimaryReason(): void
    {
        $this->connection->insert('mtf_run', [
            'run_id' => 'run-1',
            'status' => 'completed',
            'execution_time_seconds' => '18.3',
            'symbols_requested' => 1,
            'symbols_processed' => 1,
            'symbols_successful' => 0,
            'symbols_failed' => 1,
            'symbols_skipped' => 0,
            'success_rate' => '0.00',
            'dry_run' => 0,
            'force_run' => 0,
            'current_tf' => '1m',
            'started_at' => '2026-06-01 10:00:00',
            'finished_at' => '2026-06-01 10:00:18',
            'workers' => 8,
        ]);
        $this->connection->insert('mtf_run_symbol', [
            'run_id' => 'run-1',
            'symbol' => 'ETHUSDT',
            'status' => 'NO_LONG_NO_SHORT',
            'execution_tf' => '1m',
            'blocking_tf' => '5m',
            'signal_side' => null,
            'current_price' => '3720.10',
            'trading_decision' => json_encode(['status' => 'SKIPPED', 'reason' => 'filters_mandatory_failed_execution_selector_empty'], JSON_THROW_ON_ERROR),
            'error' => null,
            'context' => json_encode(['rules_failed' => ['rsi_bullish', 'near_vwap']], JSON_THROW_ON_ERROR),
            'created_at' => '2026-06-01 10:00:18',
        ]);

        $view = (new DecisionSummaryQuery($this->connection))->latest();

        self::assertSame('run-1', $view->runs[0]['run_id']);
        self::assertSame('ETHUSDT', $view->symbols[0]['symbol']);
        self::assertSame('Filtres obligatoires non validés', $view->symbols[0]['primary_reason']);
        self::assertSame(['rsi_bullish', 'near_vwap'], $view->symbols[0]['failed_rules']);
    }

    public function testDetailFindsLifecycleRowsThroughOrderIntentOrderIds(): void
    {
        $this->connection->insert('order_intent', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'LINKUSDT',
            'timeframe' => '1m',
            'side' => 1,
            'type' => 'limit',
            'status' => 'SENT',
            'price' => '14.2',
            'size' => 1,
            'client_order_id' => 'client-order-1',
            'order_id' => null,
            'exchange_order_id' => 'exchange-order-1',
            'failure_reason' => null,
            'decision_key' => 'decision-1',
            'strategy_profile' => 'scalper_micro',
            'strategy_version' => 'test',
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:01:00',
            'sent_at' => '2026-06-01 10:01:00',
        ]);
        $this->connection->insert('trade_lifecycle_event', [
            'symbol' => 'LINKUSDT',
            'event_type' => 'order_submitted',
            'run_id' => null,
            'order_id' => 'exchange-order-1',
            'client_order_id' => null,
            'side' => 'LONG',
            'qty' => '1',
            'price' => '14.2',
            'timeframe' => '1m',
            'config_profile' => 'scalper_micro',
            'config_version' => 'test',
            'reason_code' => null,
            'extra' => '{}',
            'happened_at' => '2026-06-01 10:02:00',
        ]);

        $detail = (new DecisionSummaryQuery($this->connection))->detail('decision-1');

        self::assertSame(['exchange-order-1'], array_column($detail['lifecycle_events'], 'order_id'));
    }
}
