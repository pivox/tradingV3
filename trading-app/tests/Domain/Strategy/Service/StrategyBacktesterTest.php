<?php

namespace App\Tests\Domain\Strategy\Service;

use App\Domain\Common\Dto\BacktestRequestDto;
use App\Domain\Common\Dto\BacktestResultDto;
use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Strategy\Service\StrategyBacktester;
use App\Service\BacktestClockService;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;

class StrategyBacktesterTest extends TestCase
{
    private StrategyBacktester $backtester;
    private BacktestClockService $clockService;

    protected function setUp(): void
    {
        $clock = new Clock();
        $this->clockService = new BacktestClockService($clock);
        $this->backtester = new StrategyBacktester([], $this->clockService);
    }

    public function testBacktestWithValidRequest(): void
    {
        $request = new BacktestRequestDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::H1,
            startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
            strategies: ['Test Strategy'],
            initialCapital: 10000.0,
            riskPerTrade: 0.02,
            commissionRate: 0.001,
            name: 'Test Backtest',
            description: 'Test de validation du backtest'
        );

        $result = $this->backtester->runBacktest($request);

        $this->assertInstanceOf(BacktestResultDto::class, $result);
        $this->assertEquals('BTCUSDT', $result->symbol);
        $this->assertEquals(Timeframe::H1, $result->timeframe);
        $this->assertEquals(10000.0, $result->initialCapital);
        $this->assertIsFloat($result->finalCapital);
        $this->assertIsFloat($result->totalReturn);
        $this->assertIsFloat($result->totalReturnPercentage);
        $this->assertIsInt($result->totalTrades);
        $this->assertIsInt($result->winningTrades);
        $this->assertIsInt($result->losingTrades);
        $this->assertIsFloat($result->winRate);
        $this->assertIsFloat($result->profitFactor);
        $this->assertIsFloat($result->sharpeRatio);
        $this->assertIsFloat($result->maxDrawdown);
        $this->assertIsFloat($result->maxDrawdownPercentage);
        $this->assertIsArray($result->trades);
        $this->assertIsArray($result->equityCurve);
        $this->assertIsArray($result->monthlyReturns);
    }

    public function testBacktestWithFixedTime(): void
    {
        // Définir une heure fixe pour le backtest
        $fixedTime = new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC'));
        $this->clockService->setFixedTime($fixedTime);

        $request = new BacktestRequestDto(
            symbol: 'ETHUSDT',
            timeframe: Timeframe::H4,
            startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
            strategies: ['Test Strategy'],
            initialCapital: 5000.0,
            riskPerTrade: 0.01,
            commissionRate: 0.0005,
            name: 'Fixed Time Backtest'
        );

        $result = $this->backtester->runBacktest($request);

        $this->assertInstanceOf(BacktestResultDto::class, $result);
        $this->assertEquals('ETHUSDT', $result->symbol);
        $this->assertEquals(Timeframe::H4, $result->timeframe);
        $this->assertEquals(5000.0, $result->initialCapital);

        // Vérifier que l'heure fixe est toujours active
        $this->assertTrue($this->clockService->isFixedTimeEnabled());
        $this->assertEquals($fixedTime, $this->clockService->getFixedTime());
    }

    public function testBacktestPerformanceMetrics(): void
    {
        $request = new BacktestRequestDto(
            symbol: 'ADAUSDT',
            timeframe: Timeframe::D1,
            startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable('2024-01-31 23:59:59', new \DateTimeZone('UTC')),
            strategies: ['Test Strategy'],
            initialCapital: 20000.0,
            riskPerTrade: 0.03,
            commissionRate: 0.002,
            name: 'Performance Test'
        );

        $result = $this->backtester->runBacktest($request);

        // Vérifier les métriques de performance
        $this->assertGreaterThanOrEqual(0, $result->totalTrades);
        $this->assertGreaterThanOrEqual(0, $result->winningTrades);
        $this->assertGreaterThanOrEqual(0, $result->losingTrades);
        $this->assertEquals($result->totalTrades, $result->winningTrades + $result->losingTrades);
        
        if ($result->totalTrades > 0) {
            $this->assertGreaterThanOrEqual(0, $result->winRate);
            $this->assertLessThanOrEqual(100, $result->winRate);
        }

        $this->assertGreaterThanOrEqual(0, $result->maxDrawdown);
        $this->assertGreaterThanOrEqual(0, $result->maxDrawdownPercentage);
        $this->assertLessThanOrEqual(100, $result->maxDrawdownPercentage);

        // Vérifier la cohérence des calculs
        $expectedFinalCapital = $result->initialCapital + $result->totalReturn;
        $this->assertEqualsWithDelta($expectedFinalCapital, $result->finalCapital, 0.01);

        if ($result->initialCapital > 0) {
            $expectedReturnPercentage = ($result->totalReturn / $result->initialCapital) * 100;
            $this->assertEqualsWithDelta($expectedReturnPercentage, $result->totalReturnPercentage, 0.01);
        }
    }

    public function testBacktestWithDifferentTimeframes(): void
    {
        $timeframes = [Timeframe::M1, Timeframe::M5, Timeframe::M15, Timeframe::M30, Timeframe::H1, Timeframe::H4, Timeframe::D1];

        foreach ($timeframes as $timeframe) {
            $request = new BacktestRequestDto(
                symbol: 'BTCUSDT',
                timeframe: $timeframe,
                startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
                endDate: new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
                strategies: ['Test Strategy'],
                initialCapital: 10000.0,
                riskPerTrade: 0.02,
                commissionRate: 0.001,
                name: "Backtest {$timeframe->value}"
            );

            $result = $this->backtester->runBacktest($request);

            $this->assertInstanceOf(BacktestResultDto::class, $result);
            $this->assertEquals($timeframe, $result->timeframe);
            $this->assertEquals('BTCUSDT', $result->symbol);
        }
    }

    public function testBacktestWithDifferentRiskLevels(): void
    {
        $riskLevels = [0.005, 0.01, 0.02, 0.05, 0.1]; // 0.5%, 1%, 2%, 5%, 10%

        foreach ($riskLevels as $risk) {
            $request = new BacktestRequestDto(
                symbol: 'ETHUSDT',
                timeframe: Timeframe::H1,
                startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
                endDate: new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
                strategies: ['Test Strategy'],
                initialCapital: 10000.0,
                riskPerTrade: $risk,
                commissionRate: 0.001,
                name: "Risk Test {$risk}"
            );

            $result = $this->backtester->runBacktest($request);

            $this->assertInstanceOf(BacktestResultDto::class, $result);
            $this->assertEquals($risk, $request->riskPerTrade);
            $this->assertGreaterThanOrEqual(0, $result->totalTrades);
        }
    }

    public function testBacktestEquityCurve(): void
    {
        $request = new BacktestRequestDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::H1,
            startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable('2024-01-03 00:00:00', new \DateTimeZone('UTC')),
            strategies: ['Test Strategy'],
            initialCapital: 10000.0,
            riskPerTrade: 0.02,
            commissionRate: 0.001,
            name: 'Equity Curve Test'
        );

        $result = $this->backtester->runBacktest($request);

        $this->assertIsArray($result->equityCurve);
        
        if (!empty($result->equityCurve)) {
            // Vérifier que la courbe d'équité commence avec le capital initial
            $firstEquity = $result->equityCurve[0];
            $this->assertArrayHasKey('date', $firstEquity);
            $this->assertArrayHasKey('equity', $firstEquity);
            $this->assertEquals($result->initialCapital, $firstEquity['equity']);

            // Vérifier que la courbe d'équité se termine avec le capital final
            $lastEquity = end($result->equityCurve);
            $this->assertEqualsWithDelta($result->finalCapital, $lastEquity['equity'], 0.01);
        }
    }

    public function testBacktestMonthlyReturns(): void
    {
        $request = new BacktestRequestDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::D1,
            startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable('2024-03-31 23:59:59', new \DateTimeZone('UTC')),
            strategies: ['Test Strategy'],
            initialCapital: 10000.0,
            riskPerTrade: 0.02,
            commissionRate: 0.001,
            name: 'Monthly Returns Test'
        );

        $result = $this->backtester->runBacktest($request);

        $this->assertIsArray($result->monthlyReturns);
        
        // Vérifier que les retours mensuels sont cohérents
        $totalMonthlyReturn = 0;
        foreach ($result->monthlyReturns as $month => $return) {
            $this->assertIsString($month);
            $this->assertIsFloat($return);
            $totalMonthlyReturn += $return;
        }

        // Le total des retours mensuels devrait être proche du retour total
        if (!empty($result->monthlyReturns)) {
            $this->assertEqualsWithDelta($result->totalReturnPercentage, $totalMonthlyReturn, 1.0);
        }
    }

    public function testBacktestTradesStructure(): void
    {
        $request = new BacktestRequestDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::H1,
            startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
            strategies: ['Test Strategy'],
            initialCapital: 10000.0,
            riskPerTrade: 0.02,
            commissionRate: 0.001,
            name: 'Trades Structure Test'
        );

        $result = $this->backtester->runBacktest($request);

        $this->assertIsArray($result->trades);
        
        foreach ($result->trades as $trade) {
            $this->assertIsArray($trade);
            $this->assertArrayHasKey('id', $trade);
            $this->assertArrayHasKey('symbol', $trade);
            $this->assertArrayHasKey('side', $trade);
            $this->assertArrayHasKey('entry_time', $trade);
            $this->assertArrayHasKey('entry_price', $trade);
            $this->assertArrayHasKey('quantity', $trade);
            $this->assertArrayHasKey('pnl', $trade);
            $this->assertArrayHasKey('pnl_percentage', $trade);
            
            $this->assertEquals('BTCUSDT', $trade['symbol']);
            $this->assertContains($trade['side'], ['BUY', 'SELL']);
            $this->assertIsFloat($trade['entry_price']);
            $this->assertIsFloat($trade['quantity']);
            $this->assertIsFloat($trade['pnl']);
            $this->assertIsFloat($trade['pnl_percentage']);
        }
    }

    public function testBacktestIsProfitable(): void
    {
        $request = new BacktestRequestDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::H1,
            startDate: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
            strategies: ['Test Strategy'],
            initialCapital: 10000.0,
            riskPerTrade: 0.02,
            commissionRate: 0.001,
            name: 'Profitability Test'
        );

        $result = $this->backtester->runBacktest($request);

        $this->assertIsBool($result->isProfitable());
        
        if ($result->totalReturn > 0) {
            $this->assertTrue($result->isProfitable());
        } else {
            $this->assertFalse($result->isProfitable());
        }
    }

    protected function tearDown(): void
    {
        // Nettoyer l'heure fixe après chaque test
        $this->clockService->clearFixedTime();
    }
}

