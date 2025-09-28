<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'trade_events')]
#[ORM\Index(columns: ['aggregate_type', 'aggregate_id', 'occurred_at'], name: 'idx_trade_events_agg_time')]
#[ORM\Index(columns: ['type'], name: 'idx_trade_events_type')]
class TradeEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue('IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    // Ex: "order" | "position" | "pipeline"
    #[ORM\Column(type: 'string', length: 32)]
    private string $aggregateType;

    // Ex: order_id BitMart, ou votre id interne
    #[ORM\Column(type: 'string', length: 128)]
    private string $aggregateId;

    // Ex: "OrderSubmitted", "OrderAccepted", "OrderFilled", "OrderRejected", "PositionOpened", "PositionClosed"
    #[ORM\Column(type: 'string', length: 64)]
    private string $type;

    // Détails arbitraires (symbol, side, size, price, sl/tp, payload API…)
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    // Contexte optionnel (trace/correlation id, nom du use-case, hôte, version…)
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    // Optionnel: clé d’idempotence pour éviter les doublons si vous rejouez une étape
    #[ORM\Column(type: 'string', length: 255, nullable: true, unique: true)]
    private ?string $eventKey = null;

    public function __construct(
        string $aggregateType,
        string $aggregateId,
        string $type,
        ?array $payload = null,
        ?array $context = null,
        ?string $eventKey = null
    ) {
        $this->aggregateType = $aggregateType;
        $this->aggregateId   = $aggregateId;
        $this->type          = $type;
        $this->payload       = $payload;
        $this->context       = $context;
        $this->occurredAt    = new \DateTimeImmutable('now');
        $this->eventKey      = $eventKey;
    }

    public function getId(): ?int { return $this->id; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getType(): string { return $this->type; }
    public function getPayload(): ?array { return $this->payload; }
    public function getContext(): ?array { return $this->context; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getEventKey(): ?string { return $this->eventKey; }
}
