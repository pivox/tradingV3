<?php

declare(strict_types=1);

namespace App\Tests\Front\Query;

use App\Front\Query\InvestigationQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvestigationQuery::class)]
final class InvestigationQueryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE mtf_run_symbol (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id VARCHAR(80) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL,
    execution_tf VARCHAR(10) NULL,
    blocking_tf VARCHAR(10) NULL,
    signal_side VARCHAR(10) NULL,
    current_price NUMERIC NULL,
    trading_decision VARCHAR(30) NULL,
    error TEXT NULL,
    context TEXT NULL,
    created_at DATETIME NOT NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE entry_zone_live (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol VARCHAR(50) NOT NULL,
    side VARCHAR(10) NOT NULL,
    price_min NUMERIC NOT NULL,
    price_max NUMERIC NOT NULL,
    atr_pct_1m NUMERIC NULL,
    vwap NUMERIC NULL,
    volume_ratio NUMERIC NULL,
    config_profile VARCHAR(80) NULL,
    config_version VARCHAR(80) NULL,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    status VARCHAR(30) NULL
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
    filled_size NUMERIC NULL,
    client_order_id VARCHAR(80) NULL,
    order_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
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
    execution_price NUMERIC NULL,
    price NUMERIC NULL,
    size INTEGER NULL,
    plan_type VARCHAR(20) NULL,
    client_order_id VARCHAR(80) NULL,
    order_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL);
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE order_intent (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange VARCHAR(32) NOT NULL,
    market_type VARCHAR(32) NOT NULL,
    decision_key VARCHAR(255) NULL,
    strategy_profile VARCHAR(80) NULL,
    strategy_version VARCHAR(80) NULL,
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
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    sent_at DATETIME NULL
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

    public function testInvestigationScopesMtfSymbolsToRequestedTimeWindowAndReadsEntryZoneAtr(): void
    {
        $this->connection->insert('mtf_run_symbol', [
            'run_id' => 'run-in-window',
            'symbol' => 'LINKUSDT',
            'status' => 'VALID',
            'execution_tf' => '1m',
            'blocking_tf' => null,
            'signal_side' => 'LONG',
            'current_price' => '14.2',
            'trading_decision' => 'LONG',
            'error' => null,
            'context' => '{}',
            'created_at' => '2026-06-01 10:15:00',
        ]);
        $this->connection->insert('mtf_run_symbol', [
            'run_id' => 'run-outside-window',
            'symbol' => 'LINKUSDT',
            'status' => 'VALID',
            'execution_tf' => '1m',
            'blocking_tf' => null,
            'signal_side' => 'LONG',
            'current_price' => '14.3',
            'trading_decision' => 'LONG',
            'error' => null,
            'context' => '{}',
            'created_at' => '2026-06-01 14:15:00',
        ]);
        $this->connection->insert('entry_zone_live', [
            'symbol' => 'LINKUSDT',
            'side' => 'LONG',
            'price_min' => '14.10',
            'price_max' => '14.30',
            'atr_pct_1m' => '0.0042',
            'vwap' => '14.20',
            'volume_ratio' => '1.1',
            'config_profile' => 'scalper_micro',
            'config_version' => 'test',
            'valid_from' => '2026-06-01 10:00:00',
            'valid_until' => '2026-06-01 10:05:00',
            'created_at' => '2026-06-01 10:01:00',
            'status' => 'active',
        ]);

        $result = (new InvestigationQuery($this->connection, sys_get_temp_dir()))->investigate(
            'LINKUSDT',
            '2026-06-01 10:00:00',
            null,
            null,
        );

        self::assertSame(['run-in-window'], array_column($result['sections']['mtf_symbols'], 'run_id'));
        self::assertSame('0.0042', (string) $result['sections']['entry_zones'][0]['atr_pct_1m']);
    }

    public function testInvestigationSkipsSectionsThatCannotBeScopedByTheSubmittedCriteria(): void
    {
        $this->connection->insert('mtf_run_symbol', [
            'run_id' => 'unrelated-run',
            'symbol' => 'LINKUSDT',
            'status' => 'VALID',
            'execution_tf' => '1m',
            'blocking_tf' => null,
            'signal_side' => 'LONG',
            'current_price' => '14.2',
            'trading_decision' => 'LONG',
            'error' => null,
            'context' => '{}',
            'created_at' => '2026-06-01 10:15:00',
        ]);
        $this->connection->insert('futures_order', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'LINKUSDT',
            'side' => 1,
            'type' => 'limit',
            'status' => 'open',
            'price' => '14.2',
            'size' => 1,
            'filled_size' => '0',
            'client_order_id' => 'unrelated-order',
            'order_id' => 'exchange-unrelated-order',
            'created_at' => '2026-06-01 10:15:00',
            'updated_at' => '2026-06-01 10:15:00',
        ]);
        $this->connection->insert('futures_plan_order', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'LINKUSDT',
            'side' => 1,
            'type' => 'stop_loss',
            'status' => 'active',
            'trigger_price' => '13.9',
            'execution_price' => null,
            'price' => '13.9',
            'size' => 1,
            'plan_type' => 'stop_loss',
            'client_order_id' => 'unrelated-plan',
            'order_id' => 'exchange-unrelated-plan',
            'created_at' => '2026-06-01 10:15:00',
            'updated_at' => '2026-06-01 10:15:00',
        ]);

        $decisionKeyOnly = (new InvestigationQuery($this->connection, sys_get_temp_dir()))->investigate(
            null,
            null,
            'decision-key-only',
            null,
        );
        $runIdOnly = (new InvestigationQuery($this->connection, sys_get_temp_dir()))->investigate(
            null,
            null,
            null,
            'run-id-only',
        );

        self::assertSame([], $decisionKeyOnly['sections']['mtf_symbols']);
        self::assertSame([], $runIdOnly['sections']['orders']);
        self::assertSame([], $runIdOnly['sections']['plan_orders']);
    }

    public function testDecisionKeyInvestigationFindsLifecycleRowsByOrderIds(): void
    {
        $this->connection->insert('order_intent', [
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'decision_key' => 'decision-key',
            'strategy_profile' => 'scalper_micro',
            'strategy_version' => 'test',
            'symbol' => 'LINKUSDT',
            'timeframe' => '1m',
            'side' => 1,
            'type' => 'limit',
            'status' => 'SENT',
            'price' => '14.2',
            'size' => 1,
            'client_order_id' => 'decision-client-order',
            'order_id' => null,
            'exchange_order_id' => 'exchange-decision-order',
            'failure_reason' => null,
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:01:00',
            'sent_at' => '2026-06-01 10:01:00',
        ]);
        $this->connection->insert('trade_lifecycle_event', [
            'symbol' => 'LINKUSDT',
            'event_type' => 'order_submitted',
            'run_id' => null,
            'order_id' => 'exchange-decision-order',
            'client_order_id' => null,
            'side' => 'LONG',
            'qty' => '1',
            'price' => '14.2',
            'timeframe' => '1m',
            'config_profile' => 'scalper_micro',
            'config_version' => 'test',
            'reason_code' => null,
            'extra' => '{}',
            'happened_at' => '2026-06-01 10:15:00',
        ]);

        $result = (new InvestigationQuery($this->connection, sys_get_temp_dir()))->investigate(
            null,
            null,
            'decision-key',
            null,
        );

        self::assertSame(['exchange-decision-order'], array_column($result['sections']['lifecycle'], 'order_id'));
    }
}
