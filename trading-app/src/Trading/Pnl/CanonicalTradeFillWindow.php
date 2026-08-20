<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

final readonly class CanonicalTradeFillWindow
{
    public function __construct(
        public \DateTimeImmutable $entryFirstFillAt,
        public \DateTimeImmutable $exitLastFillAt,
        public float $entryVwap,
    ) {
        if ($this->exitLastFillAt < $this->entryFirstFillAt) {
            throw new \InvalidArgumentException('Canonical fill window exit cannot precede entry.');
        }
        if (!is_finite($this->entryVwap) || $this->entryVwap <= 0.0) {
            throw new \InvalidArgumentException('Canonical fill window entry VWAP must be positive and finite.');
        }
    }

    public function holdingTimeSeconds(): int|float
    {
        $wholeSeconds = $this->exitLastFillAt->getTimestamp() - $this->entryFirstFillAt->getTimestamp();
        $microseconds = (int) $this->exitLastFillAt->format('u') - (int) $this->entryFirstFillAt->format('u');

        return $microseconds === 0 ? $wholeSeconds : $wholeSeconds + ($microseconds / 1_000_000);
    }
}
