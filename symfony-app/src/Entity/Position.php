<?php

namespace App\Entity;

use App\Repository\PositionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'positions')]
#[ORM\HasLifecycleCallbacks]
class Position
{
    public const SIDE_LONG  = 'LONG';
    public const SIDE_SHORT = 'SHORT';

    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_OPEN      = 'OPEN';
    public const STATUS_CLOSED    = 'CLOSED';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * FK vers Contract(symbol) — Contract a une PK string 'symbol'
     */
    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(
        name: 'contract_symbol',
        referencedColumnName: 'symbol',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private Contract $contract;

    #[ORM\Column(length: 32)]
    private string $exchange = 'bitmart';

    #[ORM\Column(length: 5)]
    private string $side = self::SIDE_LONG;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    // Montant notionnel en USDT (ex: 100)
    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $amountUsdt = '0';

    // Prix d'entrée et quantité (en contrats), si connus au moment de l'ouverture
    #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
    private ?string $entryPrice = null;

    #[ORM\Column(type: 'decimal', precision: 28, scale: 12, nullable: true)]
    private ?string $qtyContract = null;

    // Effet de levier (facultatif)
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $leverage = null;

    // Référence d’ordre côté exchange (si tu intègres un jour l’API de trading)
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $externalOrderId = null;

    // Timestamps métier
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    // Gestion du risque
    #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
    private ?string $stopLoss = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
    private ?string $takeProfit = null;

    // PnL réalisé en USDT (à la clôture)
    #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
    private ?string $pnlUsdt = null;

    // Espace libre pour stocker la décision / scores indicateurs / debug
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    // Audit
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ------------ Getters / Setters ------------

    public function getId(): ?int { return $this->id; }

    public function getContract(): Contract { return $this->contract; }
    public function setContract(Contract $contract): self { $this->contract = $contract; return $this; }

    public function getExchange(): string { return $this->exchange; }
    public function setExchange(string $exchange): self { $this->exchange = $exchange; return $this; }

    public function getSide(): string { return $this->side; }
    public function setSide(string $side): self { $this->side = $side; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getAmountUsdt(): string { return $this->amountUsdt; }
    public function setAmountUsdt(string $amountUsdt): self { $this->amountUsdt = $amountUsdt; return $this; }

    public function getEntryPrice(): ?string { return $this->entryPrice; }
    public function setEntryPrice(?string $entryPrice): self { $this->entryPrice = $entryPrice; return $this; }

    public function getQtyContract(): ?string { return $this->qtyContract; }
    public function setQtyContract(?string $qtyContract): self { $this->qtyContract = $qtyContract; return $this; }

    public function getLeverage(): ?string { return $this->leverage; }
    public function setLeverage(?string $leverage): self { $this->leverage = $leverage; return $this; }

    public function getExternalOrderId(): ?string { return $this->externalOrderId; }
    public function setExternalOrderId(?string $externalOrderId): self { $this->externalOrderId = $externalOrderId; return $this; }

    public function getOpenedAt(): ?\DateTimeImmutable { return $this->openedAt; }
    public function setOpenedAt(?\DateTimeImmutable $openedAt): self { $this->openedAt = $openedAt; return $this; }

    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $closedAt): self { $this->closedAt = $closedAt; return $this; }

    public function getStopLoss(): ?string { return $this->stopLoss; }
    public function setStopLoss(?string $stopLoss): self { $this->stopLoss = $stopLoss; return $this; }

    public function getTakeProfit(): ?string { return $this->takeProfit; }
    public function setTakeProfit(?string $takeProfit): self { $this->takeProfit = $takeProfit; return $this; }

    public function getPnlUsdt(): ?string { return $this->pnlUsdt; }
    public function setPnlUsdt(?string $pnlUsdt): self { $this->pnlUsdt = $pnlUsdt; return $this; }

    public function getMeta(): ?array { return $this->meta; }
    public function setMeta(?array $meta): self { $this->meta = $meta; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}

