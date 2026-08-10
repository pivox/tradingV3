<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalExecutionCostSnapshot
{
    /** @param list<CanonicalTargetCostSnapshot> $targets */
    public function __construct(
        public string $exchange,
        public string $environment,
        public string $symbol,
        public string $marketType,
        public string $configHash,
        public ?string $entryLiquidityRole,
        public ?string $stopLiquidityRole,
        public ?string $entrySpreadSource,
        public ?float $entrySpreadRate,
        public ?string $entrySlippageSource,
        public ?float $entrySlippageRate,
        public ?string $stopSpreadSource,
        public ?float $stopSpreadRate,
        public ?string $stopSlippageSource,
        public ?float $stopSlippageRate,
        public ?string $fundingSource,
        public ?float $fundingRate,
        public array $targets,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
        if (
            trim($exchange) === ''
            || trim($environment) === ''
            || preg_match('/\A[A-Z0-9][A-Z0-9_.-]*\z/D', $symbol) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $marketType) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $configHash) !== 1
        ) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_identity_invalid');
        }
        foreach ([
            $entryLiquidityRole,
            $stopLiquidityRole,
            $entrySpreadSource,
            $entrySpreadRate,
            $entrySlippageSource,
            $entrySlippageRate,
            $stopSpreadSource,
            $stopSpreadRate,
            $stopSlippageSource,
            $stopSlippageRate,
            $fundingSource,
            $fundingRate,
        ] as $value) {
            if ($value === null) {
                throw new CanonicalOrderPlanException('canonical_net_r_cost_unknown');
            }
        }
        foreach ([$entryLiquidityRole, $stopLiquidityRole] as $role) {
            if (!\in_array($role, ['maker', 'taker'], true)) {
                throw new CanonicalOrderPlanException('canonical_net_r_liquidity_role_invalid');
            }
        }
        foreach ([$entrySpreadRate, $entrySlippageRate, $stopSpreadRate, $stopSlippageRate] as $rate) {
            if (!\is_finite($rate) || $rate < 0.0 || $rate >= 1.0) {
                throw new CanonicalOrderPlanException('canonical_net_r_cost_invalid');
            }
        }
        if (!\is_finite($fundingRate) || $fundingRate <= -1.0 || $fundingRate >= 1.0 || $targets === []) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_invalid');
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_hash_invalid');
        }
    }
}
