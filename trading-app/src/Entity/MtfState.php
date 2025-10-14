<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MtfStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MtfStateRepository::class)]
#[ORM\Table(name: 'mtf_state')]
#[ORM\Index(name: 'idx_mtf_state_symbol', columns: ['symbol'])]
#[ORM\UniqueConstraint(name: 'ux_mtf_state_symbol', columns: ['symbol'])]
class MtfState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: 'postgres_timestamp', nullable: true)]
    private ?\DateTimeImmutable $k4hTime = null;

    #[ORM\Column(type: 'postgres_timestamp', nullable: true)]
    private ?\DateTimeImmutable $k1hTime = null;

    #[ORM\Column(type: 'postgres_timestamp', nullable: true)]
    private ?\DateTimeImmutable $k15mTime = null;

    #[ORM\Column(type: 'postgres_timestamp', nullable: true)]
    private ?\DateTimeImmutable $k5mTime = null;

    #[ORM\Column(type: 'postgres_timestamp', nullable: true)]
    private ?\DateTimeImmutable $k1mTime = null;

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $sides = [];

    #[ORM\Column(type: 'postgres_timestamp', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
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

    public function getK4hTime(): ?\DateTimeImmutable
    {
        return $this->k4hTime;
    }

    public function setK4hTime(?\DateTimeImmutable $k4hTime): static
    {
        $this->k4hTime = $k4hTime;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function getK1hTime(): ?\DateTimeImmutable
    {
        return $this->k1hTime;
    }

    public function setK1hTime(?\DateTimeImmutable $k1hTime): static
    {
        $this->k1hTime = $k1hTime;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function getK15mTime(): ?\DateTimeImmutable
    {
        return $this->k15mTime;
    }

    public function setK15mTime(?\DateTimeImmutable $k15mTime): static
    {
        $this->k15mTime = $k15mTime;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function getSides(): array
    {
        return $this->sides;
    }

    public function setSides(array $sides): static
    {
        $this->sides = $sides;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function getSide(string $timeframe): ?string
    {
        return $this->sides[$timeframe] ?? null;
    }

    public function setSide(string $timeframe, ?string $side): static
    {
        $this->sides[$timeframe] = $side;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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

    public function is4hValidated(): bool
    {
        return $this->k4hTime !== null;
    }

    public function is1hValidated(): bool
    {
        return $this->k1hTime !== null;
    }

    public function is15mValidated(): bool
    {
        return $this->k15mTime !== null;
    }

    public function areParentTimeframesValidated(): bool
    {
        return $this->is4hValidated() && $this->is1hValidated() && $this->is15mValidated();
    }

    public function get4hSide(): ?string
    {
        return $this->getSide('4h');
    }

    public function set4hSide(?string $side): static
    {
        return $this->setSide('4h', $side);
    }

    public function get1hSide(): ?string
    {
        return $this->getSide('1h');
    }

    public function set1hSide(?string $side): static
    {
        return $this->setSide('1h', $side);
    }

    public function get15mSide(): ?string
    {
        return $this->getSide('15m');
    }

    public function set15mSide(?string $side): static
    {
        return $this->setSide('15m', $side);
    }

    public function get5mSide(): ?string
    {
        return $this->getSide('5m');
    }

    public function set5mSide(?string $side): static
    {
        return $this->setSide('5m', $side);
    }

    public function get1mSide(): ?string
    {
        return $this->getSide('1m');
    }

    public function set1mSide(?string $side): static
    {
        return $this->setSide('1m', $side);
    }

    public function hasConsistentSides(): bool
    {
        $sides = array_filter($this->sides);
        if (empty($sides)) {
            return false;
        }
        
        $uniqueSides = array_unique($sides);
        return count($uniqueSides) === 1;
    }

    public function getConsistentSide(): ?string
    {
        if (!$this->hasConsistentSides()) {
            return null;
        }
        
        $sides = array_filter($this->sides);
        return reset($sides);
    }

    public function setK5mTime(mixed $k5mTime): static
    {
        $this->k5mTime = $k5mTime;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function setK1mTime(mixed $k1mTime): static
    {
        $this->k1mTime = $k1mTime;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function getK5mTime(): ?\DateTimeImmutable
    {
        return $this->k5mTime;
    }

    public function getK1mTime(): ?\DateTimeImmutable
    {
        return $this->k1mTime;
    }


}
