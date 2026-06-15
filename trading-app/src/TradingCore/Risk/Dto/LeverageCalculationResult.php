<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Dto;

final readonly class LeverageCalculationResult
{
    /**
     * @param list<string> $capsApplied Caps that were configured and positive (not limited to those that actually reduced leverage).
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public float $rawLeverage,
        public float $cappedLeverage,
        public int $finalLeverage,
        public array $capsApplied,
        public array $warnings = [],
        public array $metadata = [],
    ) {}
}
