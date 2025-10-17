<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Model;

final readonly class StrategyPerformance
{
    public function __construct(
        public array $metrics = []
    ) {
    }

    public function getWinRate(): ?float
    {
        return $this->metrics['win_rate'] ?? null;
    }

    public function getProfitFactor(): ?float
    {
        return $this->metrics['profit_factor'] ?? null;
    }

    public function getSharpeRatio(): ?float
    {
        return $this->metrics['sharpe_ratio'] ?? null;
    }

    public function getMaxDrawdown(): ?float
    {
        return $this->metrics['max_drawdown'] ?? null;
    }

    public function getTotalReturn(): ?float
    {
        return $this->metrics['total_return'] ?? null;
    }

    public function getNumberOfTrades(): ?int
    {
        return $this->metrics['number_of_trades'] ?? null;
    }

    public function getAverageWin(): ?float
    {
        return $this->metrics['average_win'] ?? null;
    }

    public function getAverageLoss(): ?float
    {
        return $this->metrics['average_loss'] ?? null;
    }

    public function toArray(): array
    {
        return $this->metrics;
    }

    public static function fromArray(array $data): self
    {
        return new self(metrics: $data);
    }

    public function withMetric(string $key, mixed $value): self
    {
        $metrics = $this->metrics;
        $metrics[$key] = $value;
        return new self(metrics: $metrics);
    }

    public function getMetric(string $key): mixed
    {
        return $this->metrics[$key] ?? null;
    }

    public function hasMetric(string $key): bool
    {
        return array_key_exists($key, $this->metrics);
    }
}


