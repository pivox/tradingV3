<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FuturesPlanOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FuturesPlanOrderRepository::class)]
#[ORM\Table(name: 'futures_plan_order')]
#[ORM\UniqueConstraint(name: 'ux_futures_plan_order_order_id', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'ux_futures_plan_order_client', columns: ['client_order_id'])]
#[ORM\Index(name: 'idx_futures_plan_order_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_futures_plan_order_status', columns: ['status'])]
class FuturesPlanOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $orderId = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $clientOrderId = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $side = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $type = null; // limit, market

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    private ?string $status = null; // pending, triggered, cancelled

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $triggerPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $executionPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $size = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $openType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $positionMode = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $leverage = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $planType = null; // normal, preset

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $triggerTime = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $createdTime = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $updatedTime = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rawData = [];

    #[ORM\ManyToOne(targetEntity: FuturesOrder::class)]
    #[ORM\JoinColumn(name: 'futures_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?FuturesOrder $futuresOrder = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
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
        $this->orderId = $orderId;
        return $this->touch();
    }

    public function getClientOrderId(): ?string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(?string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId;
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

    public function getSide(): ?int
    {
        return $this->side;
    }

    public function setSide(?int $side): self
    {
        $this->side = $side;
        return $this->touch();
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type !== null ? strtolower($type) : null;
        return $this->touch();
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status !== null ? strtolower($status) : null;
        return $this->touch();
    }

    public function getTriggerPrice(): ?string
    {
        return $this->triggerPrice;
    }

    public function setTriggerPrice(?string $triggerPrice): self
    {
        $this->triggerPrice = $triggerPrice;
        return $this->touch();
    }

    public function getExecutionPrice(): ?string
    {
        return $this->executionPrice;
    }

    public function setExecutionPrice(?string $executionPrice): self
    {
        $this->executionPrice = $executionPrice;
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

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;
        return $this->touch();
    }

    public function getOpenType(): ?string
    {
        return $this->openType;
    }

    public function setOpenType(?string $openType): self
    {
        $this->openType = $openType !== null ? strtolower($openType) : null;
        return $this->touch();
    }

    public function getPositionMode(): ?int
    {
        return $this->positionMode;
    }

    public function setPositionMode(?int $positionMode): self
    {
        $this->positionMode = $positionMode;
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

    public function getPlanType(): ?string
    {
        return $this->planType;
    }

    public function setPlanType(?string $planType): self
    {
        $this->planType = $planType !== null ? strtolower($planType) : null;
        return $this->touch();
    }

    public function getTriggerTime(): ?int
    {
        return $this->triggerTime;
    }

    public function setTriggerTime(?int $triggerTime): self
    {
        $this->triggerTime = $triggerTime;
        return $this->touch();
    }

    public function getCreatedTime(): ?int
    {
        return $this->createdTime;
    }

    public function setCreatedTime(?int $createdTime): self
    {
        $this->createdTime = $createdTime;
        return $this->touch();
    }

    public function getUpdatedTime(): ?int
    {
        return $this->updatedTime;
    }

    public function setUpdatedTime(?int $updatedTime): self
    {
        $this->updatedTime = $updatedTime;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * @param array<string,mixed> $rawData
     */
    public function setRawData(array $rawData): self
    {
        $this->rawData = $rawData;
        return $this->touch();
    }

    public function getFuturesOrder(): ?FuturesOrder
    {
        return $this->futuresOrder;
    }

    public function setFuturesOrder(?FuturesOrder $futuresOrder): self
    {
        $this->futuresOrder = $futuresOrder;
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

