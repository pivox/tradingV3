<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Dto;

final readonly class LeverageCalculationResult
{
    /**
     * @param list<string> $capsApplied
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public float $rawLeverage,
        public float $cappedLeverage,
        public int $finalLeverage,
        public array $capsApplied,
        public int $roundedLeverage,
        public array $warnings = [],
        public array $metadata = [],
    ) {}
}
