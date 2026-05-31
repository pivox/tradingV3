<?php

declare(strict_types=1);

namespace App\TradeEntry\Execution;

final readonly class ProtectionEnforcementResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $status,
        public bool $protected,
        public ?string $protectionOrderId = null,
        public ?string $emergencyOrderId = null,
        public array $metadata = [],
    ) {
    }
}
