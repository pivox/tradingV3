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
// À placer dans la classe Contract

    #[ORM\Column(name: 'index_price', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $indexPrice = null;

    #[ORM\Column(name: 'index_name', type: Types::STRING, length: 50, nullable: true)]
    private ?string $indexName = null;

    #[ORM\Column(name: 'contract_size', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $contractSize = null;

    #[ORM\Column(name: 'min_leverage', type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $minLeverage = null;

    #[ORM\Column(name: 'max_leverage', type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $maxLeverage = null;

    #[ORM\Column(name: 'price_precision', type: Types::STRING, length: 20, nullable: true)]
    private ?string $pricePrecision = null;

    #[ORM\Column(name: 'vol_precision', type: Types::STRING, length: 20, nullable: true)]
    private ?string $volPrecision = null;

    #[ORM\Column(name: 'max_volume', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $maxVolume = null;

    #[ORM\Column(name: 'market_max_volume', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $marketMaxVolume = null;

    #[ORM\Column(name: 'min_volume', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $minVolume = null;

    #[ORM\Column(name: 'funding_rate', type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $fundingRate = null;

    #[ORM\Column(name: 'expected_funding_rate', type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $expectedFundingRate = null;

    #[ORM\Column(name: 'open_interest', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $openInterest = null;

    #[ORM\Column(name: 'open_interest_value', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $openInterestValue = null;

    #[ORM\Column(name: 'high_24h', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $high24h = null;

    #[ORM\Column(name: 'low_24h', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $low24h = null;

    #[ORM\Column(name: 'change_24h', type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $change24h = null;

    #[ORM\Column(name: 'funding_interval_hours', type: Types::INTEGER, nullable: true)]
    private ?int $fundingIntervalHours = null;

    #[ORM\Column(name: 'delist_time', type: Types::BIGINT, nullable: true)]
    private ?int $delistTime = null;


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

    /**
     * @return string|null
     */
    public function getIndexPrice(): ?string
    {
        return $this->indexPrice;
    }

    /**
     * @param string|null $indexPrice
     * @return Contract
     */
    public function setIndexPrice(?string $indexPrice): Contract
    {
        $this->indexPrice = $indexPrice;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getIndexName(): ?string
    {
        return $this->indexName;
    }

    /**
     * @param string|null $indexName
     * @return Contract
     */
    public function setIndexName(?string $indexName): Contract
    {
        $this->indexName = $indexName;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getContractSize(): ?string
    {
        return $this->contractSize;
    }

    /**
     * @param string|null $contractSize
     * @return Contract
     */
    public function setContractSize(?string $contractSize): static
    {
        $this->contractSize = $contractSize;
        return $this;
    }
    public function setMinLeverage(?string $minLeverage): static
    {
        $this->minLeverage = $minLeverage;
        return $this;
    }
    public function setMaxLeverage(?string $maxLeverage): static
    {
        $this->maxLeverage = $maxLeverage;
        return $this;
    }
    public function setPricePrecision(?string $pricePrecision): static
    {
        $this->pricePrecision = $pricePrecision;
        return $this;
    }
    public function setVolPrecision(?string $volPrecision): static
    {
        $this->volPrecision = $volPrecision;
        return $this;
    }
    public function setMaxVolume(?string $maxVolume): static
    {
        $this->maxVolume = $maxVolume;
        return $this;
    }

    public function getMaxVolume(): ?string
    {
        return $this->maxVolume;
    }

    public function setMarketMaxVolume(?string $marketMaxVolume): static
    {
        $this->marketMaxVolume = $marketMaxVolume;
        return $this;
    }

    public function getMarketMaxVolume(): ?string
    {
        return $this->marketMaxVolume;
    }

    public function setMinVolume(?string $minVolume): static
    {
        $this->minVolume = $minVolume;
        return $this;
    }

    public function getMinVolume(): ?string
    {
        return $this->minVolume;
    }
    public function setFundingRate(?string $fundingRate): static
    {
        $this->fundingRate = $fundingRate;
        return $this;
    }
    public function setExpectedFundingRate(?string $expectedFundingRate): static
    {
        $this->expectedFundingRate = $expectedFundingRate;
        return $this;
    }
    public function setOpenInterest(?string $openInterest): static
    {
        $this->openInterest = $openInterest;
        return $this;
    }
    public function setOpenInterestValue(?string $openInterestValue): static
    {
        $this->openInterestValue = $openInterestValue;
        return $this;
    }
    public function setHigh24h(?string $high24h): static
    {
        $this->high24h = $high24h;
        return $this;
    }
    public function setLow24h(?string $low24h): static
    {
        $this->low24h = $low24h;
        return $this;
    }
    public function setChange24h(?string $change24h): static
    {
        $this->change24h = $change24h;
        return $this;
    }
    public function setFundingIntervalHours(?int $fundingIntervalHours): static
    {
        $this->fundingIntervalHours = $fundingIntervalHours;
        return $this;
    }
    public function setDelistTime(?int $delistTime): static
    {
        $this->delistTime = $delistTime;
        return $this;
    }


}
