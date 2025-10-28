<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

final class ExecutionResult
{
    private function __construct(
        public string $status,   // cancelled | order_opened | position_opened
        public array $data = []
    ) {}

    public static function cancelled(string $reason, array $meta = []): self
    {
        return new self('cancelled', ['reason' => $reason] + $meta);
    }

    public static function orderOpened(array $payload): self
    {
        return new self('order_opened', $payload);
    }

    public static function positionOpened(array $payload): self
    {
        return new self('position_opened', $payload);
    }
}
