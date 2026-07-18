<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeFundingDto;

final readonly class FakeFundingResult
{
    public function __construct(
        public string $status,
        public ?ExchangeFundingDto $funding,
        public bool $replayed = false,
    ) {
    }
}
