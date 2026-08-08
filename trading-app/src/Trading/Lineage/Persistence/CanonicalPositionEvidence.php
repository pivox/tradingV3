<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Persistence;

final readonly class CanonicalPositionEvidence
{
    public function __construct(
        public ?string $exchangePositionId = null,
        public ?string $exchangeOrderId = null,
        public ?string $clientOrderId = null,
        public ?string $exchangeFillId = null,
    ) {}

    public function hasAnyIdentifier(): bool
    {
        return $this->exchangePositionId !== null
            || $this->exchangeOrderId !== null
            || $this->clientOrderId !== null
            || $this->exchangeFillId !== null;
    }
}
