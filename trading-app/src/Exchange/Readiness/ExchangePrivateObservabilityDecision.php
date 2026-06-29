<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

final readonly class ExchangePrivateObservabilityDecision
{
    /**
     * @param list<string> $blockingErrors
     * @param list<string> $warnings
     */
    public function __construct(
        public bool $allowed,
        public ExchangePrivateObservabilityStatus $status,
        public array $blockingErrors,
        public array $warnings,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'blocking_errors' => $this->blockingErrors,
            'warnings' => $this->warnings,
            'status' => $this->status->toArray(),
        ];
    }
}
