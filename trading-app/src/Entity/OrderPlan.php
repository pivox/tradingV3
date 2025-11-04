<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderPlanRepository::class)]
#[ORM\Table(name: 'order_plan')]
#[ORM\Index(name: 'idx_order_plan_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_order_plan_status', columns: ['status'])]
#[ORM\Index(name: 'idx_order_plan_plan_time', columns: ['plan_time'])]
class OrderPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $planTime;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: \App\Common\Enum\SignalSide::class)]
    private \App\Common\Enum\SignalSide $side;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $riskJson = [];

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $contextJson = [];

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $execJson = [];

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'PLANNED'])]
    private string $status = 'PLANNED';

    public function __construct()
    {
        $this->planTime = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getPlanTime(): \DateTimeImmutable
    {
        return $this->planTime;
    }

    public function setPlanTime(\DateTimeImmutable $planTime): static
    {
        $this->planTime = $planTime;
        return $this;
    }

    public function getSide(): \App\Common\Enum\SignalSide
    {
        return $this->side;
    }

    public function setSide(\App\Common\Enum\SignalSide $side): static
    {
        $this->side = $side;
        return $this;
    }

    public function getRiskJson(): array
    {
        return $this->riskJson;
    }

    public function setRiskJson(array $riskJson): static
    {
        $this->riskJson = $riskJson;
        return $this;
    }

    public function getContextJson(): array
    {
        return $this->contextJson;
    }

    public function setContextJson(array $contextJson): static
    {
        $this->contextJson = $contextJson;
        return $this;
    }

    public function getExecJson(): array
    {
        return $this->execJson;
    }

    public function setExecJson(array $execJson): static
    {
        $this->execJson = $execJson;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isPlanned(): bool
    {
        return $this->status === 'PLANNED';
    }

    public function isExecuted(): bool
    {
        return $this->status === 'EXECUTED';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'CANCELLED';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }

    public function getRiskValue(string $key): mixed
    {
        return $this->riskJson[$key] ?? null;
    }

    public function setRiskValue(string $key, mixed $value): static
    {
        $this->riskJson[$key] = $value;
        return $this;
    }

    public function getContextValue(string $key): mixed
    {
        return $this->contextJson[$key] ?? null;
    }

    public function setContextValue(string $key, mixed $value): static
    {
        $this->contextJson[$key] = $value;
        return $this;
    }

    public function getExecValue(string $key): mixed
    {
        return $this->execJson[$key] ?? null;
    }

    public function setExecValue(string $key, mixed $value): static
    {
        $this->execJson[$key] = $value;
        return $this;
    }
}




