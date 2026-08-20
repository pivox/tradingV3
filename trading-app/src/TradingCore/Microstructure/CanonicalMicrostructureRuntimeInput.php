<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use App\TradingCore\Rules\Evaluation\RuleInputSnapshot;
use App\TradingCore\Rules\Evaluation\RuleMarketIdentity;

final readonly class CanonicalMicrostructureRuntimeInput
{
    /** @param array<string, mixed> $trace */
    public function __construct(
        public string $status,
        public ?RuleInputSnapshot $ruleInput,
        public ?RuleMarketIdentity $marketIdentity,
        public array $trace,
    ) {
        if (!\in_array($status, [
            'not_required',
            'identity_unavailable',
            'provider_unavailable',
            'input_unavailable',
            'input_rejected',
            'input_stale',
            'identity_mismatch',
            'ready',
        ], true)
            || ($status === 'ready' && ($ruleInput === null || $marketIdentity === null))
            || ($status !== 'ready' && $ruleInput !== null)
            || ($trace['status'] ?? null) !== $status
        ) {
            throw new \InvalidArgumentException('canonical_microstructure_runtime_input_invalid');
        }
    }
}
