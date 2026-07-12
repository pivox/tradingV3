<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\TradingCore\Execution\Dto\ExecutionResult;

final readonly class HyperliquidExecutionAttemptClaim
{
    public const CLAIMED = 'claimed';
    public const ACTIVE_REPLAY = 'active_replay';
    public const GLOBAL_ACTIVE = 'global_active';
    public const TERMINAL_REPLAY = 'terminal_replay';
    public const CONFLICT = 'conflict';

    public function __construct(
        public string $outcome,
        public ?ExecutionResult $result = null,
    ) {
        if (!in_array($outcome, [self::CLAIMED, self::ACTIVE_REPLAY, self::GLOBAL_ACTIVE, self::TERMINAL_REPLAY, self::CONFLICT], true)) {
            throw new \InvalidArgumentException('hyperliquid_execution_attempt_claim_invalid');
        }
        if (($outcome === self::TERMINAL_REPLAY) !== ($result instanceof ExecutionResult)) {
            throw new \InvalidArgumentException('hyperliquid_execution_attempt_result_invalid');
        }
    }
}
