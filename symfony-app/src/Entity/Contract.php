<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiFilter(SearchFilter::class, properties: [
    'exchange.name' => 'exact',
    'symbol' => 'partial',
])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post()
    ]
)]
class Contract
{
    /* ============================================================
     * Constantes : valeurs connues (status / product_type)
     * ============================================================ */
    public const STATUS_TRADING  = 'Trading';
    public const STATUS_DELISTED = 'Delisted';

    // Product types (BitMart Futures V2)
    public const PRODUCT_TYPE_LINEAR  = 1; // ex: BTCUSDT (linéaire, règlement USDT)
    public const PRODUCT_TYPE_INVERSE = 2; // ex: BTCUSD  (inverse, règlement coin)

    /* ============================================================
     * Propriétés
     * ============================================================ */
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $symbol;

    #[ORM\ManyToOne(targetEntity: Exchange::class, inversedBy: 'contracts')]
    #[ORM\JoinColumn(name: 'exchange_name', referencedColumnName: 'name', nullable: false)]
    private Exchange $exchange;

    /** @var Collection<int, Kline> */
    #[ORM\OneToMany(mappedBy: 'contract', targetEntity: Kline::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $klines;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $productType = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $openTimestamp = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expireTimestamp = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $settleTimestamp = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $baseCurrency = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $quoteCurrency = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $lastPrice = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $volume24h = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $turnover24h = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $indexPrice = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $indexName = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $contractSize = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $minLeverage = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxLeverage = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pricePrecision = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $volPrecision = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxVolume = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $minVolume = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $fundingRate = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $expectedFundingRate = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $openInterest = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $openInterestValue = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $high24h = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $low24h = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $change24h = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $fundingTime = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $marketMaxVolume = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fundingIntervalHours = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $status = null;


    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $delistTime = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $nextSchedule = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAttemptedAt = null;

    /* ============================================================
     * Constructeur
     * ============================================================ */
    public function __construct()
    {
        $this->klines = new ArrayCollection();
    }

    /* ============================================================
     * Getters
     * ============================================================ */
    public function getSymbol(): string { return $this->symbol; }
    public function getExchange(): Exchange { return $this->exchange; }
    /** @return Collection<int, Kline> */ public function getKlines(): Collection { return $this->klines; }

    public function getProductType(): ?string { return $this->productType; }
    public function getOpenTimestamp(): ?\DateTimeInterface { return $this->openTimestamp; }
    public function getExpireTimestamp(): ?\DateTimeInterface { return $this->expireTimestamp; }
    public function getSettleTimestamp(): ?\DateTimeInterface { return $this->settleTimestamp; }
    public function getBaseCurrency(): ?string { return $this->baseCurrency; }
    public function getQuoteCurrency(): ?string { return $this->quoteCurrency; }
    public function getLastPrice(): ?float { return $this->lastPrice; }
    public function getVolume24h(): ?int { return $this->volume24h; }
    public function getTurnover24h(): ?float { return $this->turnover24h; }
    public function getIndexPrice(): ?float { return $this->indexPrice; }
    public function getIndexName(): ?string { return $this->indexName; }
    public function getContractSize(): ?float { return $this->contractSize; }
    public function getMinLeverage(): ?int { return $this->minLeverage; }
    public function getMaxLeverage(): ?int { return $this->maxLeverage; }
    public function getPricePrecision(): ?float { return $this->pricePrecision; }
    public function getVolPrecision(): ?float { return $this->volPrecision; }
    public function getMaxVolume(): ?int { return $this->maxVolume; }
    public function getMinVolume(): ?int { return $this->minVolume; }
    public function getFundingRate(): ?float { return $this->fundingRate; }
    public function getExpectedFundingRate(): ?float { return $this->expectedFundingRate; }
    public function getOpenInterest(): ?int { return $this->openInterest; }
    public function getOpenInterestValue(): ?float { return $this->openInterestValue; }
    public function getHigh24h(): ?float { return $this->high24h; }
    public function getLow24h(): ?float { return $this->low24h; }
    public function getChange24h(): ?float { return $this->change24h; }
    public function getFundingTime(): ?\DateTimeInterface { return $this->fundingTime; }
    public function getMarketMaxVolume(): ?int { return $this->marketMaxVolume; }
    public function getFundingIntervalHours(): ?int { return $this->fundingIntervalHours; }
    public function getStatus(): ?string { return $this->status; }
    public function getDelistTime(): ?\DateTimeInterface { return $this->delistTime; }
    public function getNextSchedule(): ?\DateTimeInterface { return $this->nextSchedule; }

    /* ============================================================
     * Setters (fluent)
     * ============================================================ */
    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }
    public function setExchange(Exchange $exchange): self { $this->exchange = $exchange; return $this; }

    public function setProductType(?string $productType): self { $this->productType = $productType; return $this; }
    public function setOpenTimestamp(?\DateTimeInterface $openTimestamp): self { $this->openTimestamp = $openTimestamp; return $this; }
    public function setExpireTimestamp(?\DateTimeInterface $expireTimestamp): self { $this->expireTimestamp = $expireTimestamp; return $this; }
    public function setSettleTimestamp(?\DateTimeInterface $settleTimestamp): self { $this->settleTimestamp = $settleTimestamp; return $this; }

    public function setBaseCurrency(?string $baseCurrency): self { $this->baseCurrency = $baseCurrency; return $this; }
    public function setQuoteCurrency(?string $quoteCurrency): self { $this->quoteCurrency = $quoteCurrency; return $this; }
    public function setIndexName(?string $indexName): self { $this->indexName = $indexName; return $this; }

    public function setContractSize(?float $contractSize): self { $this->contractSize = $contractSize; return $this; }
    public function setPricePrecision(?float $pricePrecision): self { $this->pricePrecision = $pricePrecision; return $this; }
    public function setVolPrecision(?float $volPrecision): self { $this->volPrecision = $volPrecision; return $this; }

    public function setLastPrice(?float $lastPrice): self { $this->lastPrice = $lastPrice; return $this; }
    public function setVolume24h(?int $volume24h): self { $this->volume24h = $volume24h; return $this; }
    public function setTurnover24h(?float $turnover24h): self { $this->turnover24h = $turnover24h; return $this; }
    public function setIndexPrice(?float $indexPrice): self { $this->indexPrice = $indexPrice; return $this; }

    public function setMinLeverage(?int $minLeverage): self { $this->minLeverage = $minLeverage; return $this; }
    public function setMaxLeverage(?int $maxLeverage): self { $this->maxLeverage = $maxLeverage; return $this; }

    public function setMaxVolume(?int $maxVolume): self { $this->maxVolume = $maxVolume; return $this; }
    public function setMinVolume(?int $minVolume): self { $this->minVolume = $minVolume; return $this; }
    public function setMarketMaxVolume(?int $marketMaxVolume): self { $this->marketMaxVolume = $marketMaxVolume; return $this; }

    public function setFundingRate(?float $fundingRate): self { $this->fundingRate = $fundingRate; return $this; }
    public function setExpectedFundingRate(?float $expectedFundingRate): self { $this->expectedFundingRate = $expectedFundingRate; return $this; }

    public function setOpenInterest(?int $openInterest): self { $this->openInterest = $openInterest; return $this; }
    public function setOpenInterestValue(?float $openInterestValue): self { $this->openInterestValue = $openInterestValue; return $this; }

    public function setHigh24h(?float $high24h): self { $this->high24h = $high24h; return $this; }
    public function setLow24h(?float $low24h): self { $this->low24h = $low24h; return $this; }
    public function setChange24h(?float $change24h): self { $this->change24h = $change24h; return $this; }

    public function setFundingTime(?\DateTimeInterface $fundingTime): self { $this->fundingTime = $fundingTime; return $this; }
    public function setFundingIntervalHours(?int $fundingIntervalHours): self { $this->fundingIntervalHours = $fundingIntervalHours; return $this; }

    public function setStatus(?string $status): self { $this->status = $status; return $this; }
    public function setDelistTime(?\DateTimeInterface $delistTime): self { $this->delistTime = $delistTime; return $this; }
    public function setNextSchedule(?\DateTimeInterface $nextSchedule): self { $this->nextSchedule = $nextSchedule; return $this; }

    /* ============================================================
     * Gestion de la relation Kline
     * ============================================================ */
    public function addKline(Kline $kline): self
    {
        if (!$this->klines->contains($kline)) {
            $this->klines->add($kline);
            $kline->setContract($this);
        }
        return $this;
    }

    public function removeKline(Kline $kline): self
    {
        if ($this->klines->removeElement($kline)) {
            if ($kline->getContract() === $this) {
                $kline->setContract(null);
            }
        }
        return $this;
    }

    /* ============================================================
     * Helpers métier (status / product type)
     * ============================================================ */

    /* ============================================================
 * Méthodes métier parlantes (fluent)
 * ============================================================ */

    /** Infos de base (devises, index, tailles/precisions/prix) */
    public function updateCoreInfo(
        ?string $baseCurrency,
        ?string $quoteCurrency,
        ?string $indexName,
        ?float $contractSize,
        ?float $pricePrecision,
        ?float $volPrecision,
        ?float $lastPrice
    ): self {
        return $this
            ->setBaseCurrency($baseCurrency)
            ->setQuoteCurrency($quoteCurrency)
            ->setIndexName($indexName)
            ->setContractSize($contractSize)
            ->setPricePrecision($pricePrecision)
            ->setVolPrecision($volPrecision)
            ->setLastPrice($lastPrice);
    }

    /** Timestamps (ouverts, expiration, règlement) */
    public function updateContractTimestamps(
        ?\DateTimeInterface $openTimestamp,
        ?\DateTimeInterface $expireTimestamp,
        ?\DateTimeInterface $settleTimestamp
    ): self {
        return $this
            ->setOpenTimestamp($openTimestamp)
            ->setExpireTimestamp($expireTimestamp)
            ->setSettleTimestamp($settleTimestamp);
    }

    /** Levier min/max */
    public function updateLeverageBounds(?int $minLeverage, ?int $maxLeverage): self
    {
        return $this
            ->setMinLeverage($minLeverage)
            ->setMaxLeverage($maxLeverage);
    }

    /** Limites de volumes */
    public function updateVolumeLimits(
        ?int $minVolume,
        ?int $maxVolume,
        ?int $marketMaxVolume
    ): self {
        return $this
            ->setMinVolume($minVolume)
            ->setMaxVolume($maxVolume)
            ->setMarketMaxVolume($marketMaxVolume);
    }

    /** Données de financement (taux, attendu, prochaine fenêtre/intervalle) */
    public function updateFundingInfo(
        ?float $fundingRate,
        ?float $expectedFundingRate,
        ?\DateTimeInterface $fundingTime,
        ?int $fundingIntervalHours
    ): self {
        return $this
            ->setFundingRate($fundingRate)
            ->setExpectedFundingRate($expectedFundingRate)
            ->setFundingTime($fundingTime)
            ->setFundingIntervalHours($fundingIntervalHours);
    }

    /** Intérêt ouvert */
    public function updateOpenInterest(?int $openInterest, ?float $openInterestValue): self
    {
        return $this
            ->setOpenInterest($openInterest)
            ->setOpenInterestValue($openInterestValue);
    }

    /** Statistiques 24h (prix et volumes/turnover) */
    public function updateDailyStats(
        ?float $indexPrice,
        ?float $high24h,
        ?float $low24h,
        ?float $change24h,
        ?float $turnover24h,
        ?int $volume24h
    ): self {
        return $this
            ->setIndexPrice($indexPrice)
            ->setHigh24h($high24h)
            ->setLow24h($low24h)
            ->setChange24h($change24h)
            ->setTurnover24h($turnover24h)
            ->setVolume24h($volume24h);
    }

    /** Statut & delist */
    public function updateLifecycle(?string $status, ?\DateTimeInterface $delistTime): self
    {
        return $this
            ->setStatus($status)
            ->setDelistTime($delistTime);
    }

    /** Type de produit (1=linéaire, 2=inverse côté BitMart) */
    public function updateProductTypeFromApi(?int $productType): self
    {
        // On stocke tel quel en string (conforme à ta propriété actuelle).
        return $this->setProductType($productType !== null ? (string) $productType : null);
    }


    public function isTrading(): bool
    {
        return $this->status === self::STATUS_TRADING;
    }

    public function isDelisted(): bool
    {
        return $this->status === self::STATUS_DELISTED;
    }

    public function isLinear(): bool
    {
        return (int) $this->productType === self::PRODUCT_TYPE_LINEAR;
    }

    public function isInverse(): bool
    {
        return (int) $this->productType === self::PRODUCT_TYPE_INVERSE;
    }

    public function getLastAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptedAt;
    }

    public function setLastAttemptedAt(
        ?\DateTimeImmutable $lastAttemptedAt = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC'))
    ): static
    {
        $this->lastAttemptedAt = $lastAttemptedAt->setTime(
            (int)$lastAttemptedAt->format('H'),
            (int)$lastAttemptedAt->format('i'),
            0,
            0
        );;

        return $this;
    }

}
