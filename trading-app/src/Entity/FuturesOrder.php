<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FuturesOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FuturesOrderRepository::class)]
#[ORM\Table(name: 'futures_order')]
#[ORM\UniqueConstraint(name: 'ux_futures_order_order_id', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'ux_futures_order_client', columns: ['client_order_id'])]
#[ORM\Index(name: 'idx_futures_order_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_futures_order_status', columns: ['status'])]
#[ORM\Index(name: 'idx_futures_order_client_order_id', columns: ['client_order_id'])]
class FuturesOrder
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
    private ?int $side = null; // 1=open_long, 2=close_long, 3=close_short, 4=open_short

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $type = null; // limit, market

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    private ?string $status = null; // pending, filled, cancelled, etc.

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $size = null; // nombre de contrats

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $filledSize = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $filledNotional = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $openType = null; // isolated, cross

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $positionMode = null; // 1=hedge, 2=one-way

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $leverage = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $fee = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $feeCurrency = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $account = null; // futures, copy_trading

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $filledTime = null; // timestamp millis

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $createdTime = null; // timestamp millis

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $updatedTime = null; // timestamp millis

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rawData = []; // données complètes de l'API

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

    public function getFilledSize(): ?int
    {
        return $this->filledSize;
    }

    public function setFilledSize(?int $filledSize): self
    {
        $this->filledSize = $filledSize;
        return $this->touch();
    }

    public function getFilledNotional(): ?string
    {
        return $this->filledNotional;
    }

    public function setFilledNotional(?string $filledNotional): self
    {
        $this->filledNotional = $filledNotional;
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

    public function getFee(): ?string
    {
        return $this->fee;
    }

    public function setFee(?string $fee): self
    {
        $this->fee = $fee;
        return $this->touch();
    }

    public function getFeeCurrency(): ?string
    {
        return $this->feeCurrency;
    }

    public function setFeeCurrency(?string $feeCurrency): self
    {
        $this->feeCurrency = $feeCurrency;
        return $this->touch();
    }

    public function getAccount(): ?string
    {
        return $this->account;
    }

    public function setAccount(?string $account): self
    {
        $this->account = $account !== null ? strtolower($account) : null;
        return $this->touch();
    }

    public function getFilledTime(): ?int
    {
        return $this->filledTime;
    }

    public function setFilledTime(?int $filledTime): self
    {
        $this->filledTime = $filledTime;
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

