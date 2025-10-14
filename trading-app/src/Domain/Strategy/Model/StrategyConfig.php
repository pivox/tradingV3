<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Model;

final readonly class StrategyConfig
{
    public function __construct(
        public string $strategyName,
        public array $parameters = [],
        public ?StrategyPerformance $performance = null,
        public bool $enabled = true,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null
    ) {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        if ($this->updatedAt === null) {
            $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    public function toArray(): array
    {
        return [
            'strategy_name' => $this->strategyName,
            'parameters' => $this->parameters,
            'performance' => $this->performance?->toArray(),
            'enabled' => $this->enabled,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            strategyName: $data['strategy_name'],
            parameters: $data['parameters'] ?? [],
            performance: isset($data['performance']) ? StrategyPerformance::fromArray($data['performance']) : null,
            enabled: $data['enabled'] ?? true,
            createdAt: isset($data['created_at']) ? new \DateTimeImmutable($data['created_at'], new \DateTimeZone('UTC')) : null,
            updatedAt: isset($data['updated_at']) ? new \DateTimeImmutable($data['updated_at'], new \DateTimeZone('UTC')) : null
        );
    }

    public function withParameters(array $parameters): self
    {
        return new self(
            strategyName: $this->strategyName,
            parameters: $parameters,
            performance: $this->performance,
            enabled: $this->enabled,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    public function withPerformance(StrategyPerformance $performance): self
    {
        return new self(
            strategyName: $this->strategyName,
            parameters: $this->parameters,
            performance: $performance,
            enabled: $this->enabled,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(
            strategyName: $this->strategyName,
            parameters: $this->parameters,
            performance: $this->performance,
            enabled: $enabled,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }
}


