<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final class CanonicalOrderPlanException extends \RuntimeException
{
    /** @param array<string, int|float|string|bool|null> $evidence */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $evidence = [],
    ) {
        parent::__construct($reasonCode);
    }
}
