<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

use Psr\Log\LoggerInterface;

final readonly class PsrDemoTradingAuditSink implements DemoTradingAuditSinkInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function recordDemoTradingAttempt(array $event): void
    {
        $this->logger->info('[DemoTrading] mutation attempt', $event);
    }
}
