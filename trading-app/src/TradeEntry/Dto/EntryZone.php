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
        if ($price >= $this->min && $price <= $this->max) {
            return true;
        }

        $tolerance = $this->metadata['outside_tolerance_pct'] ?? null;
        if (!\is_numeric($tolerance)) {
            return false;
        }
        $tolerance = (float)$tolerance;
        if ($tolerance <= 0.0 || $price <= 0.0) {
            return false;
        }
        if ($tolerance > 1.0) {
            $tolerance *= 0.01;
        }
        $tolerance = min($tolerance, 1.0);

        if ($price > $this->max) {
            return (($price - $this->max) / $price) <= $tolerance;
        }
        if ($price < $this->min) {
            return (($this->min - $price) / $price) <= $tolerance;
        }

        return false;
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
