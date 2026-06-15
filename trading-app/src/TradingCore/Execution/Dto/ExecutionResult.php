<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Dto;

use App\TradingCore\Execution\Enum\ExecutionStatus;

final readonly class ExecutionResult
{
    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public ExecutionStatus $status,
        public ?string $clientOrderId = null,
        public ?string $exchangeOrderId = null,
        public array $raw = [],
        public array $metadata = [],
    ) {
    }
}
