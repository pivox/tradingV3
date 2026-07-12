<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HyperliquidTestnetExecutionAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HyperliquidTestnetExecutionAttemptRepository::class)]
#[ORM\Table(name: 'hyperliquid_testnet_execution_attempt')]
#[ORM\UniqueConstraint(name: 'hl_testnet_execution_attempt_active_uniq', columns: ['scope', 'active_slot'])]
final class HyperliquidTestnetExecutionAttempt
{
    #[ORM\Id]
    #[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $scope;

    #[ORM\Column(name: 'active_slot', type: Types::SMALLINT, nullable: true)]
    private ?int $activeSlot;

    #[ORM\Column(name: 'plan_fingerprint', type: Types::STRING, length: 64)]
    private string $planFingerprint;

    #[ORM\Column(name: 'client_order_id', type: Types::STRING, length: 128)]
    private string $clientOrderId;

    #[ORM\Column(name: 'correlation_id', type: Types::STRING, length: 128)]
    private string $correlationId;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $state;

    /** @var null|array<string, mixed> */
    #[ORM\Column(name: 'result_payload', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $resultPayload = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param null|array<string, mixed> $resultPayload */
    public function __construct(
        string $idempotencyKey,
        string $scope,
        ?int $activeSlot,
        string $planFingerprint,
        string $clientOrderId,
        string $correlationId,
        string $state,
        ?array $resultPayload,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->idempotencyKey = $idempotencyKey;
        $this->scope = $scope;
        $this->activeSlot = $activeSlot;
        $this->planFingerprint = $planFingerprint;
        $this->clientOrderId = $clientOrderId;
        $this->correlationId = $correlationId;
        $this->state = $state;
        $this->resultPayload = $resultPayload;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getPlanFingerprint(): string
    {
        return $this->planFingerprint;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getActiveSlot(): ?int
    {
        return $this->activeSlot;
    }

    public function getClientOrderId(): string
    {
        return $this->clientOrderId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    /** @return null|array<string, mixed> */
    public function getResultPayload(): ?array
    {
        return $this->resultPayload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
