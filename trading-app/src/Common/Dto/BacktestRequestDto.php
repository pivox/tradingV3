<?php

declare(strict_types=1);

namespace App\Common\Dto;

use App\Common\Enum\Timeframe;

final readonly class BacktestRequestDto
{
    public function __construct(
        public string $symbol,
        public Timeframe $timeframe,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public array $strategies = [],
        public array $parameters = [],
        public float $initialCapital = 10000.0,
        public float $riskPerTrade = 0.02, // 2% par trade
        public bool $includeCommissions = true,
        public float $commissionRate = 0.001, // 0.1%
        public ?string $name = null,
        public ?string $description = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe->value,
            'start_date' => $this->startDate->format('Y-m-d H:i:s'),
            'end_date' => $this->endDate->format('Y-m-d H:i:s'),
            'strategies' => $this->strategies,
            'parameters' => $this->parameters,
            'initial_capital' => $this->initialCapital,
            'risk_per_trade' => $this->riskPerTrade,
            'include_commissions' => $this->includeCommissions,
            'commission_rate' => $this->commissionRate,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            timeframe: Timeframe::from($data['timeframe']),
            startDate: new \DateTimeImmutable($data['start_date'], new \DateTimeZone('UTC')),
            endDate: new \DateTimeImmutable($data['end_date'], new \DateTimeZone('UTC')),
            strategies: $data['strategies'] ?? [],
            parameters: $data['parameters'] ?? [],
            initialCapital: $data['initial_capital'] ?? 10000.0,
            riskPerTrade: $data['risk_per_trade'] ?? 0.02,
            includeCommissions: $data['include_commissions'] ?? true,
            commissionRate: $data['commission_rate'] ?? 0.001,
            name: $data['name'] ?? null,
            description: $data['description'] ?? null
        );
    }

    public function getDurationInDays(): int
    {
        return $this->startDate->diff($this->endDate)->days;
    }

    public function hasStrategy(string $strategyName): bool
    {
        return in_array($strategyName, $this->strategies);
    }

    public function getStrategyParameters(string $strategyName): array
    {
        return $this->parameters[$strategyName] ?? [];
    }
}


