<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Dto;

use App\TradingCore\Risk\Enum\RiskSource;

final readonly class RiskCalculationResult
{
    /**
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public float $effectiveRiskPct,
        public RiskSource $riskSource,
        public float $riskUsdt,
        public float $stopPct,
        public float $positionNotional,
        public ?float $quantity,
        public array $warnings = [],
        public array $metadata = [],
    ) {}
}
