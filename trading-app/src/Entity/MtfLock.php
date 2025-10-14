<?php

declare(strict_types=1);

namespace App\Entity;

use App\Infrastructure\Doctrine\Type\PostgresTimestampType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mtf_lock')]
class MtfLock
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $lockKey;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $processId;

    #[ORM\Column(type: 'postgres_timestamp')]
    private \DateTimeImmutable $acquiredAt;

    #[ORM\Column(type: 'postgres_timestamp', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metadata = null;

    public function __construct(
        string $lockKey,
        string $processId,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $metadata = null
    ) {
        $this->lockKey = $lockKey;
        $this->processId = $processId;
        $this->acquiredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->expiresAt = $expiresAt;
        $this->metadata = $metadata;
    }

    public function getLockKey(): string
    {
        return $this->lockKey;
    }

    public function getProcessId(): string
    {
        return $this->processId;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getMetadata(): ?string
    {
        return $this->metadata;
    }

    public function setMetadata(?string $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getDuration(): int
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $this->acquiredAt->getTimestamp();
    }
}




