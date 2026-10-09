<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

final readonly class CanonicalSetupRuleRuntimeResult
{
    /**
     * @param array<string, mixed> $trace
     * @param array<string, array<string|int, array{passed: bool, reason_code: string}>> $verdicts
     */
    public function __construct(
        public bool $passed,
        public string $reasonCode,
        public array $trace,
        public array $verdicts = [],
    ) {
    }
}
