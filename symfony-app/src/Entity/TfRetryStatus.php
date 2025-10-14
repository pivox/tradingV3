<?php

declare(strict_types=1);

namespace App\Entity\MTF;

// @tag:mtf-core  Compteur de tentatives métier par timeframe

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tf_retry_status')]
#[ORM\UniqueConstraint(name: 'pk_symbol_tf', columns: ['symbol', 'tf'])]
class TfRetryStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $symbol;

    #[ORM\Column(type: 'string', length: 8)]
    private string $tf;

    #[ORM\Column(name: 'retry_count', type: 'integer')]
    private int $retryCount = 0;

    #[ORM\Column(name: 'last_result', type: 'string', length: 8)]
    private string $lastResult = 'NONE'; // NONE | SUCCESS | FAILED

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int { return $this->id; }
    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }
    public function getTf(): string { return $this->tf; }
    public function setTf(string $tf): self { $this->tf = $tf; return $this; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function setRetryCount(int $v): self { $this->retryCount = $v; return $this; }
    public function getLastResult(): string { return $this->lastResult; }
    public function setLastResult(string $v): self { $this->lastResult = $v; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }
}
