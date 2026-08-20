<?php

declare(strict_types=1);

namespace App\TradingCore\MicroScalping;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;

final readonly class MicroScalpingShadowOutcome
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
            throw new \InvalidArgumentException('micro_scalping_shadow_status_invalid');
        }
        $validShape = $status === 'planned'
            ? $orderPlan !== null && $reservation !== null
            : $orderPlan === null && $reservation === null;
        if (!$validShape) {
            throw new \InvalidArgumentException('micro_scalping_shadow_outcome_shape_invalid');
        }
    }
}
