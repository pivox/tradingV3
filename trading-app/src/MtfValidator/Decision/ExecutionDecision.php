<?php

declare(strict_types=1);

namespace App\MtfValidator\Decision;

/**
 * DTO pour la décision de timeframe d'exécution
 * Note: Différent de App\MtfValidator\Execution\ExecutionDecision utilisé par ExecutionSelector
 */
final class ExecutionDecision
{
    public function __construct(
        private readonly ?string $executionTimeframe,  // '15m', '5m', '1m', ou null
        private readonly string $reason,
    ) {
    }

    public function isExecutable(): bool
    {
        return $this->executionTimeframe !== null;
    }

    public function getExecutionTimeframe(): ?string
    {
        return $this->executionTimeframe;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

