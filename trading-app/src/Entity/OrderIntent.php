<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderIntentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderIntentRepository::class)]
#[ORM\Table(name: 'order_intent')]
#[ORM\Index(name: 'idx_order_intent_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_order_intent_status', columns: ['status'])]
#[ORM\Index(name: 'idx_order_intent_client_order_id', columns: ['client_order_id'])]
class OrderIntent
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_READY_TO_SEND = 'READY_TO_SEND';
    public const STATUS_SENT = 'SENT';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const TYPE_LIMIT = 'limit';
    public const TYPE_MARKET = 'market';

    public const OPEN_TYPE_ISOLATED = 'isolated';
    public const OPEN_TYPE_CROSS = 'cross';

    public const POSITION_MODE_ONE_WAY = 'one_way';
    public const POSITION_MODE_HEDGE = 'hedge';

    public const PRESET_MODE_NONE = 'none';
    public const PRESET_MODE_PRESET_ON_ENTRY = 'preset_on_entry';
    public const PRESET_MODE_POSITION_TP_SL = 'position_tp_sl';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::INTEGER)]
    private int $side; // 1=open_long, 2=close_long, 3=close_short, 4=open_short

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type; // limit, market

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $openType; // isolated, cross

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $leverage = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $positionMode; // one_way, hedge

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $price = null; // Prix limit (pour type=limit)

    #[ORM\Column(type: Types::INTEGER)]
    private int $size; // Nombre de contrats

    #[ORM\Column(type: Types::STRING, length: 80, unique: true)]
    private string $clientOrderId; // Généré unique

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $presetMode; // none, preset_on_entry, position_tp_sl

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $quantization = []; // tick_size, step_size, min_notional, price_precision, vol_precision, etc.

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    private ?array $rawInputs = null; // Données brutes avant normalisation

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    private ?array $validationErrors = null; // Erreurs de validation

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $orderId = null; // Order ID de l'exchange après envoi

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $failureReason = null; // Raison de l'échec

    #[ORM\OneToMany(targetEntity: OrderProtection::class, mappedBy: 'orderIntent', cascade: ['persist', 'remove'])]
    private Collection $protections;

    #[ORM\ManyToOne(targetEntity: OrderPlan::class)]
    #[ORM\JoinColumn(name: 'order_plan_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?OrderPlan $orderPlan = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct()
    {
        $this->protections = new ArrayCollection();
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

    public function getSide(): int
    {
        return $this->side;
    }

    public function setSide(int $side): self
    {
        $this->side = $side;
        return $this->touch();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = strtolower($type);
        return $this->touch();
    }

    public function getOpenType(): string
    {
        return $this->openType;
    }

    public function setOpenType(string $openType): self
    {
        $this->openType = strtolower($openType);
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

    public function getPositionMode(): string
    {
        return $this->positionMode;
    }

    public function setPositionMode(string $positionMode): self
    {
        $this->positionMode = strtolower($positionMode);
        return $this->touch();
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): self
    {
        $this->price = $price;
        return $this->touch();
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;
        return $this->touch();
    }

    public function getClientOrderId(): string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId;
        return $this->touch();
    }

    public function getPresetMode(): string
    {
        return $this->presetMode;
    }

    public function setPresetMode(string $presetMode): self
    {
        $this->presetMode = strtolower($presetMode);
        return $this->touch();
    }

    /**
     * @return array<string,mixed>
     */
    public function getQuantization(): array
    {
        return $this->quantization;
    }

    /**
     * @param array<string,mixed> $quantization
     */
    public function setQuantization(array $quantization): self
    {
        $this->quantization = $quantization;
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

    /**
     * @return array<string,mixed>|null
     */
    public function getRawInputs(): ?array
    {
        return $this->rawInputs;
    }

    /**
     * @param array<string,mixed>|null $rawInputs
     */
    public function setRawInputs(?array $rawInputs): self
    {
        $this->rawInputs = $rawInputs;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getValidationErrors(): ?array
    {
        return $this->validationErrors;
    }

    /**
     * @param array<string,mixed>|null $validationErrors
     */
    public function setValidationErrors(?array $validationErrors): self
    {
        $this->validationErrors = $validationErrors;
        return $this->touch();
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId;
        return $this->touch();
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): self
    {
        $this->failureReason = $failureReason;
        return $this->touch();
    }

    /**
     * @return Collection<int, OrderProtection>
     */
    public function getProtections(): Collection
    {
        return $this->protections;
    }

    public function addProtection(OrderProtection $protection): self
    {
        if (!$this->protections->contains($protection)) {
            $this->protections->add($protection);
            $protection->setOrderIntent($this);
        }
        return $this->touch();
    }

    public function removeProtection(OrderProtection $protection): self
    {
        if ($this->protections->removeElement($protection)) {
            if ($protection->getOrderIntent() === $this) {
                $protection->setOrderIntent(null);
            }
        }
        return $this->touch();
    }

    public function getOrderPlan(): ?OrderPlan
    {
        return $this->orderPlan;
    }

    public function setOrderPlan(?OrderPlan $orderPlan): self
    {
        $this->orderPlan = $orderPlan;
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

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this->touch();
    }

    // Méthodes utilitaires pour les statuts

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    public function isReadyToSend(): bool
    {
        return $this->status === self::STATUS_READY_TO_SEND;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function markAsValidated(): self
    {
        return $this->setStatus(self::STATUS_VALIDATED);
    }

    public function markAsReadyToSend(): self
    {
        return $this->setStatus(self::STATUS_READY_TO_SEND);
    }

    public function markAsSent(?string $orderId = null): self
    {
        $this->setStatus(self::STATUS_SENT);
        if ($orderId !== null) {
            $this->setOrderId($orderId);
        }
        $this->setSentAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return $this->touch();
    }

    public function markAsFailed(string $reason): self
    {
        $this->setStatus(self::STATUS_FAILED);
        $this->setFailureReason($reason);
        return $this->touch();
    }

    public function markAsCancelled(): self
    {
        return $this->setStatus(self::STATUS_CANCELLED);
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }
}

