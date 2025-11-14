<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class EntryZone
{
    public function __construct(
        public readonly float $min,
        public readonly float $max,
        public readonly string $rationale = '',
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?int $ttlSec = null,
        /** @var array<string,mixed> */
        public readonly array $metadata = [],
    ) {}

    public function contains(float $price): bool
    {
        return $price >= $this->min && $price <= $this->max;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTtlSec(): ?int
    {
        return $this->ttlSec;
    }

    public function getTtlRemainingSec(?\DateTimeImmutable $now = null): ?int
    {
        if ($this->ttlSec === null || $this->createdAt === null) {
            return null;
        }
        $now = $now ?? new \DateTimeImmutable();
        $elapsed = max(0, $now->getTimestamp() - $this->createdAt->getTimestamp());
        return max(0, $this->ttlSec - $elapsed);
    }

    /**
     * @return array<string,mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
