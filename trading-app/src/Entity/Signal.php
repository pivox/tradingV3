<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SignalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SignalRepository::class)]
#[ORM\Table(name: 'signals')]
#[ORM\Index(name: 'idx_signals_symbol_tf', columns: ['symbol', 'timeframe'])]
#[ORM\Index(name: 'idx_signals_kline_time', columns: ['kline_time'])]
#[ORM\Index(name: 'idx_signals_side', columns: ['side'])]
#[ORM\UniqueConstraint(name: 'ux_signals_symbol_tf_time', columns: ['symbol', 'timeframe', 'kline_time'])]
class Signal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: \App\Common\Enum\Timeframe::class)]
    private \App\Common\Enum\Timeframe $timeframe;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $klineTime;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: \App\Common\Enum\SignalSide::class)]
    private \App\Common\Enum\SignalSide $side;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $score = null;

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $meta = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $insertedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
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

    public function getTimeframe(): \App\Common\Enum\Timeframe
    {
        return $this->timeframe;
    }

    public function setTimeframe(\App\Common\Enum\Timeframe $timeframe): static
    {
        $this->timeframe = $timeframe;
        return $this;
    }

    public function getKlineTime(): \DateTimeImmutable
    {
        return $this->klineTime;
    }

    public function setKlineTime(\DateTimeImmutable $klineTime): static
    {
        $this->klineTime = $klineTime;
        return $this;
    }

    public function getSide(): \App\Common\Enum\SignalSide
    {
        return $this->side;
    }

    public function setSide(\App\Common\Enum\SignalSide $side): static
    {
        $this->side = $side;
        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function setMeta(array $meta): static
    {
        $this->meta = $meta;
        return $this;
    }

    public function getMetaValue(string $key): mixed
    {
        return $this->meta[$key] ?? null;
    }

    public function setMetaValue(string $key, mixed $value): static
    {
        $this->meta[$key] = $value;
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

    public function isLong(): bool
    {
        return $this->side->isLong();
    }

    public function isShort(): bool
    {
        return $this->side->isShort();
    }

    public function isNone(): bool
    {
        return $this->side->isNone();
    }

    public function hasScore(): bool
    {
        return $this->score !== null;
    }

    public function isStrongSignal(float $threshold = 0.7): bool
    {
        return $this->score !== null && $this->score >= $threshold;
    }

    public function isWeakSignal(float $threshold = 0.3): bool
    {
        return $this->score !== null && $this->score <= $threshold;
    }

    public function getTrigger(): ?string
    {
        return $this->getMetaValue('trigger');
    }

    public function setTrigger(?string $trigger): static
    {
        return $this->setMetaValue('trigger', $trigger);
    }
}




