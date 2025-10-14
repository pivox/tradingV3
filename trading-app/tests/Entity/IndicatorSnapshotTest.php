<?php

namespace App\Tests\Entity;

use App\Entity\IndicatorSnapshot;
use App\Domain\Common\Enum\Timeframe;
use PHPUnit\Framework\TestCase;

class IndicatorSnapshotTest extends TestCase
{
    private IndicatorSnapshot $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = new IndicatorSnapshot();
    }

    public function testBasicProperties(): void
    {
        $this->snapshot
            ->setSymbol('BTCUSDT')
            ->setTimeframe(Timeframe::H1)
            ->setKlineTime(new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC')));

        $this->assertEquals('BTCUSDT', $this->snapshot->getSymbol());
        $this->assertEquals(Timeframe::H1, $this->snapshot->getTimeframe());
        $this->assertEquals('2024-01-01 12:00:00', $this->snapshot->getKlineTime()->format('Y-m-d H:i:s'));
    }

    public function testIndicatorValues(): void
    {
        $this->snapshot
            ->setEma20('52000.50')
            ->setEma50('51500.25')
            ->setMacd('150.75')
            ->setMacdSignal('140.50')
            ->setMacdHistogram('10.25')
            ->setAtr('200.00')
            ->setRsi(65.5)
            ->setVwap('52500.00')
            ->setBbUpper('53000.00')
            ->setBbMiddle('52000.00')
            ->setBbLower('51000.00')
            ->setMa9('52100.00')
            ->setMa21('51900.00');

        $this->assertEquals('52000.50', $this->snapshot->getEma20());
        $this->assertEquals('51500.25', $this->snapshot->getEma50());
        $this->assertEquals('150.75', $this->snapshot->getMacd());
        $this->assertEquals('140.50', $this->snapshot->getMacdSignal());
        $this->assertEquals('10.25', $this->snapshot->getMacdHistogram());
        $this->assertEquals('200.00', $this->snapshot->getAtr());
        $this->assertEquals(65.5, $this->snapshot->getRsi());
        $this->assertEquals('52500.00', $this->snapshot->getVwap());
        $this->assertEquals('53000.00', $this->snapshot->getBbUpper());
        $this->assertEquals('52000.00', $this->snapshot->getBbMiddle());
        $this->assertEquals('51000.00', $this->snapshot->getBbLower());
        $this->assertEquals('52100.00', $this->snapshot->getMa9());
        $this->assertEquals('51900.00', $this->snapshot->getMa21());
    }

    public function testCustomValues(): void
    {
        $this->snapshot
            ->setValue('custom_indicator', 123.45)
            ->setValue('another_value', 'test');

        $this->assertEquals(123.45, $this->snapshot->getValue('custom_indicator'));
        $this->assertEquals('test', $this->snapshot->getValue('another_value'));
        $this->assertNull($this->snapshot->getValue('non_existent'));
    }

    public function testValuesArray(): void
    {
        $values = [
            'ema20' => '52000.50',
            'ema50' => '51500.25',
            'rsi' => 65.5,
            'custom' => 'value'
        ];

        $this->snapshot->setValues($values);

        $this->assertEquals($values, $this->snapshot->getValues());
        $this->assertEquals('52000.50', $this->snapshot->getValue('ema20'));
        $this->assertEquals(65.5, $this->snapshot->getValue('rsi'));
    }

    public function testTimestamps(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->snapshot->getInsertedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->snapshot->getUpdatedAt());
        
        // Les timestamps doivent être proches de maintenant
        $this->assertLessThan(5, abs($now->getTimestamp() - $this->snapshot->getInsertedAt()->getTimestamp()));
        $this->assertLessThan(5, abs($now->getTimestamp() - $this->snapshot->getUpdatedAt()->getTimestamp()));
    }

    public function testNullValues(): void
    {
        $this->snapshot
            ->setEma20(null)
            ->setEma50(null)
            ->setMacd(null)
            ->setRsi(null);

        $this->assertNull($this->snapshot->getEma20());
        $this->assertNull($this->snapshot->getEma50());
        $this->assertNull($this->snapshot->getMacd());
        $this->assertNull($this->snapshot->getRsi());
    }

    public function testFluentInterface(): void
    {
        $result = $this->snapshot
            ->setSymbol('ETHUSDT')
            ->setTimeframe(Timeframe::H4)
            ->setKlineTime(new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')))
            ->setEma20('3000.00')
            ->setRsi(70.0);

        $this->assertSame($this->snapshot, $result);
        $this->assertEquals('ETHUSDT', $this->snapshot->getSymbol());
        $this->assertEquals(Timeframe::H4, $this->snapshot->getTimeframe());
        $this->assertEquals('3000.00', $this->snapshot->getEma20());
        $this->assertEquals(70.0, $this->snapshot->getRsi());
    }

    public function testAllTimeframes(): void
    {
        $timeframes = [
            Timeframe::M1,
            Timeframe::M5,
            Timeframe::M15,
            Timeframe::M30,
            Timeframe::H1,
            Timeframe::H4,
            Timeframe::H12,
            Timeframe::D1,
            Timeframe::W1,
            Timeframe::M1_MONTH
        ];

        foreach ($timeframes as $timeframe) {
            $this->snapshot->setTimeframe($timeframe);
            $this->assertEquals($timeframe, $this->snapshot->getTimeframe());
        }
    }

    public function testPrecisionHandling(): void
    {
        // Test avec des valeurs à haute précision
        $this->snapshot
            ->setEma20('52000.123456789')
            ->setMacd('150.987654321')
            ->setRsi(65.123456789);

        $this->assertEquals('52000.123456789', $this->snapshot->getEma20());
        $this->assertEquals('150.987654321', $this->snapshot->getMacd());
        $this->assertEquals(65.123456789, $this->snapshot->getRsi());
    }

    public function testLargeNumbers(): void
    {
        // Test avec de très grands nombres
        $this->snapshot
            ->setEma20('999999999.99')
            ->setVwap('1000000000.00')
            ->setRsi(99.99);

        $this->assertEquals('999999999.99', $this->snapshot->getEma20());
        $this->assertEquals('1000000000.00', $this->snapshot->getVwap());
        $this->assertEquals(99.99, $this->snapshot->getRsi());
    }

    public function testSmallNumbers(): void
    {
        // Test avec de très petits nombres
        $this->snapshot
            ->setEma20('0.000001')
            ->setMacd('0.000000001')
            ->setRsi(0.01);

        $this->assertEquals('0.000001', $this->snapshot->getEma20());
        $this->assertEquals('0.000000001', $this->snapshot->getMacd());
        $this->assertEquals(0.01, $this->snapshot->getRsi());
    }
}

