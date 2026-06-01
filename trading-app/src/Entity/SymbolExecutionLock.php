<?php

declare(strict_types=1);

namespace App\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Repository\SymbolExecutionLockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SymbolExecutionLockRepository::class)]
#[ORM\Table(name: 'symbol_execution_lock')]
#[ORM\Index(name: 'idx_symbol_execution_lock_active', columns: ['exchange', 'market_type', 'symbol', 'released_at'])]
#[ORM\Index(name: 'idx_symbol_execution_lock_owner_intent', columns: ['owner_order_intent_id'])]
final class SymbolExecutionLock
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_RELEASED = 'RELEASED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $exchange;

    #[ORM\Column(name: 'market_type', type: Types::STRING, length: 32)]
    private string $marketType;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 24)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'owner_profile', type: Types::STRING, length: 80, nullable: true)]
    private ?string $ownerProfile = null;

    #[ORM\Column(name: 'owner_decision_key', type: Types::STRING, length: 255, nullable: true)]
    private ?string $ownerDecisionKey = null;

    #[ORM\ManyToOne(targetEntity: OrderIntent::class)]
    #[ORM\JoinColumn(name: 'owner_order_intent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?OrderIntent $ownerOrderIntent = null;

    #[ORM\Column(name: 'locked_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lockedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'released_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(name: 'release_reason', type: Types::STRING, length: 120, nullable: true)]
    private ?string $releaseReason = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Exchange|string $exchange,
        MarketType|string $marketType,
        string $symbol,
        ?OrderIntent $ownerOrderIntent = null,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->setExchange($exchange);
        $this->setMarketType($marketType);
        $this->symbol = strtoupper(trim($symbol));
        $this->ownerOrderIntent = $ownerOrderIntent;
        $this->lockedAt = $now;
        $this->expiresAt = $expiresAt ?? $now->modify('+900 seconds');
        $this->createdAt = $now;
        $this->updatedAt = $now;

        if ($ownerOrderIntent instanceof OrderIntent) {
            $this->ownerProfile = $ownerOrderIntent->getStrategyProfile();
            $this->ownerDecisionKey = $ownerOrderIntent->getDecisionKey();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function setExchange(Exchange|string $exchange): self
    {
        $this->exchange = $exchange instanceof Exchange ? $exchange->value : strtolower(trim($exchange));

        return $this->touch();
    }

    public function getMarketType(): string
    {
        return $this->marketType;
    }

    public function setMarketType(MarketType|string $marketType): self
    {
        $this->marketType = $marketType instanceof MarketType ? $marketType->value : strtolower(trim($marketType));

        return $this->touch();
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getOwnerProfile(): ?string
    {
        return $this->ownerProfile;
    }

    public function getOwnerDecisionKey(): ?string
    {
        return $this->ownerDecisionKey;
    }

    public function getOwnerOrderIntent(): ?OrderIntent
    {
        return $this->ownerOrderIntent;
    }

    public function getOwnerOrderIntentId(): ?int
    {
        return $this->ownerOrderIntent?->getId();
    }

    public function getLockedAt(): \DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function getReleaseReason(): ?string
    {
        return $this->releaseReason;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this->touch();
    }

    public function activeKey(): string
    {
        return sprintf('%s:%s:%s', $this->exchange, $this->marketType, $this->symbol);
    }

    public function isActive(): bool
    {
        return $this->releasedAt === null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->expiresAt <= $now;
    }

    public function release(string $reason): self
    {
        if ($this->releasedAt !== null) {
            return $this;
        }

        $this->status = self::STATUS_RELEASED;
        $this->releaseReason = substr(trim($reason), 0, 120) ?: 'released';
        $this->releasedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->touch();
    }

    private function touch(): self
    {
        if (!isset($this->updatedAt)) {
            return $this;
        }

        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }
}
