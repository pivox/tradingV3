<?php

namespace App\Risk;

class HighConvictionDecision
{
    public function __construct(
        public readonly bool $eligible,
        public readonly ?float $suggestedLeverage,
        public readonly float $riskPct,
        public readonly array $reasons = [],
    ) {}
}
