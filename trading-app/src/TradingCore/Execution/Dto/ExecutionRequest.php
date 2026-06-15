<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Dto;

use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\OrderPlan\Dto\OrderPlan;

final readonly class ExecutionRequest
{
    /**
     * @param array<string,mixed> $metadata
     */
    private function __construct(
        public OrderPlan $orderPlan,
        public ExecutionMode $mode,
        public \DateTimeImmutable $requestedAt,
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function forPlan(
        OrderPlan $orderPlan,
        ExecutionMode $mode,
        array $metadata = [],
        ?\DateTimeImmutable $requestedAt = null,
    ): self {
        if ($mode === ExecutionMode::Live && !$orderPlan->validation->isExecutable) {
            throw new \InvalidArgumentException('Live execution requires an executable order plan.');
        }

        return new self(
            orderPlan: $orderPlan,
            mode: $mode,
            requestedAt: $requestedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            metadata: $metadata,
        );
    }
}
