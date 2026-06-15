<?php
declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Dto;

use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;

final readonly class OrderPlanValidationResult
{
    /**
     * @param list<string> $invalidReasons
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public OrderPlanStatus $status,
        public bool $isExecutable,
        public array $invalidReasons = [],
        public array $warnings = [],
        public array $metadata = [],
    ) {
    }
}
