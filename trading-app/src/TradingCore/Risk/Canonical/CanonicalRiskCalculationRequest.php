<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

final readonly class CanonicalRiskCalculationRequest
{
    public const MIN_QUANTITY_STEP = 1.0e-12;
    public const MAX_QUANTITY_DECIMALS = 12;

    public function __construct(
        public CanonicalRiskPolicy $policy,
        public string $symbol,
        public string $marketType,
        public string $side,
        public float $equityQuote,
        public float $availableBalanceQuote,
        public float $entryPrice,
        public float $stopPrice,
        public float $contractSize,
        public float $quantityStep,
        public float $minQuantity,
        public float $maxQuantity,
        public ?float $marketMaxQuantity,
        public float $exchangeLeverageCap,
        public ?float $symbolLeverageCap,
        public CanonicalCostSnapshot $costs,
    ) {
        if (trim($symbol) === '') {
            throw new CanonicalRiskException('canonical_risk_symbol_invalid');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $marketType) !== 1) {
            throw new CanonicalRiskException('canonical_risk_market_type_invalid');
        }
        if (!\in_array($side, ['long', 'short'], true)) {
            throw new CanonicalRiskException('canonical_risk_side_invalid');
        }
        if ($side !== $policy->side) {
            throw new CanonicalRiskException('canonical_policy_identity_mismatch');
        }
        if (!\is_finite($equityQuote) || $equityQuote <= 0.0) {
            throw new CanonicalRiskException('canonical_risk_equity_invalid');
        }
        if (!\is_finite($availableBalanceQuote) || $availableBalanceQuote < 0.0) {
            throw new CanonicalRiskException('canonical_risk_available_balance_invalid');
        }
        if (!\is_finite($entryPrice) || !\is_finite($stopPrice) || $entryPrice <= 0.0 || $stopPrice <= 0.0) {
            throw new CanonicalRiskException('canonical_risk_price_invalid');
        }
        if (($side === 'long' && $stopPrice >= $entryPrice) || ($side === 'short' && $stopPrice <= $entryPrice)) {
            throw new CanonicalRiskException('canonical_risk_stop_side_invalid');
        }
        if (!\is_finite($contractSize) || $contractSize <= 0.0) {
            throw new CanonicalRiskException('canonical_risk_contract_size_invalid');
        }
        if (
            !\is_finite($quantityStep)
            || $quantityStep < self::MIN_QUANTITY_STEP
            || round($quantityStep, self::MAX_QUANTITY_DECIMALS) !== $quantityStep
        ) {
            throw new CanonicalRiskException('canonical_risk_quantity_step_invalid');
        }
        if (
            !\is_finite($minQuantity)
            || !\is_finite($maxQuantity)
            || $minQuantity < $quantityStep
            || $maxQuantity < $minQuantity
            || ($marketMaxQuantity !== null && (!\is_finite($marketMaxQuantity) || $marketMaxQuantity < $minQuantity))
        ) {
            throw new CanonicalRiskException('canonical_risk_quantity_bounds_invalid');
        }
        if (
            !\is_finite($exchangeLeverageCap)
            || $exchangeLeverageCap < 1.0
            || ($symbolLeverageCap !== null && (!\is_finite($symbolLeverageCap) || $symbolLeverageCap < 1.0))
        ) {
            throw new CanonicalRiskException('canonical_leverage_cap_invalid');
        }
    }
}
