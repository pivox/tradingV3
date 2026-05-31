<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class ExecutionResult
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SUBMITTED_PROTECTED = 'submitted_protected';
    public const STATUS_ENTRY_SUBMITTED = 'entry_submitted';
    public const STATUS_FAILED_UNPROTECTED_CLOSED = 'failed_unprotected_closed';
    public const STATUS_CRITICAL_UNPROTECTED_POSITION = 'critical_unprotected_position';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_SIMULATED = 'simulated';

    public function __construct(
        public readonly string $clientOrderId,
        public readonly ?string $exchangeOrderId,
        public readonly string $status,
        public readonly array $raw = []
    ) {}
}
