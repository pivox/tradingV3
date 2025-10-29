<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class ExecutionResult
{
    public function __construct(
        public readonly string $clientOrderId,
        public readonly ?string $exchangeOrderId,
        public readonly string $status,
        public readonly array $raw = []
    ) {}
}
