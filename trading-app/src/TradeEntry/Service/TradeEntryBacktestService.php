<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\TradeEntryBox;
use App\TradeEntry\Types\Side;
use App\TradeEntry\Execution\ExecutionResult;
use Psr\Log\LoggerInterface;
 
final class TradeEntryBacktestService
{
    public function __construct(
        private TradeEntryBox $tradeEntryBox,
        private TradeEntryMetricsService $metricsService,
        private TradeEntryAlertService $alertService,
        private LoggerInterface $logger
    ) {}

    /**
     * Exécute un backtest sur une période donnée avec des données historiques
     */
    public function runBacktest(
        array $historicalData,
        array $backtestConfig = []
    ): array {
        $config = array_merge([
            'start_date' => null,
            'end_date' => null,
            'initial_capital' => 10000.0,
            'risk_per_trade' => 2.0,
            'max_trades' => 100,
            'symbols' => ['BTCUSDT'],
            'timeframes' => ['1m', '5m', '15m']
        ], $backtestConfig);

        $this->logger->info('TradeEntry: Starting backtest', [
            'config' => $config,
            'data_points' => count($historicalData)
        ]);

        $results = [
            'config' => $config,
            'trades' => [],
            'performance' => [
                'total_trades' => 0,
                'successful_trades' => 0,
                'failed_trades' => 0,
                'total_pnl' => 0.0,
                'win_rate' => 0.0,
                'max_drawdown' => 0.0,
                'sharpe_ratio' => 0.0
            ],
            'metrics' => [],
            'alerts' => []
        ];

        $capital = $config['initial_capital'];
        $trades = [];
        $equity_curve = [$capital];

        foreach ($historicalData as $index => $dataPoint) {
            if (count($trades) >= $config['max_trades']) {
                break;
            }

            // Simulation d'un signal de trading
            $signal = $this->generateTradingSignal($dataPoint, $config);
            
            if ($signal) {
                $tradeResult = $this->executeBacktestTrade($signal, $capital, $dataPoint);
                
                if ($tradeResult) {
                    $trades[] = $tradeResult;
                    $capital += $tradeResult['pnl'];
                    $equity_curve[] = $capital;
                    
                    // Mise à jour des métriques
                    $this->updateBacktestMetrics($tradeResult);
                }
            }
        }

        // Calcul des performances finales
        $results['trades'] = $trades;
        $results['performance'] = $this->calculateBacktestPerformance($trades, $equity_curve, $config['initial_capital']);
        $results['metrics'] = $this->metricsService->getMetrics();

        $this->logger->info('TradeEntry: Backtest completed', [
            'total_trades' => count($trades),
            'final_capital' => $capital,
            'total_return' => (($capital - $config['initial_capital']) / $config['initial_capital']) * 100
        ]);

        return $results;
    }

    private function generateTradingSignal(array $dataPoint, array $config): ?array
    {
        // Logique simple de génération de signal basée sur les données historiques
        $price = (float)$dataPoint['close'];
        $volume = (float)$dataPoint['volume'];
        $rsi = $this->calculateRSIFromData($dataPoint);
        
        // Conditions pour générer un signal
        $shouldLong = $rsi < 30 && $volume > 1000; // RSI oversold + volume élevé
        $shouldShort = $rsi > 70 && $volume > 1000; // RSI overbought + volume élevé
        
        if ($shouldLong) {
            return [
                'side' => Side::LONG,
                'entry_price' => $price,
                'signal_strength' => $this->calculateSignalStrength($dataPoint, 'long'),
                'timestamp' => $dataPoint['timestamp'] ?? time()
            ];
        }
        
        if ($shouldShort) {
            return [
                'side' => Side::SHORT,
                'entry_price' => $price,
                'signal_strength' => $this->calculateSignalStrength($dataPoint, 'short'),
                'timestamp' => $dataPoint['timestamp'] ?? time()
            ];
        }
        
        return null;
    }

    private function executeBacktestTrade(array $signal, float $capital, array $dataPoint): ?array
    {
        try {
            // Calcul de l'ATR simple pour le stop loss
            $atr = $this->calculateATRFromData($dataPoint);
            $pivotPrice = $this->calculatePivotPrice($dataPoint);
            
            // Configuration du trade
            $riskAmount = $capital * ($signal['risk_per_trade'] ?? 0.02); // 2% par défaut
            $budget = $capital * 0.1; // 10% du capital par trade
            
            $input = [
                'symbol' => $dataPoint['symbol'] ?? 'BTCUSDT',
                'side' => $signal['side'],
                'entry_price_base' => $signal['entry_price'],
                'atr_value' => $atr,
                'pivot_price' => $pivotPrice,
                'risk_pct' => ($riskAmount / $capital) * 100,
                'budget_usdt' => $budget,
                'equity_usdt' => $capital,
                'rsi' => $this->calculateRSIFromData($dataPoint),
                'volume_ratio' => 1.5, // Volume modéré
                'pullback_confirmed' => true,
            ];

            $startTime = microtime(true);
            $result = $this->tradeEntryBox->handle($input);
            $executionTime = microtime(true) - $startTime;

            // Simulation du résultat du trade
            $pnl = $this->simulateTradePnL($signal, $dataPoint, $result);
            
            $tradeResult = [
                'timestamp' => $signal['timestamp'],
                'symbol' => $input['symbol'],
                'side' => $signal['side']->value,
                'entry_price' => $signal['entry_price'],
                'quantity' => $result->data['quantity'] ?? 0,
                'pnl' => $pnl,
                'status' => $result->status,
                'execution_time' => $executionTime,
                'signal_strength' => $signal['signal_strength']
            ];

            // Mise à jour des métriques et alertes
            $this->metricsService->recordExecution(
                $input['symbol'],
                $signal['side']->value,
                $executionTime,
                $result,
                $input
            );

            $this->alertService->checkAlerts(
                $input['symbol'],
                $signal['side']->value,
                $executionTime,
                $result,
                $this->metricsService->getMetrics()
            );

            return $tradeResult;

        } catch (\Exception $e) {
            $this->logger->error('TradeEntry: Backtest trade execution failed', [
                'error' => $e->getMessage(),
                'signal' => $signal
            ]);
            return null;
        }
    }

    private function simulateTradePnL(array $signal, array $dataPoint, ExecutionResult $result): float
    {
        if ($result->status !== 'order_opened') {
            return 0.0;
        }

        // Simulation simple du PnL basée sur le mouvement de prix
        $entryPrice = $signal['entry_price'];
        $exitPrice = (float)$dataPoint['close']; // Prix de sortie simulé
        
        if ($signal['side'] === Side::LONG) {
            return ($exitPrice - $entryPrice) / $entryPrice * 100; // PnL en %
        } else {
            return ($entryPrice - $exitPrice) / $entryPrice * 100; // PnL en %
        }
    }

    private function calculateBacktestPerformance(array $trades, array $equityCurve, float $initialCapital): array
    {
        $totalTrades = count($trades);
        $successfulTrades = array_filter($trades, fn($trade) => $trade['pnl'] > 0);
        $failedTrades = array_filter($trades, fn($trade) => $trade['pnl'] <= 0);
        
        $totalPnL = array_sum(array_column($trades, 'pnl'));
        $winRate = $totalTrades > 0 ? (count($successfulTrades) / $totalTrades) * 100 : 0;
        
        // Calcul du max drawdown
        $maxDrawdown = 0;
        $peak = $initialCapital;
        foreach ($equityCurve as $equity) {
            if ($equity > $peak) {
                $peak = $equity;
            }
            $drawdown = (($peak - $equity) / $peak) * 100;
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }
        
        return [
            'total_trades' => $totalTrades,
            'successful_trades' => count($successfulTrades),
            'failed_trades' => count($failedTrades),
            'total_pnl' => round($totalPnL, 2),
            'win_rate' => round($winRate, 2),
            'max_drawdown' => round($maxDrawdown, 2),
            'final_capital' => end($equityCurve),
            'total_return' => round((end($equityCurve) - $initialCapital) / $initialCapital * 100, 2)
        ];
    }

    private function calculateRSIFromData(array $dataPoint): float
    {
        // Simulation simple du RSI
        return rand(20, 80); // Pour l'exemple
    }

    private function calculateATRFromData(array $dataPoint): float
    {
        // Simulation simple de l'ATR
        return (float)$dataPoint['close'] * 0.02; // 2% du prix
    }

    private function calculatePivotPrice(array $dataPoint): float
    {
        return (float)$dataPoint['close']; // Prix de clôture comme pivot
    }

    private function calculateSignalStrength(array $dataPoint, string $side): float
    {
        // Force du signal basée sur les données
        return rand(50, 100) / 100; // Entre 0.5 et 1.0
    }

    private function updateBacktestMetrics(array $tradeResult): void
    {
        // Mise à jour des métriques spécifiques au backtest
        // Cette méthode peut être étendue selon les besoins
    }
}




