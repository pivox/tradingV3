<?php

declare(strict_types=1);

namespace App\Entity\MTF;

// @tag:mtf-support  Déduplication des événements (WS/REST) pour idempotence

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'event_dedup')]
#[ORM\UniqueConstraint(name: 'pk_event_source', columns: ['event_id', 'source'])]
class EventDedup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'event_id', type: 'string', length: 64)]
    private string $eventId;

    #[ORM\Column(type: 'string', length: 16)]
    private string $source; // ex: "bitmart"

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function getId(): ?int { return $this->id; }
    public function getEventId(): string { return $this->eventId; }
    public function setEventId(string $v): self { $this->eventId = $v; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $v): self { $this->source = $v; return $this; }
    public function getProcessedAt(): \DateTimeImmutable { return $this->processedAt; }
    public function setProcessedAt(\DateTimeImmutable $v): self { $this->processedAt = $v; return $this; }
}
