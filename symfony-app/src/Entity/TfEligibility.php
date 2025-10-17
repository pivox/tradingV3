<?php

declare(strict_types=1);

namespace App\Entity\MTF;

// @tag:mtf-core  Sélection par timeframe: ACTIVE / COOLDOWN / LOCKED_ORDER / LOCKED_POSITION / SUSPENDED

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tf_eligibility')]
#[ORM\UniqueConstraint(name: 'pk_symbol_tf', columns: ['symbol', 'tf'])]
#[ORM\Index(name: 'idx_tf_status', columns: ['tf', 'status', 'priority', 'updated_at'])]
class TfEligibility
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $symbol;

    #[ORM\Column(type: 'string', length: 8)]
    private string $tf;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = 'ACTIVE'; // ACTIVE | COOLDOWN | LOCKED_ORDER | LOCKED_POSITION | SUSPENDED

    #[ORM\Column(type: 'integer')]
    private int $priority = 0;

    #[ORM\Column(name: 'cooldown_until', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cooldownUntil = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int { return $this->id; }
    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }
    public function getTf(): string { return $this->tf; }
    public function setTf(string $tf): self { $this->tf = $tf; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): self { $this->priority = $priority; return $this; }
    public function getCooldownUntil(): ?\DateTimeImmutable { return $this->cooldownUntil; }
    public function setCooldownUntil(?\DateTimeImmutable $v): self { $this->cooldownUntil = $v; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $r): self { $this->reason = $r; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }
}
