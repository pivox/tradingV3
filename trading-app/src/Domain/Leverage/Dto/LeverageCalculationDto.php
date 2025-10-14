<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Dto;

class LeverageCalculationDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly float $riskPercent,
        public readonly float $stopPercent,
        public readonly float $exchangeCap,
        public readonly float $symbolCap,
        public readonly float $confidenceMultiplier,
        public readonly bool $isHighConviction,
        public readonly bool $isUpstreamStale,
        public readonly bool $isTieBreakerUsed,
        public readonly float $convictionCapPercent,
        public readonly float $calculatedLeverage,
        public readonly float $finalLeverage,
        public readonly array $calculationSteps
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'risk_percent' => $this->riskPercent,
            'stop_percent' => $this->stopPercent,
            'exchange_cap' => $this->exchangeCap,
            'symbol_cap' => $this->symbolCap,
            'confidence_multiplier' => $this->confidenceMultiplier,
            'is_high_conviction' => $this->isHighConviction,
            'is_upstream_stale' => $this->isUpstreamStale,
            'is_tie_breaker_used' => $this->isTieBreakerUsed,
            'conviction_cap_percent' => $this->convictionCapPercent,
            'calculated_leverage' => $this->calculatedLeverage,
            'final_leverage' => $this->finalLeverage,
            'calculation_steps' => $this->calculationSteps
        ];
    }
}




