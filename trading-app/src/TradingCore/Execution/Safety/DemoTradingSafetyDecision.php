<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

final readonly class DemoTradingSafetyDecision
{
    /**
     * @param list<string> $blockingErrors
     * @param list<string> $warnings
     */
    public function __construct(
        public bool $allowed,
        public DemoTradingSafetyLevel $level,
        public array $blockingErrors,
        public array $warnings,
        public DemoTradingSafetyPolicy $policy,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toRedactedArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'level' => $this->level->value,
            'blocking_errors' => $this->blockingErrors,
            'warnings' => $this->warnings,
            'policy' => $this->policy->toRedactedArray(),
        ];
    }
}
