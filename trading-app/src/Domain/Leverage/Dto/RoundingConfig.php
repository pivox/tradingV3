<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Dto;

class RoundingConfig
{
    public function __construct(
        public readonly int $precision,
        public readonly string $mode
    ) {
    }
}




