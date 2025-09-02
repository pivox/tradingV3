<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ApiFilter(SearchFilter::class, properties: ['cex' => 'exact'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post()
    ]
)]
class Contract
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $symbol;

    #[ORM\ManyToOne(targetEntity: Exchange::class, inversedBy: 'contracts')]
    #[ORM\JoinColumn(name: 'exchange_name', referencedColumnName: 'name', nullable: false)]
    private Exchange $exchange;

    /**
     * @var Collection<int, Kline>
     */
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

    public function __construct()
    {
        $this->klines = new ArrayCollection();
    }

    public function getExchange(): Exchange { return $this->exchange; }
    public function setExchange(Exchange $exchange): self { $this->exchange = $exchange; return $this; }

    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }
    public function setBaseCurrency(?string $baseCurrency): self { $this->baseCurrency = $baseCurrency; return $this; }
    public function setQuoteCurrency(?string $quoteCurrency): self { $this->quoteCurrency = $quoteCurrency; return $this; }
    public function setIndexName(?string $indexName): self { $this->indexName = $indexName; return $this; }
    public function setContractSize(?float $contractSize): self { $this->contractSize = $contractSize; return $this; }
    public function setPricePrecision(?float $pricePrecision): self { $this->pricePrecision = $pricePrecision; return $this; }
    public function setVolPrecision(?float $volPrecision): self { $this->volPrecision = $volPrecision; return $this; }
    public function setLastPrice(?float $lastPrice): self { $this->lastPrice = $lastPrice; return $this; }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    /**
     * @return Collection<int, Kline>
     */
    public function getKlines(): Collection
    {
        return $this->klines;
    }

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

}
