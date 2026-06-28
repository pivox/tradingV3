<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

interface DemoTradingAuditSinkInterface
{
    /**
     * @param array<string,mixed> $event
     */
    public function recordDemoTradingAttempt(array $event): void;
}
