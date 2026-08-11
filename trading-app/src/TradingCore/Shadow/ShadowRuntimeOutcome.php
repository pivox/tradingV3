<?php

declare(strict_types=1);

namespace App\TradingCore\Shadow;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;

final readonly class ShadowRuntimeOutcome
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $status,
        public string $reasonCode,
        public LineageContext $lineage,
        public ?CanonicalOrderPlan $orderPlan,
        public ?CanonicalPortfolioReservation $reservation,
        public array $evidence,
    ) {
        if (!\in_array($status, ['planned', 'no_trade'], true)) {
            throw new \InvalidArgumentException('shadow_runtime_status_invalid');
        }
        $validShape = $status === 'planned'
            ? $orderPlan !== null && $reservation !== null
            : $orderPlan === null && $reservation === null;
        if (!$validShape) {
            throw new \InvalidArgumentException('shadow_runtime_outcome_shape_invalid');
        }
    }
}
