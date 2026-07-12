<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final readonly class HyperliquidCorrelationIdValidator
{
    public function __construct(private HyperliquidKillSwitchAuditSanitizer $sanitizer = new HyperliquidKillSwitchAuditSanitizer())
    {
    }

    public function isValid(string $correlationId): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $correlationId) === 1
            && $this->sanitizer->isSafeOpaqueValue($correlationId);
    }
}
