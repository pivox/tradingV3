<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Dto;

final readonly class ExecutionResult
{
    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $status,
        public ?string $clientOrderId = null,
        public ?string $exchangeOrderId = null,
        public array $raw = [],
        public array $metadata = [],
    ) {
    }
}
