<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

final class TimeframeDecisionDto
{
    public function __construct(
        public readonly string $timeframe,             // '4h', '1h', '15m', ...
        public readonly string $phase,                 // 'context' ou 'execution'
        public readonly string $signal,                // 'long', 'short', 'invalid'
        public readonly bool $valid,                   // true = utilisable, false = invalid / veto
        public readonly ?string $invalidReason = null, // si valid = false, raison principale
        public readonly array $rulesPassed = [],       // ['macd_hist_gt_eps', ...]
        public readonly array $rulesFailed = [],       // ['rsi_lt_70', ...]
        public readonly array $extra = [],             // métriques brutes: r_multiple, atr_pct, etc.
    ) {
    }
}
