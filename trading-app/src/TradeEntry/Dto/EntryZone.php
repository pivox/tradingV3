<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class EntryZone
{
    public function __construct(
        public float $low,
        public float $high,
        public \DateTimeImmutable $expiresAt
    ) {}

    public function isValid(): bool
    {
        return $this->low <= $this->high && (new \DateTimeImmutable()) < $this->expiresAt;
    }

    public function clampToTick(float $tick): self
    {
        $round = static fn(float $p) => floor($p / $tick) * $tick;
        return new self($round($this->low), $round($this->high), $this->expiresAt);
    }
}
