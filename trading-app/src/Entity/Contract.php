<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContractRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'contracts')]
#[ORM\UniqueConstraint(name: 'ux_contracts_symbol', columns: ['symbol'])]
class Contract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'product_type', type: Types::INTEGER, nullable: true)]
    private ?int $productType = null;

    #[ORM\Column(name: 'open_timestamp', type: Types::BIGINT, nullable: true)]
    private ?int $openTimestamp = null;

    #[ORM\Column(name: 'expire_timestamp', type: Types::BIGINT, nullable: true)]
    private ?int $expireTimestamp = null;

    #[ORM\Column(name: 'settle_timestamp', type: Types::BIGINT, nullable: true)]
    private ?int $settleTimestamp = null;

    #[ORM\Column(name: 'base_currency', type: Types::STRING, length: 20, nullable: true)]
    private ?string $baseCurrency = null;

    #[ORM\Column(name: 'quote_currency', type: Types::STRING, length: 20, nullable: true)]
    private ?string $quoteCurrency = null;

    #[ORM\Column(name: 'last_price', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $lastPrice = null;

    #[ORM\Column(name: 'volume_24h', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $volume24h = null;

    #[ORM\Column(name: 'turnover_24h', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $turnover24h = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'min_size', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $minSize = null;

    #[ORM\Column(name: 'max_size', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $maxSize = null;

    #[ORM\Column(name: 'tick_size', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $tickSize = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $multiplier = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $insertedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->insertedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getProductType(): ?int
    {
        return $this->productType;
    }

    public function setProductType(?int $productType): static
    {
        $this->productType = $productType;
        return $this;
    }

    public function getOpenTimestamp(): ?int
    {
        return $this->openTimestamp;
    }

    public function setOpenTimestamp(?int $openTimestamp): static
    {
        $this->openTimestamp = $openTimestamp;
        return $this;
    }

    public function getExpireTimestamp(): ?int
    {
        return $this->expireTimestamp;
    }

    public function setExpireTimestamp(?int $expireTimestamp): static
    {
        $this->expireTimestamp = $expireTimestamp;
        return $this;
    }

    public function getSettleTimestamp(): ?int
    {
        return $this->settleTimestamp;
    }

    public function setSettleTimestamp(?int $settleTimestamp): static
    {
        $this->settleTimestamp = $settleTimestamp;
        return $this;
    }

    public function getBaseCurrency(): ?string
    {
        return $this->baseCurrency;
    }

    public function setBaseCurrency(?string $baseCurrency): static
    {
        $this->baseCurrency = $baseCurrency;
        return $this;
    }

    public function getQuoteCurrency(): ?string
    {
        return $this->quoteCurrency;
    }

    public function setQuoteCurrency(?string $quoteCurrency): static
    {
        $this->quoteCurrency = $quoteCurrency;
        return $this;
    }

    public function getLastPrice(): ?string
    {
        return $this->lastPrice;
    }

    public function setLastPrice(?string $lastPrice): static
    {
        $this->lastPrice = $lastPrice;
        return $this;
    }

    public function getVolume24h(): ?string
    {
        return $this->volume24h;
    }

    public function setVolume24h(?string $volume24h): static
    {
        $this->volume24h = $volume24h;
        return $this;
    }

    public function getTurnover24h(): ?string
    {
        return $this->turnover24h;
    }

    public function setTurnover24h(?string $turnover24h): static
    {
        $this->turnover24h = $turnover24h;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getMinSize(): ?string
    {
        return $this->minSize;
    }

    public function setMinSize(?string $minSize): static
    {
        $this->minSize = $minSize;
        return $this;
    }

    public function getMaxSize(): ?string
    {
        return $this->maxSize;
    }

    public function setMaxSize(?string $maxSize): static
    {
        $this->maxSize = $maxSize;
        return $this;
    }

    public function getTickSize(): ?string
    {
        return $this->tickSize;
    }

    public function setTickSize(?string $tickSize): static
    {
        $this->tickSize = $tickSize;
        return $this;
    }

    public function getMultiplier(): ?string
    {
        return $this->multiplier;
    }

    public function setMultiplier(?string $multiplier): static
    {
        $this->multiplier = $multiplier;
        return $this;
    }

    public function getInsertedAt(): \DateTimeImmutable
    {
        return $this->insertedAt;
    }

    public function setInsertedAt(\DateTimeImmutable $insertedAt): static
    {
        $this->insertedAt = $insertedAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === 'Trading' && 
               $this->quoteCurrency === 'USDT' && 
               floatval($this->volume24h ?? 0) >= 500_000;
    }

    public function getOpenDate(): ?\DateTimeImmutable
    {
        if (!$this->openTimestamp) {
            return null;
        }
        return new \DateTimeImmutable('@' . ($this->openTimestamp / 1000), new \DateTimeZone('UTC'));
    }
}
