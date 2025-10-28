<?php

namespace App\Tests\Domain\Indicator\Service;

use App\Common\Dto\IndicatorSnapshotDto;
use App\Common\Dto\KlineDto;
use App\Common\Enum\Timeframe;
use App\Domain\Ports\Out\IndicatorProviderPort;
use App\Indicator\Loader\IndicatorEngine;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndicatorEngineTest extends TestCase
{
    private IndicatorEngine $indicatorEngine;
    private IndicatorProviderPort|MockObject $indicatorProviderMock;

    protected function setUp(): void
    {
        $this->indicatorProviderMock = $this->createMock(IndicatorProviderPort::class);
        $this->indicatorEngine = new IndicatorEngine($this->indicatorProviderMock);
    }

    public function testCalculateAllIndicatorsWithValidKlines(): void
    {
        // Créer des klines de test
        $klines = $this->createTestKlines();

        // Configurer les mocks pour les calculs d'indicateurs
        $this->setupIndicatorMocks();

        $result = $this->indicatorEngine->calculateAllIndicators('BTCUSDT', Timeframe::H1, $klines);

        $this->assertInstanceOf(IndicatorSnapshotDto::class, $result);
        $this->assertEquals('BTCUSDT', $result->symbol);
        $this->assertEquals(Timeframe::H1, $result->timeframe);
        $this->assertNotNull($result->klineTime);
        $this->assertArrayHasKey('calculated_at', $result->meta);
        $this->assertArrayHasKey('klines_count', $result->meta);
        $this->assertEquals(count($klines), $result->meta['klines_count']);
    }

    public function testCalculateAllIndicatorsWithEmptyKlines(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Klines array cannot be empty');

        $this->indicatorEngine->calculateAllIndicators('BTCUSDT', Timeframe::H1, []);
    }

    public function testCalculateAllIndicatorsWithInsufficientData(): void
    {
        // Créer seulement 5 klines (insuffisant pour la plupart des indicateurs)
        $klines = $this->createTestKlines(5);

        // Configurer les mocks pour retourner des tableaux vides
        $this->indicatorProviderMock->method('calculateEMA')->willReturn([]);
        $this->indicatorProviderMock->method('calculateMACD')->willReturn([]);
        $this->indicatorProviderMock->method('calculateATR')->willReturn([]);
        $this->indicatorProviderMock->method('calculateRSI')->willReturn([]);
        $this->indicatorProviderMock->method('calculateVWAP')->willReturn([]);
        $this->indicatorProviderMock->method('calculateBollingerBands')->willReturn([]);

        $result = $this->indicatorEngine->calculateAllIndicators('ETHUSDT', Timeframe::H4, $klines);

        $this->assertInstanceOf(IndicatorSnapshotDto::class, $result);
        $this->assertEquals('ETHUSDT', $result->symbol);
        $this->assertEquals(Timeframe::H4, $result->timeframe);

        // Vérifier que les valeurs sont null quand les données sont insuffisantes
        $this->assertNull($result->ema20);
        $this->assertNull($result->ema50);
        $this->assertNull($result->macd);
        $this->assertNull($result->macdSignal);
        $this->assertNull($result->macdHistogram);
        $this->assertNull($result->atr);
        $this->assertNull($result->rsi);
        $this->assertNull($result->vwap);
    }

    public function testCalculateAllIndicatorsWithRealisticData(): void
    {
        // Créer des klines avec des données réalistes
        $klines = $this->createRealisticKlines();

        // Configurer les mocks avec des valeurs réalistes
        $this->setupRealisticIndicatorMocks();

        $result = $this->indicatorEngine->calculateAllIndicators('BTCUSDT', Timeframe::H1, $klines);

        $this->assertInstanceOf(IndicatorSnapshotDto::class, $result);
        $this->assertEquals('BTCUSDT', $result->symbol);
        $this->assertEquals(Timeframe::H1, $result->timeframe);

        // Vérifier que les indicateurs sont calculés
        $this->assertNotNull($result->ema20);
        $this->assertNotNull($result->ema50);
        $this->assertNotNull($result->macd);
        $this->assertNotNull($result->macdSignal);
        $this->assertNotNull($result->macdHistogram);
        $this->assertNotNull($result->atr);
        $this->assertNotNull($result->rsi);
        $this->assertNotNull($result->vwap);

        // Vérifier les types des valeurs
        $this->assertInstanceOf(BigDecimal::class, $result->ema20);
        $this->assertInstanceOf(BigDecimal::class, $result->ema50);
        $this->assertInstanceOf(BigDecimal::class, $result->macd);
        $this->assertInstanceOf(BigDecimal::class, $result->macdSignal);
        $this->assertInstanceOf(BigDecimal::class, $result->macdHistogram);
        $this->assertInstanceOf(BigDecimal::class, $result->atr);
        $this->assertIsFloat($result->rsi);
        $this->assertInstanceOf(BigDecimal::class, $result->vwap);
    }

    public function testSaveIndicatorSnapshot(): void
    {
        $snapshot = new IndicatorSnapshotDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::H1,
            klineTime: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );

        $this->indicatorProviderMock
            ->expects($this->once())
            ->method('saveIndicatorSnapshot')
            ->with($snapshot);

        $this->indicatorEngine->saveIndicatorSnapshot($snapshot);
    }

    public function testGetLastIndicatorSnapshot(): void
    {
        $expectedSnapshot = new IndicatorSnapshotDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::H1,
            klineTime: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );

        $this->indicatorProviderMock
            ->expects($this->once())
            ->method('getLastIndicatorSnapshot')
            ->with('BTCUSDT', Timeframe::H1)
            ->willReturn($expectedSnapshot);

        $result = $this->indicatorEngine->getLastIndicatorSnapshot('BTCUSDT', Timeframe::H1);

        $this->assertSame($expectedSnapshot, $result);
    }

    public function testGetLastIndicatorSnapshotReturnsNull(): void
    {
        $this->indicatorProviderMock
            ->expects($this->once())
            ->method('getLastIndicatorSnapshot')
            ->with('ETHUSDT', Timeframe::H4)
            ->willReturn(null);

        $result = $this->indicatorEngine->getLastIndicatorSnapshot('ETHUSDT', Timeframe::H4);

        $this->assertNull($result);
    }

    private function createTestKlines(int $count = 50): array
    {
        $klines = [];
        $baseTime = new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC'));
        $basePrice = 50000.0;

        for ($i = 0; $i < $count; $i++) {
            $price = $basePrice + ($i * 100); // Prix croissant
            $klines[] = new KlineDto(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::H1,
                openTime: $baseTime->modify("+{$i} hours"),
                open: BigDecimal::of($price),
                high: BigDecimal::of($price + 50),
                low: BigDecimal::of($price - 50),
                close: BigDecimal::of($price + 25),
                volume: BigDecimal::of(1000 + $i * 10)
            );
        }

        return $klines;
    }

    private function createRealisticKlines(): array
    {
        $klines = [];
        $baseTime = new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC'));

        // Données réalistes de prix BTC
        $prices = [
            50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900,
            51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900,
            52000, 52100, 52200, 52300, 52400, 52500, 52600, 52700, 52800, 52900,
            53000, 53100, 53200, 53300, 53400, 53500, 53600, 53700, 53800, 53900,
            54000, 54100, 54200, 54300, 54400, 54500, 54600, 54700, 54800, 54900
        ];

        foreach ($prices as $i => $price) {
            $klines[] = new KlineDto(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::H1,
                openTime: $baseTime->modify("+{$i} hours"),
                open: BigDecimal::of($price),
                high: BigDecimal::of($price + 100),
                low: BigDecimal::of($price - 100),
                close: BigDecimal::of($price + 50),
                volume: BigDecimal::of(1000 + $i * 20)
            );
        }

        return $klines;
    }

    private function setupIndicatorMocks(): void
    {
        // EMA
        $this->indicatorProviderMock->method('calculateEMA')
            ->willReturnMap([
                [[50000, 50100, 50200], 20, [50100, 50200]],
                [[50000, 50100, 50200], 50, [50100, 50200]]
            ]);

        // MACD
        $this->indicatorProviderMock->method('calculateMACD')
            ->willReturn([
                'macd' => [0.5, 0.6],
                'signal' => [0.4, 0.5],
                'histogram' => [0.1, 0.1]
            ]);

        // ATR
        $this->indicatorProviderMock->method('calculateATR')
            ->willReturn([100.0, 105.0]);

        // RSI
        $this->indicatorProviderMock->method('calculateRSI')
            ->willReturn([65.5, 67.2]);

        // VWAP
        $this->indicatorProviderMock->method('calculateVWAP')
            ->willReturn([50100.0, 50200.0]);

        // Bollinger Bands
        $this->indicatorProviderMock->method('calculateBollingerBands')
            ->willReturn([
                'upper' => [51000, 51100],
                'middle' => [50000, 50100],
                'lower' => [49000, 49100]
            ]);
    }

    private function setupRealisticIndicatorMocks(): void
    {
        // EMA avec des valeurs réalistes
        $this->indicatorProviderMock->method('calculateEMA')
            ->willReturnMap([
                [array_fill(0, 50, 50000), 20, array_fill(0, 30, 52000)],
                [array_fill(0, 50, 50000), 50, array_fill(0, 1, 51000)]
            ]);

        // MACD avec des valeurs réalistes
        $this->indicatorProviderMock->method('calculateMACD')
            ->willReturn([
                'macd' => array_fill(0, 30, 150.0),
                'signal' => array_fill(0, 30, 140.0),
                'histogram' => array_fill(0, 30, 10.0)
            ]);

        // ATR avec des valeurs réalistes
        $this->indicatorProviderMock->method('calculateATR')
            ->willReturn(array_fill(0, 30, 200.0));

        // RSI avec des valeurs réalistes
        $this->indicatorProviderMock->method('calculateRSI')
            ->willReturn(array_fill(0, 30, 65.5));

        // VWAP avec des valeurs réalistes
        $this->indicatorProviderMock->method('calculateVWAP')
            ->willReturn(array_fill(0, 30, 52500.0));

        // Bollinger Bands avec des valeurs réalistes
        $this->indicatorProviderMock->method('calculateBollingerBands')
            ->willReturn([
                'upper' => array_fill(0, 30, 53000.0),
                'middle' => array_fill(0, 30, 52000.0),
                'lower' => array_fill(0, 30, 51000.0)
            ]);
    }
}

