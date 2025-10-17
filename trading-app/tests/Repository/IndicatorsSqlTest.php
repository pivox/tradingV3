<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use PDO;

final class IndicatorsSqlTest extends TestCase
{
    private function getPdo(): PDO
    {
        $dsn = getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=test;charset=utf8mb4';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') ?: '';
        return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testUpsertIndicatorsIntraday(): void
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare('INSERT INTO indicators_intraday(symbol,timeframe,ts,atr,atr_raw,rsi,vwap,volume_ratio) VALUES(?,?,?,?,?,?,?,?)');
        $ok = $stmt->execute(['BTCUSDT','1m','2025-10-17 12:00:00', '25.123456789012','25.223456789012','55.55','27000.123456789012','1.23']);
        $this->assertTrue($ok);

        $row = $pdo->query("SELECT * FROM indicators_intraday WHERE symbol='BTCUSDT' AND timeframe='1m' AND ts='2025-10-17 12:00:00'")
                    ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('BTCUSDT', $row['symbol']);
        $this->assertSame('1m', $row['timeframe']);
        $this->assertSame('55.5500', sprintf('%.4f', (float)$row['rsi']));
    }

    public function testInsertEntryZone(): void
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare('INSERT INTO entry_zones(symbol,side,timeframe,ts,zone_low,zone_high,is_valid_entry,cancel_after,suggested_leverage,suggested_stop,evidence) VALUES(?,?,?,?,?,?,?,?,?,?,JSON_OBJECT("k","v"))');
        $ok = $stmt->execute(['BTCUSDT','LONG','1m','2025-10-17 12:00:00','26900.123456789012','27100.123456789012',1,'2025-10-17 12:04:00','10.25','26800.123456789012']);
        $this->assertTrue($ok);

        $row = $pdo->query("SELECT * FROM entry_zones WHERE symbol='BTCUSDT' AND side='LONG' AND timeframe='1m' AND ts='2025-10-17 12:00:00'")
                    ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('1', (string)$row['is_valid_entry']);
        $this->assertSame('10.2500', sprintf('%.4f', (float)$row['suggested_leverage']));
    }
}


