<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Dto;

final readonly class StopLossResult
{
    /**
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public float $stopPrice,
        public float $stopPct,
        public float $stopDistance,
        public string $stopSource,
        public bool $isFullSize,
        public array $warnings = [],
        public array $metadata = [],
    ) {}
}
