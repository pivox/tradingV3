<?php

declare(strict_types=1);

namespace App\Common\Dto;

use App\Domain\Strategy\Model\StrategyPerformance;

final readonly class BacktestResultDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $symbol,
        public string $timeframe,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public array $strategies,
        public float $initialCapital,
        public float $finalCapital,
        public float $totalReturn,
        public float $totalReturnPercentage,
        public int $totalTrades,
        public int $winningTrades,
        public int $losingTrades,
        public float $winRate,
        public float $profitFactor,
        public float $sharpeRatio,
        public float $maxDrawdown,
        public float $maxDrawdownPercentage,
        public float $averageWin,
        public float $averageLoss,
        public float $largestWin,
        public float $largestLoss,
        public array $monthlyReturns,
        public array $equityCurve,
        public array $trades,
        public \DateTimeImmutable $createdAt,
        public ?string $description = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'start_date' => $this->startDate->format('Y-m-d H:i:s'),
            'end_date' => $this->endDate->format('Y-m-d H:i:s'),
            'strategies' => $this->strategies,
            'initial_capital' => $this->initialCapital,
            'final_capital' => $this->finalCapital,
            'total_return' => $this->totalReturn,
            'total_return_percentage' => $this->totalReturnPercentage,
            'total_trades' => $this->totalTrades,
            'winning_trades' => $this->winningTrades,
            'losing_trades' => $this->losingTrades,
            'win_rate' => $this->winRate,
            'profit_factor' => $this->profitFactor,
            'sharpe_ratio' => $this->sharpeRatio,
            'max_drawdown' => $this->maxDrawdown,
            'max_drawdown_percentage' => $this->maxDrawdownPercentage,
            'average_win' => $this->averageWin,
            'average_loss' => $this->averageLoss,
            'largest_win' => $this->largestWin,
            'largest_loss' => $this->largestLoss,
            'monthly_returns' => $this->monthlyReturns,
            'equity_curve' => $this->equityCurve,
            'trades' => $this->trades,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'description' => $this->description,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            symbol: $data['symbol'],
            timeframe: $data['timeframe'],
            startDate: new \DateTimeImmutable($data['start_date'], new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable($data['end_date'], new \DateTimeZone('UTC')),
            strategies: $data['strategies'],
            initialCapital: $data['initial_capital'],
            finalCapital: $data['final_capital'],
            totalReturn: $data['total_return'],
            totalReturnPercentage: $data['total_return_percentage'],
            totalTrades: $data['total_trades'],
            winningTrades: $data['winning_trades'],
            losingTrades: $data['losing_trades'],
            winRate: $data['win_rate'],
            profitFactor: $data['profit_factor'],
            sharpeRatio: $data['sharpe_ratio'],
            maxDrawdown: $data['max_drawdown'],
            maxDrawdownPercentage: $data['max_drawdown_percentage'],
            averageWin: $data['average_win'],
            averageLoss: $data['average_loss'],
            largestWin: $data['largest_win'],
            largestLoss: $data['largest_loss'],
            monthlyReturns: $data['monthly_returns'],
            equityCurve: $data['equity_curve'],
            trades: $data['trades'],
            createdAt: new \DateTimeImmutable($data['created_at'], new \DateTimeZone('UTC')),
            description: $data['description'] ?? null
        );
    }

    public function isProfitable(): bool
    {
        return $this->totalReturn > 0;
    }

    public function getRiskRewardRatio(): float
    {
        if ($this->averageLoss == 0) {
            return 0.0;
        }
        return $this->averageWin / abs($this->averageLoss);
    }

    public function getAnnualizedReturn(): float
    {
        $days = $this->startDate->diff($this->endDate)->days;
        if ($days == 0) {
            return 0.0;
        }

        $years = $days / 365.25;
        return pow(1 + $this->totalReturnPercentage / 100, 1 / $years) - 1;
    }
}


