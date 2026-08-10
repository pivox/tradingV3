<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

final readonly class CanonicalPortfolioSnapshot
{
    /** @param list<string> $activeDecisionKeys */
    public function __construct(
        public CanonicalPortfolioScope $scope,
        public string $source,
        public string $sourceVersion,
        public \DateTimeImmutable $policyDayStart,
        public \DateTimeImmutable $policyDayEnd,
        public \DateTimeImmutable $observedAt,
        public float $equityQuote,
        public float $realizedNetPnlQuote,
        public float $unrealizedNetPnlQuote,
        public int $openPositions,
        public int $pendingEntries,
        public float $openNotionalQuote,
        public float $pendingNotionalQuote,
        public float $reservedRiskQuote,
        public array $activeDecisionKeys,
        public int $stateVersion,
        public string $inputHash,
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9_.:-]*\z/D', $source) !== 1
            || preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $sourceVersion) !== 1
            || $policyDayStart >= $policyDayEnd
            || $observedAt < $policyDayStart
            || $observedAt >= $policyDayEnd
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_state_day_invalid');
        }
        foreach ([$equityQuote, $realizedNetPnlQuote, $unrealizedNetPnlQuote, $openNotionalQuote, $pendingNotionalQuote, $reservedRiskQuote] as $value) {
            if (!\is_finite($value)) {
                throw new CanonicalPortfolioException('canonical_portfolio_state_value_invalid');
            }
        }
        if (
            $equityQuote <= 0.0
            || $openPositions < 0
            || $pendingEntries < 0
            || $openNotionalQuote < 0.0
            || $pendingNotionalQuote < 0.0
            || $reservedRiskQuote < 0.0
            || $stateVersion < 1
            || $stateVersion === PHP_INT_MAX
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_state_value_invalid');
        }
        if (count($activeDecisionKeys) !== count(array_unique($activeDecisionKeys))) {
            throw new CanonicalPortfolioException('canonical_portfolio_state_decision_keys_invalid');
        }
        foreach ($activeDecisionKeys as $decisionKey) {
            if (!\is_string($decisionKey) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $decisionKey) !== 1) {
                throw new CanonicalPortfolioException('canonical_portfolio_state_decision_keys_invalid');
            }
        }
    }
}
