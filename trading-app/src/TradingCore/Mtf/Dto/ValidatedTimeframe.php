<?php

declare(strict_types=1);

namespace App\TradingCore\Mtf\Dto;

final class ValidatedTimeframe
{
    /**
     * @param string[]            $rulesPassed
     * @param string[]            $rulesFailed
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $timeframe,
        public readonly string $phase,
        public readonly string $signal,
        public readonly bool $valid,
        public readonly ?MtfRejectionReason $rejectionReason = null,
        public readonly array $rulesPassed = [],
        public readonly array $rulesFailed = [],
        public readonly array $metadata = [],
    ) {
    }
}
