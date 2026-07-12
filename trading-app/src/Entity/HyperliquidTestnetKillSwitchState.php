<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HyperliquidTestnetKillSwitchStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HyperliquidTestnetKillSwitchStateRepository::class)]
#[ORM\Table(name: 'hyperliquid_testnet_kill_switch_state')]
final class HyperliquidTestnetKillSwitchState
{
    public const SCOPE = 'hyperliquid_testnet';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $scope;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $tripped;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $reason;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'audit_context', type: Types::JSON, options: ['jsonb' => true])]
    private array $auditContext;

    #[ORM\Column(name: 'tripped_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $trippedAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $auditContext */
    public function __construct(string $reason, array $auditContext, \DateTimeImmutable $now)
    {
        $this->scope = self::SCOPE;
        $this->tripped = true;
        $this->reason = $reason;
        $this->auditContext = $auditContext;
        $this->trippedAt = $now;
        $this->updatedAt = $now;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function isTripped(): bool
    {
        return $this->tripped;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function getAuditContext(): array
    {
        return $this->auditContext;
    }

    public function getTrippedAt(): \DateTimeImmutable
    {
        return $this->trippedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
