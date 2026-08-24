<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

final readonly class PaperCanonicalStrategyPreparationResult
{
    private function __construct(
        public string $status,
        public string $reasonCode,
        public ?PaperCanonicalStrategyDecision $decision,
    ) {
        if (!in_array($status, ['planned', 'no_trade', 'missing_evidence'], true)
            || preg_match('/\A[a-z][a-z0-9_]{2,127}\z/D', $reasonCode) !== 1
            || (($status === 'planned') !== ($decision !== null))
        ) {
            throw new \InvalidArgumentException('paper_canonical_strategy_preparation_result_invalid');
        }
    }

    public static function planned(string $reasonCode, PaperCanonicalStrategyDecision $decision): self
    {
        return new self('planned', $reasonCode, $decision);
    }

    public static function noTrade(string $reasonCode): self
    {
        return new self('no_trade', $reasonCode, null);
    }

    public static function missingEvidence(string $reasonCode): self
    {
        return new self('missing_evidence', $reasonCode, null);
    }
}
