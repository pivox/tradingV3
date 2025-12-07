<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryZoneLiveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryZoneLiveRepository::class)]
#[ORM\Table(name: 'entry_zone_live')]
class EntryZoneLive
{
    public const STATUS_WAITING = 'waiting';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $symbol;

    #[ORM\Column(length: 8)]
    private string $side;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 15)]
    private string $priceMin;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 15)]
    private string $priceMax;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $atrPct1m = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 15, nullable: true)]
    private ?string $vwap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $volumeRatio = null;

    #[ORM\Column(length: 64)]
    private string $configProfile;

    #[ORM\Column(length: 16)]
    private string $configVersion;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'waiting'])]
    private string $status = self::STATUS_WAITING;

    public function __construct(
        string $symbol,
        string $side,
        string $priceMin,
        string $priceMax,
        string $configProfile,
        string $configVersion,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil,
        ?\DateTimeImmutable $createdAt = null,
        string $status = self::STATUS_WAITING
    ) {
        $this->symbol = strtoupper($symbol);
        $this->side = strtolower($side);
        $this->priceMin = $priceMin;
        $this->priceMax = $priceMax;
        $this->configProfile = $configProfile;
        $this->configVersion = $configVersion;
        $this->validFrom = $validFrom->setTimezone(new \DateTimeZone('UTC'));
        $this->validUntil = $validUntil->setTimezone(new \DateTimeZone('UTC'));
        $this->createdAt = ($createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'));
        $this->status = $status;
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

    public function getSide(): string
    {
        return $this->side;
    }

    public function setSide(string $side): self
    {
        $this->side = strtolower($side);

        return $this;
    }

    public function getPriceMin(): string
    {
        return $this->priceMin;
    }

    public function setPriceMin(string $priceMin): self
    {
        $this->priceMin = $priceMin;

        return $this;
    }

    public function getPriceMax(): string
    {
        return $this->priceMax;
    }

    public function setPriceMax(string $priceMax): self
    {
        $this->priceMax = $priceMax;

        return $this;
    }

    public function getAtrPct1m(): ?string
    {
        return $this->atrPct1m;
    }

    public function setAtrPct1m(?string $atrPct1m): self
    {
        $this->atrPct1m = $atrPct1m;

        return $this;
    }

    public function getVwap(): ?string
    {
        return $this->vwap;
    }

    public function setVwap(?string $vwap): self
    {
        $this->vwap = $vwap;

        return $this;
    }

    public function getVolumeRatio(): ?string
    {
        return $this->volumeRatio;
    }

    public function setVolumeRatio(?string $volumeRatio): self
    {
        $this->volumeRatio = $volumeRatio;

        return $this;
    }

    public function getConfigProfile(): string
    {
        return $this->configProfile;
    }

    public function setConfigProfile(string $configProfile): self
    {
        $this->configProfile = $configProfile;

        return $this;
    }

    public function getConfigVersion(): string
    {
        return $this->configVersion;
    }

    public function setConfigVersion(string $configVersion): self
    {
        $this->configVersion = $configVersion;

        return $this;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getValidUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(\DateTimeImmutable $validUntil): self
    {
        $this->validUntil = $validUntil->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }
}
