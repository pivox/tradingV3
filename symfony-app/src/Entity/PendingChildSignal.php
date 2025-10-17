<?php

declare(strict_types=1);

namespace App\Entity\MTF;

// @tag:mtf-support  Tampon des signaux enfants lorsque le parent n'est pas frais

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pending_child_signals')]
#[ORM\UniqueConstraint(name: 'pk_symbol_tf_slot', columns: ['symbol', 'tf', 'slot_start_utc'])]
class PendingChildSignal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $symbol;

    #[ORM\Column(type: 'string', length: 8)]
    private string $tf;

    #[ORM\Column(name: 'slot_start_utc', type: 'datetime_immutable')]
    private \DateTimeImmutable $slotStartUtc;

    #[ORM\Column(name: 'payload_json', type: 'json')]
    private array $payloadJson = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }
    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }
    public function getTf(): string { return $this->tf; }
    public function setTf(string $tf): self { $this->tf = $tf; return $this; }
    public function getSlotStartUtc(): \DateTimeImmutable { return $this->slotStartUtc; }
    public function setSlotStartUtc(\DateTimeImmutable $v): self { $this->slotStartUtc = $v; return $this; }
    public function getPayloadJson(): array { return $this->payloadJson; }
    public function setPayloadJson(array $v): self { $this->payloadJson = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $v): self { $this->createdAt = $v; return $this; }
}
