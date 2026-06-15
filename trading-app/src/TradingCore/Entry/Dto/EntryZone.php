<?php
declare(strict_types=1);

namespace App\TradingCore\Entry\Dto;

final readonly class EntryZone
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public float $low,
        public float $high,
        public float $center,
        public float $widthPct,
        public ?int $ttlSec,
        public ?\DateTimeImmutable $expiresAt,
        public string $source,
        public ?float $atrUsed,
        public bool $quantized,
        public array $metadata = [],
    ) {}

    public function contains(float $price): bool
    {
        return $price >= $this->low && $price <= $this->high;
    }
}
