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
}
