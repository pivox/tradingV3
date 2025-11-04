<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderProtectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderProtectionRepository::class)]
#[ORM\Table(name: 'order_protection')]
#[ORM\Index(name: 'idx_order_protection_order_intent', columns: ['order_intent_id'])]
#[ORM\Index(name: 'idx_order_protection_type', columns: ['type'])]
class OrderProtection
{
    public const TYPE_TAKE_PROFIT = 'take_profit';
    public const TYPE_STOP_LOSS = 'stop_loss';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrderIntent::class, inversedBy: 'protections')]
    #[ORM\JoinColumn(name: 'order_intent_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private OrderIntent $orderIntent;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type; // take_profit, stop_loss

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12)]
    private string $price; // Prix de déclenchement

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $size = null; // Taille (nombre de contrats), null = même taille que l'ordre principal

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $priceType = null; // 1 = prix fixe, 2 = dernier prix, etc.

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $orderId = null; // Order ID de l'exchange après envoi

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $clientOrderId = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    private ?array $metadata = null; // Données supplémentaires

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
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

    public function getOrderIntent(): OrderIntent
    {
        return $this->orderIntent;
    }

    public function setOrderIntent(?OrderIntent $orderIntent): self
    {
        $this->orderIntent = $orderIntent;
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

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this->touch();
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;
        return $this->touch();
    }

    public function getPriceType(): ?int
    {
        return $this->priceType;
    }

    public function setPriceType(?int $priceType): self
    {
        $this->priceType = $priceType;
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

    public function getClientOrderId(): ?string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(?string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string,mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
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

    public function isTakeProfit(): bool
    {
        return $this->type === self::TYPE_TAKE_PROFIT;
    }

    public function isStopLoss(): bool
    {
        return $this->type === self::TYPE_STOP_LOSS;
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }
}

