<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalTargetCostSnapshot
{
    public function __construct(
        public string $targetId,
        public ?string $spreadSource,
        public ?float $spreadRate,
        public ?string $slippageSource,
        public ?float $slippageRate,
    ) {
        if ($spreadSource === null || $slippageSource === null || $spreadRate === null || $slippageRate === null) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_unknown', ['target_id' => $targetId]);
        }
        if (trim($targetId) === '' || trim($spreadSource) === '' || trim($slippageSource) === '') {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_invalid');
        }
        foreach ([$spreadRate, $slippageRate] as $rate) {
            if (!\is_finite($rate) || $rate < 0.0 || $rate >= 1.0) {
                throw new CanonicalOrderPlanException('canonical_net_r_cost_invalid');
            }
        }
    }
}
