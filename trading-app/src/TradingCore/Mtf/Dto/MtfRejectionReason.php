<?php

declare(strict_types=1);

namespace App\TradingCore\Mtf\Dto;

final class MtfRejectionReason
{
    /**
     * @param string[]            $rulesFailed
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $timeframe = null,
        public readonly ?string $phase = null,
        public readonly array $rulesFailed = [],
        public readonly array $metadata = [],
    ) {
    }
}
