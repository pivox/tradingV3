<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FuturesOrderTradeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FuturesOrderTradeRepository::class)]
#[ORM\Table(name: 'futures_order_trade')]
#[ORM\UniqueConstraint(name: 'ux_futures_order_trade_trade_id', columns: ['trade_id'])]
#[ORM\Index(name: 'idx_futures_order_trade_order_id', columns: ['order_id'])]
#[ORM\Index(name: 'idx_futures_order_trade_symbol', columns: ['symbol'])]
class FuturesOrderTrade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $tradeId = null;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $orderId; // référence vers futures_order.order_id

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::INTEGER)]
    private int $side;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12)]
    private string $price;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $fee = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $feeCurrency = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $tradeTime; // timestamp millis

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

    public function getTradeId(): ?string
    {
        return $this->tradeId;
    }

    public function setTradeId(?string $tradeId): self
    {
        $this->tradeId = $tradeId;
        return $this->touch();
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): self
    {
        $this->orderId = $orderId;
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

    public function getSide(): int
    {
        return $this->side;
    }

    public function setSide(int $side): self
    {
        $this->side = $side;
        return $this->touch();
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this->touch();
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;
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

    public function getTradeTime(): int
    {
        return $this->tradeTime;
    }

    public function setTradeTime(int $tradeTime): self
    {
        $this->tradeTime = $tradeTime;
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

