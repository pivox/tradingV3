<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TradeZoneEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeZoneEventRepository::class)]
#[ORM\Table(name: 'trade_zone_events')]
#[ORM\Index(name: 'idx_zone_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_zone_reason', columns: ['reason'])]
#[ORM\Index(name: 'idx_zone_happened_at', columns: ['happened_at'])]
class TradeZoneEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $happenedAt;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $reason;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $decisionKey = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $timeframe = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $configProfile = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $zoneMin;

    #[ORM\Column(type: Types::FLOAT)]
    private float $zoneMax;

    #[ORM\Column(type: Types::FLOAT)]
    private float $candidatePrice;

    #[ORM\Column(type: Types::FLOAT)]
    private float $zoneDevPct;

    #[ORM\Column(type: Types::FLOAT)]
    private float $zoneMaxDevPct;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $atrPct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $spreadBps = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $volumeRatio = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $vwapDistancePct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryZoneWidthPct = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $mtfContext = [];

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $mtfLevel = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $proposedZoneMaxPct = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $category = 'close_to_threshold';

    public function __construct(
        string $symbol,
        string $reason,
        float $zoneMin,
        float $zoneMax,
        float $candidatePrice,
        float $zoneDevPct,
        float $zoneMaxDevPct,
        ?\DateTimeImmutable $happenedAt = null,
    ) {
        $this->symbol = strtoupper($symbol);
        $this->reason = $reason;
        $this->zoneMin = $zoneMin;
        $this->zoneMax = $zoneMax;
        $this->candidatePrice = $candidatePrice;
        $this->zoneDevPct = $zoneDevPct;
        $this->zoneMaxDevPct = $zoneMaxDevPct;
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

    public function setSymbol(string $symbol): self
    {
        $this->symbol = strtoupper($symbol);

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

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getDecisionKey(): ?string
    {
        return $this->decisionKey;
    }

    public function setDecisionKey(?string $decisionKey): self
    {
        $this->decisionKey = $decisionKey;

        return $this;
    }

    public function getTimeframe(): ?string
    {
        return $this->timeframe;
    }

    public function setTimeframe(?string $timeframe): self
    {
        $this->timeframe = $timeframe;

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

    public function getZoneMin(): float
    {
        return $this->zoneMin;
    }

    public function setZoneMin(float $zoneMin): self
    {
        $this->zoneMin = $zoneMin;

        return $this;
    }

    public function getZoneMax(): float
    {
        return $this->zoneMax;
    }

    public function setZoneMax(float $zoneMax): self
    {
        $this->zoneMax = $zoneMax;

        return $this;
    }

    public function getCandidatePrice(): float
    {
        return $this->candidatePrice;
    }

    public function setCandidatePrice(float $candidatePrice): self
    {
        $this->candidatePrice = $candidatePrice;

        return $this;
    }

    public function getZoneDevPct(): float
    {
        return $this->zoneDevPct;
    }

    public function setZoneDevPct(float $zoneDevPct): self
    {
        $this->zoneDevPct = $zoneDevPct;

        return $this;
    }

    public function getZoneMaxDevPct(): float
    {
        return $this->zoneMaxDevPct;
    }

    public function setZoneMaxDevPct(float $zoneMaxDevPct): self
    {
        $this->zoneMaxDevPct = $zoneMaxDevPct;

        return $this;
    }

    public function getAtrPct(): ?float
    {
        return $this->atrPct;
    }

    public function setAtrPct(?float $atrPct): self
    {
        $this->atrPct = $atrPct;

        return $this;
    }

    public function getSpreadBps(): ?float
    {
        return $this->spreadBps;
    }

    public function setSpreadBps(?float $spreadBps): self
    {
        $this->spreadBps = $spreadBps;

        return $this;
    }

    public function getVolumeRatio(): ?float
    {
        return $this->volumeRatio;
    }

    public function setVolumeRatio(?float $volumeRatio): self
    {
        $this->volumeRatio = $volumeRatio;

        return $this;
    }

    public function getVwapDistancePct(): ?float
    {
        return $this->vwapDistancePct;
    }

    public function setVwapDistancePct(?float $vwapDistancePct): self
    {
        $this->vwapDistancePct = $vwapDistancePct;

        return $this;
    }

    public function getEntryZoneWidthPct(): ?float
    {
        return $this->entryZoneWidthPct;
    }

    public function setEntryZoneWidthPct(?float $entryZoneWidthPct): self
    {
        $this->entryZoneWidthPct = $entryZoneWidthPct;

        return $this;
    }

    public function getMtfContext(): array
    {
        return $this->mtfContext;
    }

    /**
     * @param array<string,mixed> $mtfContext
     */
    public function setMtfContext(array $mtfContext): self
    {
        $this->mtfContext = $mtfContext;

        return $this;
    }

    public function getMtfLevel(): ?string
    {
        return $this->mtfLevel;
    }

    public function setMtfLevel(?string $mtfLevel): self
    {
        $this->mtfLevel = $mtfLevel;

        return $this;
    }

    public function getProposedZoneMaxPct(): ?float
    {
        return $this->proposedZoneMaxPct;
    }

    public function setProposedZoneMaxPct(?float $proposedZoneMaxPct): self
    {
        $this->proposedZoneMaxPct = $proposedZoneMaxPct;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }
}
