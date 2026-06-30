<?php

declare(strict_types=1);

namespace App\Entity;

use App\Provider\Hyperliquid\HyperliquidNonceScope;
use App\Repository\HyperliquidNonceStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HyperliquidNonceStateRepository::class)]
#[ORM\Table(name: 'hyperliquid_nonce_state')]
#[ORM\UniqueConstraint(name: 'ux_hyperliquid_nonce_signer_scope', columns: ['environment', 'network', 'signer_address'])]
#[ORM\Index(name: 'idx_hyperliquid_nonce_account', columns: ['environment', 'network', 'account_address'])]
#[ORM\Index(name: 'idx_hyperliquid_nonce_updated_at', columns: ['updated_at'])]
final class HyperliquidNonceState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $environment;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $network;

    #[ORM\Column(name: 'account_address', type: Types::STRING, length: 128)]
    private string $accountAddress;

    #[ORM\Column(name: 'signer_address', type: Types::STRING, length: 128)]
    private string $signerAddress;

    #[ORM\Column(name: 'last_nonce', type: Types::BIGINT)]
    private int $lastNonce;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(HyperliquidNonceScope $scope, int $lastNonce, \DateTimeImmutable $now)
    {
        $this->environment = $scope->environment;
        $this->network = $scope->network;
        $this->accountAddress = $scope->accountAddress;
        $this->signerAddress = $scope->signerAddress;
        $this->lastNonce = $lastNonce;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function getAccountAddress(): string
    {
        return $this->accountAddress;
    }

    public function getSignerAddress(): string
    {
        return $this->signerAddress;
    }

    public function getLastNonce(): int
    {
        return $this->lastNonce;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function advanceTo(int $nonce, \DateTimeImmutable $now): void
    {
        if ($nonce <= $this->lastNonce) {
            return;
        }

        $this->lastNonce = $nonce;
        $this->updatedAt = $now;
    }
}
