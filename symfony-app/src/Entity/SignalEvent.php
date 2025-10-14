<?php

declare(strict_types=1);

namespace App\Entity\MTF;

// @tag:mtf-core  Journal des faits immuables par (symbol, tf, slot)

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'signal_events')]
#[ORM\UniqueConstraint(name: 'uniq_signal', columns: ['symbol', 'tf', 'slot_start_utc'])]
#[ORM\Index(name: 'idx_read', columns: ['symbol', 'tf', 'at_utc'])]
class SignalEvent
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

    #[ORM\Column(type: 'boolean')]
    private bool $passed;

    #[ORM\Column(type: 'string', length: 8)]
    private string $side; // LONG | SHORT | NONE

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $score = null;

    #[ORM\Column(name: 'at_utc', type: 'datetime_immutable')]
    private \DateTimeImmutable $atUtc;

    #[ORM\Column(name: 'meta_json', type: 'json', nullable: true)]
    private ?array $metaJson = null;

    public function getId(): ?int { return $this->id; }

    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }

    public function getTf(): string { return $this->tf; }
    public function setTf(string $tf): self { $this->tf = $tf; return $this; }

    public function getSlotStartUtc(): \DateTimeImmutable { return $this->slotStartUtc; }
    public function setSlotStartUtc(\DateTimeImmutable $v): self { $this->slotStartUtc = $v; return $this; }

    public function isPassed(): bool { return $this->passed; }
    public function setPassed(bool $passed): self { $this->passed = $passed; return $this; }

    public function getSide(): string { return $this->side; }
    public function setSide(string $side): self { $this->side = $side; return $this; }

    public function getScore(): ?float { return $this->score; }
    public function setScore(?float $score): self { $this->score = $score; return $this; }

    public function getAtUtc(): \DateTimeImmutable { return $this->atUtc; }
    public function setAtUtc(\DateTimeImmutable $v): self { $this->atUtc = $v; return $this; }

    public function getMetaJson(): ?array { return $this->metaJson; }
    public function setMetaJson(?array $metaJson): self { $this->metaJson = $metaJson; return $this; }
}
