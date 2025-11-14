<?php

declare(strict_types=1);

namespace App\Logging\Dto;

final class PositionsLogScanResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly ?string $status,
        public readonly ?string $reason,
        public readonly array $details
    ) {
    }
}
