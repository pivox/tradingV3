<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderLifecycleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderLifecycleRepository::class)]
#[ORM\Table(name: 'order_lifecycle')]
#[ORM\UniqueConstraint(name: 'uniq_order_lifecycle_order_id', columns: ['order_id'])]
#[ORM\Index(name: 'idx_order_lifecycle_symbol', columns: ['symbol'])]
class OrderLifecycle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'order_id', type: Types::STRING, length: 80)]
    private string $orderId;

    #[ORM\Column(name: 'client_order_id', type: Types::STRING, length: 80, nullable: true)]
    private ?string $clientOrderId;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $side;

    #[ORM\Column(type: Types::STRING, length: 24, nullable: true)]
    private ?string $type;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $lastAction;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastEventAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload = [];

    public function __construct(
        string $orderId,
        string $symbol,
        string $status,
        ?string $clientOrderId = null,
        ?string $side = null,
        ?string $type = null,
        ?\DateTimeImmutable $eventTime = null
    ) {
        $this->orderId = $orderId;
        $this->clientOrderId = $clientOrderId;
        $this->symbol = strtoupper($symbol);
        $this->status = strtoupper($status);
        $this->side = $side !== null ? strtoupper($side) : null;
        $this->type = $type !== null ? strtoupper($type) : null;

        $now = $eventTime ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->lastEventAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getClientOrderId(): ?string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(?string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId;
        $this->touch();

        return $this;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function setSide(?string $side): self
    {
        $this->side = $side !== null ? strtoupper($side) : null;
        $this->touch();

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type !== null ? strtoupper($type) : null;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status, ?\DateTimeImmutable $eventTime = null): self
    {
        $this->status = strtoupper($status);
        $this->lastEventAt = $eventTime ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();

        return $this;
    }

    public function getLastAction(): ?string
    {
        return $this->lastAction;
    }

    public function setLastAction(?string $action): self
    {
        $this->lastAction = $action !== null ? strtoupper($action) : null;
        $this->touch();

        return $this;
    }

    public function getLastEventAt(): \DateTimeImmutable
    {
        return $this->lastEventAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function mergePayload(array $payload): self
    {
        $this->payload = array_merge($this->payload, $payload);
        $this->touch();

        return $this;
    }

    public function replacePayload(array $payload): self
    {
        $this->payload = $payload;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

