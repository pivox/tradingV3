<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExecutionLogger
{
    public function __construct(
        private readonly LoggerInterface $positionsLogger
    ) {}

    public function info(string $message, array $context = []): void
    {
        $this->positionsLogger->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->positionsLogger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->positionsLogger->error($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->positionsLogger->debug($message, $context);
    }
}
