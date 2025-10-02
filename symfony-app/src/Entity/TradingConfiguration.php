<?php

namespace App\Entity;

use App\Repository\TradingConfigurationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradingConfigurationRepository::class)]
#[ORM\Table(name: 'trading_configuration')]
#[ORM\UniqueConstraint(name: 'uniq_trading_configuration_context_scope', columns: ['context', 'scope'])]
#[ORM\HasLifecycleCallbacks]
class TradingConfiguration
{
    public const CONTEXT_GLOBAL = 'global';
    public const CONTEXT_STRATEGY = 'strategy';
    public const CONTEXT_EXECUTION = 'execution';
    public const CONTEXT_SECURITY = 'security';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 40)]
    private string $context = self::CONTEXT_STRATEGY;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $budgetCapUsdt = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $riskAbsUsdt = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $tpAbsUsdt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bannedContracts = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function setContext(string $context): self
    {
        $this->context = strtolower($context);
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): self
    {
        $this->scope = $scope !== null ? strtolower($scope) : null;
        return $this;
    }

    public function getBudgetCapUsdt(): ?float
    {
        return $this->budgetCapUsdt !== null ? (float)$this->budgetCapUsdt : null;
    }

    public function setBudgetCapUsdt(?float $value): self
    {
        $this->budgetCapUsdt = $value !== null ? number_format($value, 2, '.', '') : null;
        return $this;
    }

    public function getRiskAbsUsdt(): ?float
    {
        return $this->riskAbsUsdt !== null ? (float)$this->riskAbsUsdt : null;
    }

    public function setRiskAbsUsdt(?float $value): self
    {
        $this->riskAbsUsdt = $value !== null ? number_format($value, 2, '.', '') : null;
        return $this;
    }

    public function getTpAbsUsdt(): ?float
    {
        return $this->tpAbsUsdt !== null ? (float)$this->tpAbsUsdt : null;
    }

    public function setTpAbsUsdt(?float $value): self
    {
        $this->tpAbsUsdt = $value !== null ? number_format($value, 2, '.', '') : null;
        return $this;
    }

    public function getBannedContractsRaw(): ?string
    {
        return $this->bannedContracts;
    }

    public function getBannedContracts(): array
    {
        if ($this->bannedContracts === null || trim($this->bannedContracts) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $this->bannedContracts));
        return array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
    }

    public function setBannedContracts(?string $contracts): self
    {
        $this->bannedContracts = $contracts;
        return $this;
    }

    public function setBannedContractsFromArray(array $contracts): self
    {
        $contracts = array_values(array_filter(array_map('trim', $contracts), static fn(string $value): bool => $value !== ''));
        $this->bannedContracts = $contracts !== [] ? implode(',', $contracts) : null;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
