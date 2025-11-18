<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FuturesTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FuturesTransactionRepository::class)]
#[ORM\Table(name: 'futures_transaction')]
#[ORM\Index(name: 'idx_futures_tx_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_futures_tx_flow_type', columns: ['flow_type'])]
#[ORM\Index(name: 'idx_futures_tx_happened_at', columns: ['happened_at'])]
class FuturesTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    /**
     * flow_type Bitmart (ex: 2 = realized PnL, 3 = funding, 4 = commission, ...)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $flowType;

    /**
     * Montant de la transaction (toujours stocké en string pour éviter les problèmes de précision)
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $amount;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $currency;

    /**
     * Horodatage de la transaction (UTC)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $happenedAt;

    /**
     * Optionnel : rattacher à une position
     */
    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(name: 'position_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Position $position = null;

    /**
     * Optionnel : rattacher à un trade (si tu arrives à le corréler)
     */
    #[ORM\ManyToOne(targetEntity: FuturesOrderTrade::class)]
    #[ORM\JoinColumn(name: 'trade_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?FuturesOrderTrade $trade = null;

    /**
     * Données brutes Bitmart (JSON) pour debug / audit
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $rawData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
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

    public function setSymbol(string $symbol): self
    {
        $this->symbol = strtoupper($symbol);
        return $this->touch();
    }

    public function getFlowType(): int
    {
        return $this->flowType;
    }

    public function setFlowType(int $flowType): self
    {
        $this->flowType = $flowType;
        return $this->touch();
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this->touch();
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper($currency);
        return $this->touch();
    }

    public function getHappenedAt(): \DateTimeImmutable
    {
        return $this->happenedAt;
    }

    public function setHappenedAt(\DateTimeImmutable $happenedAt): self
    {
        $this->happenedAt = $happenedAt;
        return $this->touch();
    }

    public function getPosition(): ?Position
    {
        return $this->position;
    }

    public function setPosition(?Position $position): self
    {
        $this->position = $position;
        return $this->touch();
    }

    public function getTrade(): ?FuturesOrderTrade
    {
        return $this->trade;
    }

    public function setTrade(?FuturesOrderTrade $trade): self
    {
        $this->trade = $trade;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * @param array<string,mixed> $rawData
     */
    public function setRawData(array $rawData): self
    {
        $this->rawData = $rawData;
        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
