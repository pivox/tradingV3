<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

final readonly class RuleEvaluationResult
{
    /** @param array<string, mixed> $trace */
    public function __construct(
        public bool $passed,
        public string $reasonCode,
        public string $traceSchemaVersion,
        public array $trace,
    ) {
    }
}
