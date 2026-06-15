<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Dto;

use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;

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
        $validatedPlan = $orderPlan;
        if ($mode === ExecutionMode::Live) {
            $validatedPlan = $orderPlan->withValidation((new OrderPlanValidator())->validate($orderPlan));
        }

        if ($mode === ExecutionMode::Live && !$validatedPlan->validation->isExecutable) {
            throw new \InvalidArgumentException('Live execution requires an executable order plan.');
        }

        return new self(
            orderPlan: $validatedPlan,
            mode: $mode,
            requestedAt: $requestedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            metadata: $metadata,
        );
    }
}
