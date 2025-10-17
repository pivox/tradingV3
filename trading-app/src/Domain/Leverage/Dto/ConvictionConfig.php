<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Dto;

class ConvictionConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly float $capPctOfExchange
    ) {
    }
}




