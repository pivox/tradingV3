<?php

declare(strict_types=1);

namespace App\Trading\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'position_trade_analysis')]
class PositionTradeAnalysis
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT, name: 'entry_event_id')]
    private int $entryEventId;

    #[ORM\Column(type: Types::BIGINT, name: 'close_event_id', nullable: true)]
    private ?int $closeEventId = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $timeframe = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $runId = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $tradeId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $entryTime;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closeTime = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $expectedRMultiple = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $riskUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $notionalUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $atrPctEntry = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryVolumeRatio = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $snapshotKlineTime = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryRsi = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryAtr = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryMacd = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryMa9 = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryMa21 = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entryVwap = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $pnlR = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $pnlUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $pnlPct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $mfePct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $maePct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $holdingTimeSec = null;

    public function getEntryEventId(): int
    {
        return $this->entryEventId;
    }

    public function getCloseEventId(): ?int
    {
        return $this->closeEventId;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getTimeframe(): ?string
    {
        return $this->timeframe;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function getTradeId(): ?string
    {
        return $this->tradeId;
    }

    public function getEntryTime(): \DateTimeImmutable
    {
        return $this->entryTime;
    }

    public function getCloseTime(): ?\DateTimeImmutable
    {
        return $this->closeTime;
    }

    public function getExpectedRMultiple(): ?float
    {
        return $this->expectedRMultiple;
    }

    public function getRiskUsdt(): ?float
    {
        return $this->riskUsdt;
    }

    public function getNotionalUsdt(): ?float
    {
        return $this->notionalUsdt;
    }

    public function getAtrPctEntry(): ?float
    {
        return $this->atrPctEntry;
    }

    public function getEntryVolumeRatio(): ?float
    {
        return $this->entryVolumeRatio;
    }

    public function getSnapshotKlineTime(): ?\DateTimeImmutable
    {
        return $this->snapshotKlineTime;
    }

    public function getEntryRsi(): ?float
    {
        return $this->entryRsi;
    }

    public function getEntryAtr(): ?float
    {
        return $this->entryAtr;
    }

    public function getEntryMacd(): ?float
    {
        return $this->entryMacd;
    }

    public function getEntryMa9(): ?float
    {
        return $this->entryMa9;
    }

    public function getEntryMa21(): ?float
    {
        return $this->entryMa21;
    }

    public function getEntryVwap(): ?float
    {
        return $this->entryVwap;
    }

    public function getPnlR(): ?float
    {
        return $this->pnlR;
    }

    public function getPnlUsdt(): ?float
    {
        return $this->pnlUsdt;
    }

    public function getPnlPct(): ?float
    {
        return $this->pnlPct;
    }

    public function getMfePct(): ?float
    {
        return $this->mfePct;
    }

    public function getMaePct(): ?float
    {
        return $this->maePct;
    }

    public function getHoldingTimeSec(): ?float
    {
        return $this->holdingTimeSec;
    }
}
