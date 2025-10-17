<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PositionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'positions')]
#[ORM\UniqueConstraint(name: 'ux_positions_symbol_side', columns: ['symbol', 'side'])]
#[ORM\Index(name: 'idx_positions_symbol', columns: ['symbol'])]
class Position
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $side; // LONG | SHORT

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(name: 'avg_entry_price', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $avgEntryPrice = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $leverage = null;

    #[ORM\Column(name: 'unrealized_pnl', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $unrealizedPnl = null;

    #[ORM\Column(type: Types::STRING, length: 16, options: ['default' => 'OPEN'])]
    private string $status = 'OPEN'; // OPEN | CLOSED

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $insertedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $symbol, string $side)
    {
        $this->symbol = strtoupper($symbol);
        $this->side = strtoupper($side);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->insertedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): self
    {
        $this->size = $size;
        return $this->touch();
    }

    public function getAvgEntryPrice(): ?string
    {
        return $this->avgEntryPrice;
    }

    public function setAvgEntryPrice(?string $avgEntryPrice): self
    {
        $this->avgEntryPrice = $avgEntryPrice;
        return $this->touch();
    }

    public function getLeverage(): ?int
    {
        return $this->leverage;
    }

    public function setLeverage(?int $leverage): self
    {
        $this->leverage = $leverage;
        return $this->touch();
    }

    public function getUnrealizedPnl(): ?string
    {
        return $this->unrealizedPnl;
    }

    public function setUnrealizedPnl(?string $unrealizedPnl): self
    {
        $this->unrealizedPnl = $unrealizedPnl;
        return $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = strtoupper($status);
        return $this->touch();
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function mergePayload(array $payload): self
    {
        $this->payload = array_replace($this->payload, $payload);
        return $this->touch();
    }

    public function getInsertedAt(): \DateTimeImmutable
    {
        return $this->insertedAt;
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


