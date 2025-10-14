<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Dto;

class ConfidenceMultiplierConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly float $default,
        public readonly float $whenUpstreamStale,
        public readonly float $whenTieBreakerUsed
    ) {
    }
}




