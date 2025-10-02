<?php

namespace App\Entity;

use App\Repository\UserExchangeAccountRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserExchangeAccountRepository::class)]
#[ORM\Table(name: 'user_exchange_account')]
#[ORM\UniqueConstraint(name: 'uniq_user_exchange', columns: ['user_id', 'exchange'])]
#[ORM\HasLifecycleCallbacks]
class UserExchangeAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: 'string', length: 120)]
    private string $userId;

    #[ORM\Column(type: 'string', length: 60)]
    private string $exchange;

    #[ORM\Column(name: 'last_balance_sync_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastBalanceSyncAt = null;

    #[ORM\Column(name: 'last_order_sync_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastOrderSyncAt = null;

    #[ORM\Column(name: 'available_balance', type: 'decimal', precision: 20, scale: 8, nullable: true)]
    private ?string $availableBalance = null;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 8, nullable: true)]
    private ?string $balance = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function setExchange(string $exchange): self
    {
        $this->exchange = strtolower($exchange);
        return $this;
    }

    public function getLastBalanceSyncAt(): ?DateTimeImmutable
    {
        return $this->lastBalanceSyncAt;
    }

    public function setLastBalanceSyncAt(?DateTimeImmutable $lastBalanceSyncAt): self
    {
        $this->lastBalanceSyncAt = $lastBalanceSyncAt;
        return $this;
    }

    public function getLastOrderSyncAt(): ?DateTimeImmutable
    {
        return $this->lastOrderSyncAt;
    }

    public function setLastOrderSyncAt(?DateTimeImmutable $lastOrderSyncAt): self
    {
        $this->lastOrderSyncAt = $lastOrderSyncAt;
        return $this;
    }

    public function getAvailableBalance(): ?float
    {
        return $this->availableBalance !== null ? (float)$this->availableBalance : null;
    }

    public function setAvailableBalance(?float $availableBalance): self
    {
        $this->availableBalance = $availableBalance !== null ? number_format($availableBalance, 8, '.', '') : null;
        return $this;
    }

    public function getBalance(): ?float
    {
        return $this->balance !== null ? (float)$this->balance : null;
    }

    public function setBalance(?float $balance): self
    {
        $this->balance = $balance !== null ? number_format($balance, 8, '.', '') : null;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}

