<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExchangeOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExchangeOrderRepository::class)]
#[ORM\Table(name: 'exchange_order')]
#[ORM\UniqueConstraint(name: 'ux_exchange_order_client', columns: ['client_order_id'])]
#[ORM\Index(name: 'idx_exchange_order_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_exchange_order_kind', columns: ['kind'])]
#[ORM\Index(name: 'idx_exchange_order_status', columns: ['status'])]
class ExchangeOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $orderId = null;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $clientOrderId;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $parentClientOrderId = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $kind;

    #[ORM\Column(type: Types::STRING, length: 24)]
    private string $status;

    #[ORM\Column(type: Types::STRING, length: 24)]
    private string $type;

    #[ORM\Column(type: Types::STRING, length: 24)]
    private string $side;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $leverage = null;

    #[ORM\ManyToOne(targetEntity: OrderPlan::class)]
    #[ORM\JoinColumn(name: 'order_plan_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?OrderPlan $orderPlan = null;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(name: 'position_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Position $position = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $exchangePayload = [];

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $clientOrderId, string $symbol, string $kind)
    {
        $this->clientOrderId = strtoupper($clientOrderId);
        $this->symbol = strtoupper($symbol);
        $this->kind = strtoupper($kind);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->submittedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->status = 'SUBMITTED';
        $this->type = 'LIMIT';
        $this->side = 'BUY';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId !== null ? (string) $orderId : null;
        return $this->touch();
    }

    public function getClientOrderId(): string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(string $clientOrderId): self
    {
        $this->clientOrderId = strtoupper($clientOrderId);
        return $this->touch();
    }

    public function getParentClientOrderId(): ?string
    {
        return $this->parentClientOrderId;
    }

    public function setParentClientOrderId(?string $parentClientOrderId): self
    {
        $this->parentClientOrderId = $parentClientOrderId !== null ? strtoupper($parentClientOrderId) : null;
        return $this->touch();
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): self
    {
        $this->symbol = strtoupper($symbol);
        return $this->touch();
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = strtoupper($kind);
        return $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = strtoupper($status);
        return $this->touch();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = strtoupper($type);
        return $this->touch();
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function setSide(string $side): self
    {
        $this->side = strtoupper($side);
        return $this->touch();
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): self
    {
        $this->price = $price;
        return $this->touch();
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): self
    {
        $this->size = $size;
        return $this->touch();
    }

    public function getLeverage(): ?int
    {
        return $this->leverage;
    }

    public function setLeverage(?int $leverage): self
    {
        $this->leverage = $leverage;
        return $this->touch();
    }

    public function getOrderPlan(): ?OrderPlan
    {
        return $this->orderPlan;
    }

    public function setOrderPlan(?OrderPlan $orderPlan): self
    {
        $this->orderPlan = $orderPlan;
        return $this->touch();
    }

    public function getPosition(): ?Position
    {
        return $this->position;
    }

    public function setPosition(?Position $position): self
    {
        $this->position = $position;
        return $this->touch();
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): self
    {
        $this->submittedAt = $submittedAt;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>
     */
    public function getExchangePayload(): array
    {
        return $this->exchangePayload;
    }

    /**
     * @param array<string,mixed> $exchangePayload
     */
    public function setExchangePayload(array $exchangePayload): self
    {
        $this->exchangePayload = $exchangePayload;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }
}
