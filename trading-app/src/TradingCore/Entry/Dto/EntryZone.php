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
        if ($price >= $this->low && $price <= $this->high) {
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

        if ($price > $this->high) {
            return (($price - $this->high) / $price) <= $tolerance;
        }
        if ($price < $this->low) {
            return (($this->low - $price) / $price) <= $tolerance;
        }

        return false;
    }
}
