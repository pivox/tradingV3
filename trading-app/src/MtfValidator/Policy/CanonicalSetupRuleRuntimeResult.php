<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

final readonly class CanonicalSetupRuleRuntimeResult
{
    /** @param array<string, mixed> $trace */
    public function __construct(
        public bool $passed,
        public string $reasonCode,
        public array $trace,
    ) {
    }
}
