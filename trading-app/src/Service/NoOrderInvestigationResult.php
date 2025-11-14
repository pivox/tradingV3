<?php

declare(strict_types=1);

namespace App\Service;

/**
 * @phpstan-type InvestigationDetails array<string,mixed>
 */
final class NoOrderInvestigationResult
{
    /**
     * @param InvestigationDetails $details
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly array $details
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'details' => $this->details,
        ];
    }
}
