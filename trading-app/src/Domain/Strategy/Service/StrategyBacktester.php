<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Service;

use App\Domain\Common\Dto\BacktestRequestDto;
use App\Domain\Common\Dto\BacktestResultDto;
use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Dto\TradeDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Signal\Strategy\StrategyInterface;
use App\Domain\Strategy\Model\StrategyConfig;
use App\Domain\Strategy\Model\StrategyPerformance;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;

class StrategyBacktester
{
    /**
     * @param StrategyInterface[] $strategies
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private array $strategies = []
    ) {
    }

    /**
     * Exécute un backtest complet
     */
    public function runBacktest(BacktestRequestDto $request): BacktestResultDto
    {
        $backtestId = uniqid('backtest_', true);
        $trades = [];
        $equityCurve = [];
        $monthlyReturns = [];
        
        $currentCapital = $request->initialCapital;
        $peakCapital = $currentCapital;
        $maxDrawdown = 0.0;
        
        // Récupérer les données historiques
        $klines = $this->getHistoricalData($request);
        $indicators = $this->calculateIndicators($klines, $request->strategies);
        
        // Simuler les trades
        $openTrades = [];
        
        foreach ($klines as $kline) {
            $indicatorSnapshot = $indicators[$kline->openTime->format('Y-m-d H:i:s')] ?? null;
            if ($indicatorSnapshot === null) {
                continue;
            }
            
            // Générer les signaux
            $signals = $this->generateSignals($request, $kline, $indicatorSnapshot);
            
            // Gérer les trades ouverts
            $this->manageOpenTrades($openTrades, $kline, $trades, $currentCapital, $request);
            
            // Ouvrir de nouveaux trades basés sur les signaux
            foreach ($signals as $signal) {
                if ($this->shouldOpenTrade($signal, $openTrades, $currentCapital, $request)) {
                    $trade = $this->openTrade($signal, $kline, $currentCapital, $request);
                    $openTrades[] = $trade;
                }
            }
            
            // Mettre à jour la courbe d'équité
            $equityCurve[] = [
                'date' => $kline->openTime->format('Y-m-d H:i:s'),
                'equity' => $currentCapital
            ];
            
            // Calculer le drawdown
            if ($currentCapital > $peakCapital) {
                $peakCapital = $currentCapital;
            }
            $drawdown = ($peakCapital - $currentCapital) / $peakCapital;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }
        
        // Fermer tous les trades ouverts
        foreach ($openTrades as $trade) {
            $this->closeTrade($trade, end($klines), $trades, $currentCapital, $request, 'END_OF_DATA');
        }
        
        // Calculer les métriques finales
        $metrics = $this->calculateMetrics($trades, $request->initialCapital, $currentCapital);
        $monthlyReturns = $this->calculateMonthlyReturns($equityCurve);
        
        return new BacktestResultDto(
            id: $backtestId,
            name: $request->name ?? "Backtest {$request->symbol}",
            symbol: $request->symbol,
            timeframe: $request->timeframe->value,
            startDate: $request->startDate,
            endDate: $request->endDate,
            strategies: $request->strategies,
            initialCapital: $request->initialCapital,
            finalCapital: $currentCapital,
            totalReturn: $currentCapital - $request->initialCapital,
            totalReturnPercentage: (($currentCapital - $request->initialCapital) / $request->initialCapital) * 100,
            totalTrades: count($trades),
            winningTrades: $metrics['winning_trades'],
            losingTrades: $metrics['losing_trades'],
            winRate: $metrics['win_rate'],
            profitFactor: $metrics['profit_factor'],
            sharpeRatio: $metrics['sharpe_ratio'],
            maxDrawdown: $maxDrawdown * $request->initialCapital,
            maxDrawdownPercentage: $maxDrawdown * 100,
            averageWin: $metrics['average_win'],
            averageLoss: $metrics['average_loss'],
            largestWin: $metrics['largest_win'],
            largestLoss: $metrics['largest_loss'],
            monthlyReturns: $monthlyReturns,
            equityCurve: $equityCurve,
            trades: array_map(fn(TradeDto $trade) => $trade->toArray(), $trades),
            createdAt: $this->clock->now(),
            description: $request->description
        );
    }

    /**
     * Récupère les données historiques (à implémenter selon votre source de données)
     */
    private function getHistoricalData(BacktestRequestDto $request): array
    {
        // TODO: Implémenter la récupération des données historiques
        // Cette méthode devrait récupérer les klines depuis la base de données
        // ou une API externe pour la période spécifiée
        return [];
    }

    /**
     * Calcule les indicateurs techniques
     */
    private function calculateIndicators(array $klines, array $strategies): array
    {
        // TODO: Implémenter le calcul des indicateurs techniques
        // RSI, MACD, Bollinger Bands, Moving Averages, etc.
        return [];
    }

    /**
     * Génère les signaux pour une kline donnée
     */
    private function generateSignals(BacktestRequestDto $request, KlineDto $kline, $indicators): array
    {
        $signals = [];
        
        foreach ($this->strategies as $strategy) {
            if (in_array($strategy->getName(), $request->strategies) && $strategy->isEnabled()) {
                $signal = $strategy->generateSignal(
                    $request->symbol,
                    $request->timeframe,
                    $kline,
                    $indicators
                );
                
                if ($signal !== null) {
                    $signals[] = $signal;
                }
            }
        }
        
        return $signals;
    }

    /**
     * Gère les trades ouverts
     */
    private function manageOpenTrades(array &$openTrades, KlineDto $kline, array &$trades, float &$currentCapital, BacktestRequestDto $request): void
    {
        foreach ($openTrades as $index => $trade) {
            $shouldClose = false;
            $exitReason = '';
            
            // Vérifier le stop loss
            if ($trade->side === SignalSide::LONG && $kline->lowPrice->toFloat() <= $trade->stopLoss) {
                $shouldClose = true;
                $exitReason = 'STOP_LOSS';
            } elseif ($trade->side === SignalSide::SHORT && $kline->highPrice->toFloat() >= $trade->stopLoss) {
                $shouldClose = true;
                $exitReason = 'STOP_LOSS';
            }
            
            // Vérifier le take profit
            if (!$shouldClose) {
                if ($trade->side === SignalSide::LONG && $kline->highPrice->toFloat() >= $trade->takeProfit) {
                    $shouldClose = true;
                    $exitReason = 'TAKE_PROFIT';
                } elseif ($trade->side === SignalSide::SHORT && $kline->lowPrice->toFloat() <= $trade->takeProfit) {
                    $shouldClose = true;
                    $exitReason = 'TAKE_PROFIT';
                }
            }
            
            if ($shouldClose) {
                $this->closeTrade($trade, $kline, $trades, $currentCapital, $request, $exitReason);
                unset($openTrades[$index]);
            }
        }
        
        // Réindexer le tableau
        $openTrades = array_values($openTrades);
    }

    /**
     * Détermine si un nouveau trade doit être ouvert
     */
    private function shouldOpenTrade(SignalDto $signal, array $openTrades, float $currentCapital, BacktestRequestDto $request): bool
    {
        // Vérifier si on a déjà un trade ouvert pour ce symbole
        foreach ($openTrades as $trade) {
            if ($trade->symbol === $signal->symbol) {
                return false;
            }
        }
        
        // Vérifier si on a assez de capital
        $riskAmount = $currentCapital * $request->riskPerTrade;
        if ($riskAmount < 100) { // Minimum 100$ par trade
            return false;
        }
        
        return true;
    }

    /**
     * Ouvre un nouveau trade
     */
    private function openTrade(SignalDto $signal, KlineDto $kline, float $currentCapital, BacktestRequestDto $request): TradeDto
    {
        $riskAmount = $currentCapital * $request->riskPerTrade;
        $entryPrice = $kline->openPrice->toFloat();
        
        // Calculer le stop loss et take profit
        $stopLoss = $this->calculateStopLoss($signal, $entryPrice);
        $takeProfit = $this->calculateTakeProfit($signal, $entryPrice);
        
        // Calculer la quantité basée sur le risque
        $riskPerUnit = abs($entryPrice - $stopLoss);
        $quantity = $riskAmount / $riskPerUnit;
        
        return new TradeDto(
            id: uniqid('trade_', true),
            symbol: $signal->symbol,
            side: $signal->side,
            entryTime: $kline->openTime,
            entryPrice: $entryPrice,
            quantity: $quantity,
            stopLoss: $stopLoss,
            takeProfit: $takeProfit,
            meta: $signal->meta
        );
    }

    /**
     * Ferme un trade
     */
    private function closeTrade(TradeDto $trade, KlineDto $kline, array &$trades, float &$currentCapital, BacktestRequestDto $request, string $exitReason): void
    {
        $exitPrice = $kline->openPrice->toFloat();
        $exitTime = $kline->openTime;
        
        // Calculer le PnL
        $pnl = $this->calculatePnL($trade, $exitPrice);
        
        // Calculer la commission
        $commission = $request->includeCommissions ? 
            ($trade->entryPrice * $trade->quantity + $exitPrice * $trade->quantity) * $request->commissionRate : 0;
        
        $netPnL = $pnl - $commission;
        $pnlPercentage = ($netPnL / ($trade->entryPrice * $trade->quantity)) * 100;
        
        // Mettre à jour le capital
        $currentCapital += $netPnL;
        
        // Créer le trade fermé
        $closedTrade = new TradeDto(
            id: $trade->id,
            symbol: $trade->symbol,
            side: $trade->side,
            entryTime: $trade->entryTime,
            entryPrice: $trade->entryPrice,
            quantity: $trade->quantity,
            stopLoss: $trade->stopLoss,
            takeProfit: $trade->takeProfit,
            exitTime: $exitTime,
            exitPrice: $exitPrice,
            pnl: $netPnL,
            pnlPercentage: $pnlPercentage,
            commission: $commission,
            exitReason: $exitReason,
            meta: $trade->meta
        );
        
        $trades[] = $closedTrade;
    }

    /**
     * Calcule le PnL d'un trade
     */
    private function calculatePnL(TradeDto $trade, float $exitPrice): float
    {
        if ($trade->side === SignalSide::LONG) {
            return ($exitPrice - $trade->entryPrice) * $trade->quantity;
        } else {
            return ($trade->entryPrice - $exitPrice) * $trade->quantity;
        }
    }

    /**
     * Calcule le stop loss
     */
    private function calculateStopLoss(SignalDto $signal, float $entryPrice): float
    {
        // Logique simple : 2% de stop loss
        $stopLossPercentage = 0.02;
        
        if ($signal->side === SignalSide::LONG) {
            return $entryPrice * (1 - $stopLossPercentage);
        } else {
            return $entryPrice * (1 + $stopLossPercentage);
        }
    }

    /**
     * Calcule le take profit
     */
    private function calculateTakeProfit(SignalDto $signal, float $entryPrice): float
    {
        // Logique simple : 4% de take profit (ratio 1:2)
        $takeProfitPercentage = 0.04;
        
        if ($signal->side === SignalSide::LONG) {
            return $entryPrice * (1 + $takeProfitPercentage);
        } else {
            return $entryPrice * (1 - $takeProfitPercentage);
        }
    }

    /**
     * Calcule les métriques de performance
     */
    private function calculateMetrics(array $trades, float $initialCapital, float $finalCapital): array
    {
        if (empty($trades)) {
            return [
                'winning_trades' => 0,
                'losing_trades' => 0,
                'win_rate' => 0,
                'profit_factor' => 0,
                'sharpe_ratio' => 0,
                'average_win' => 0,
                'average_loss' => 0,
                'largest_win' => 0,
                'largest_loss' => 0,
            ];
        }
        
        $winningTrades = 0;
        $losingTrades = 0;
        $totalWins = 0.0;
        $totalLosses = 0.0;
        $largestWin = 0.0;
        $largestLoss = 0.0;
        
        foreach ($trades as $trade) {
            if ($trade->pnl > 0) {
                $winningTrades++;
                $totalWins += $trade->pnl;
                if ($trade->pnl > $largestWin) {
                    $largestWin = $trade->pnl;
                }
            } else {
                $losingTrades++;
                $totalLosses += abs($trade->pnl);
                if ($trade->pnl < $largestLoss) {
                    $largestLoss = $trade->pnl;
                }
            }
        }
        
        $winRate = count($trades) > 0 ? ($winningTrades / count($trades)) * 100 : 0;
        $profitFactor = $totalLosses > 0 ? $totalWins / $totalLosses : 0;
        $averageWin = $winningTrades > 0 ? $totalWins / $winningTrades : 0;
        $averageLoss = $losingTrades > 0 ? $totalLosses / $losingTrades : 0;
        
        // Calcul simplifié du Sharpe ratio
        $returns = array_map(fn($trade) => $trade->pnlPercentage, $trades);
        $avgReturn = array_sum($returns) / count($returns);
        $stdDev = $this->calculateStandardDeviation($returns);
        $sharpeRatio = $stdDev > 0 ? $avgReturn / $stdDev : 0;
        
        return [
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'profit_factor' => $profitFactor,
            'sharpe_ratio' => $sharpeRatio,
            'average_win' => $averageWin,
            'average_loss' => $averageLoss,
            'largest_win' => $largestWin,
            'largest_loss' => $largestLoss,
        ];
    }

    /**
     * Calcule l'écart-type
     */
    private function calculateStandardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);
        return sqrt($variance);
    }

    /**
     * Calcule les retours mensuels
     */
    private function calculateMonthlyReturns(array $equityCurve): array
    {
        $monthlyReturns = [];
        $currentMonth = null;
        $monthStartEquity = null;
        
        foreach ($equityCurve as $point) {
            $date = new \DateTimeImmutable($point['date'], new \DateTimeZone('UTC'));
            $month = $date->format('Y-m');
            
            if ($currentMonth !== $month) {
                if ($currentMonth !== null && $monthStartEquity !== null) {
                    $monthEndEquity = $point['equity'];
                    $monthlyReturn = (($monthEndEquity - $monthStartEquity) / $monthStartEquity) * 100;
                    $monthlyReturns[$currentMonth] = $monthlyReturn;
                }
                
                $currentMonth = $month;
                $monthStartEquity = $point['equity'];
            }
        }
        
        return $monthlyReturns;
    }

    /**
     * Ajoute une stratégie au backtester
     */
    public function addStrategy(StrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    /**
     * Retourne les stratégies disponibles
     */
    public function getAvailableStrategies(): array
    {
        return $this->strategies;
    }
}


