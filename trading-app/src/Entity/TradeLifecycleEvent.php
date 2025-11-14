<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TradeLifecycleEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeLifecycleEventRepository::class)]
#[ORM\Table(name: 'trade_lifecycle_event')]
class TradeLifecycleEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $symbol;

    #[ORM\Column(length: 32)]
    private string $eventType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $runId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientOrderId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $positionId = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $side = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 15, nullable: true)]
    private ?string $qty = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 15, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $timeframe = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $configProfile = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $configVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $planId = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $exchange = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $accountId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reasonCode = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $extra = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $happenedAt;

    public function __construct(string $symbol, string $eventType, ?\DateTimeImmutable $happenedAt = null)
    {
        $this->symbol = strtoupper($symbol);
        $this->eventType = strtolower($eventType);
        $this->happenedAt = $happenedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function setRunId(?string $runId): self
    {
        $this->runId = $runId;

        return $this;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId !== null ? strtoupper($orderId) : null;

        return $this;
    }

    public function getClientOrderId(): ?string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(?string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId !== null ? strtoupper($clientOrderId) : null;

        return $this;
    }

    public function getPositionId(): ?string
    {
        return $this->positionId;
    }

    public function setPositionId(?string $positionId): self
    {
        $this->positionId = $positionId;

        return $this;
    }

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function setSide(?string $side): self
    {
        $this->side = $side !== null ? strtoupper($side) : null;

        return $this;
    }

    public function getQty(): ?string
    {
        return $this->qty;
    }

    public function setQty(?string $qty): self
    {
        $this->qty = $qty;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getTimeframe(): ?string
    {
        return $this->timeframe;
    }

    public function setTimeframe(?string $timeframe): self
    {
        $this->timeframe = $timeframe !== null ? strtolower($timeframe) : null;

        return $this;
    }

    public function getConfigProfile(): ?string
    {
        return $this->configProfile;
    }

    public function setConfigProfile(?string $configProfile): self
    {
        $this->configProfile = $configProfile;

        return $this;
    }

    public function getConfigVersion(): ?string
    {
        return $this->configVersion;
    }

    public function setConfigVersion(?string $configVersion): self
    {
        $this->configVersion = $configVersion;

        return $this;
    }

    public function getPlanId(): ?string
    {
        return $this->planId;
    }

    public function setPlanId(?string $planId): self
    {
        $this->planId = $planId;

        return $this;
    }

    public function getExchange(): ?string
    {
        return $this->exchange;
    }

    public function setExchange(?string $exchange): self
    {
        $this->exchange = $exchange;

        return $this;
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function setAccountId(?string $accountId): self
    {
        $this->accountId = $accountId;

        return $this;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function setReasonCode(?string $reasonCode): self
    {
        $this->reasonCode = $reasonCode;

        return $this;
    }

    public function getExtra(): ?array
    {
        return $this->extra;
    }

    public function setExtra(?array $extra): self
    {
        $this->extra = $extra;

        return $this;
    }

    public function getHappenedAt(): \DateTimeImmutable
    {
        return $this->happenedAt;
    }

    public function setHappenedAt(\DateTimeImmutable $happenedAt): self
    {
        $this->happenedAt = $happenedAt;

        return $this;
    }
}
