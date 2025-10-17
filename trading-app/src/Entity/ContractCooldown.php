<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContractCooldownRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContractCooldownRepository::class)]
#[ORM\Table(name: 'contract_cooldown')]
#[ORM\Index(name: 'idx_contract_cooldown_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_contract_cooldown_active_until', columns: ['active_until'])]
class ContractCooldown
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $activeUntil;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $symbol, \DateTimeImmutable $activeUntil, string $reason)
    {
        $this->symbol = strtoupper($symbol);
        $this->activeUntil = $activeUntil;
        $this->reason = $reason;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getActiveUntil(): \DateTimeImmutable
    {
        return $this->activeUntil;
    }

    public function extendUntil(\DateTimeImmutable $until): self
    {
        $this->activeUntil = $until;
        $this->touch();

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function updateReason(string $reason): self
    {
        $this->reason = $reason;
        $this->touch();

        return $this;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->activeUntil > $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

