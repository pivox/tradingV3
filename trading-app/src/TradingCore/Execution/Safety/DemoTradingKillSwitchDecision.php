<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

final readonly class DemoTradingKillSwitchDecision
{
    /**
     * @param list<string> $reasons
     * @param array<string,mixed> $auditEvent
     */
    public function __construct(
        public bool $allowed,
        public array $reasons,
        public DemoTradingSafetyDecision $safetyDecision,
        public array $auditEvent,
    ) {
    }

    public function blocked(): bool
    {
        return !$this->allowed;
    }
}
