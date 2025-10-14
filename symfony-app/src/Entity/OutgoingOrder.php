<?php

declare(strict_types=1);

namespace App\Entity\MTF;

// @tag:mtf-support  Traçage des ordres émis pour synchroniser les events WS

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'outgoing_orders')]
class OutgoingOrder
{
    #[ORM\Id]
    #[ORM\Column(name: 'order_id', type: 'string', length: 64)]
    private string $orderId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $symbol;

    #[ORM\Column(type: 'string', length: 16)]
    private string $intent; // OPEN_LONG | OPEN_SHORT | CLOSE

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'dedup_key', type: 'string', length: 64, nullable: true)]
    private ?string $dedupKey = null;

    public function getOrderId(): string { return $this->orderId; }
    public function setOrderId(string $v): self { $this->orderId = $v; return $this; }
    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $v): self { $this->symbol = $v; return $this; }
    public function getIntent(): string { return $this->intent; }
    public function setIntent(string $v): self { $this->intent = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $v): self { $this->createdAt = $v; return $this; }
    public function getDedupKey(): ?string { return $this->dedupKey; }
    public function setDedupKey(?string $v): self { $this->dedupKey = $v; return $this; }
}
