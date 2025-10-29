<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class EntryZone
{
    public function __construct(
        public readonly float $min,
        public readonly float $max,
        public readonly string $rationale = ''
    ) {}

    public function contains(float $price): bool
    {
        return $price >= $this->min && $price <= $this->max;
    }
}
