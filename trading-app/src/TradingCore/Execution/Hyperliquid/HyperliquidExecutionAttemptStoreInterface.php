<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\TradingCore\Execution\Dto\ExecutionResult;

interface HyperliquidExecutionAttemptStoreInterface
{
    public function claim(
        string $idempotencyKey,
        string $planFingerprint,
        string $clientOrderId,
        string $correlationId,
    ): HyperliquidExecutionAttemptClaim;

    public function transition(string $idempotencyKey, string $planFingerprint, string $state): void;

    public function complete(string $idempotencyKey, string $planFingerprint, ExecutionResult $result): void;
}
