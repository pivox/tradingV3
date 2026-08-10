<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

final readonly class CanonicalRiskPolicy
{
    public function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
        public string $configHash,
        public float $riskRate,
        public float $modeLeverageCap,
        public float $makerFeeRate,
        public float $takerFeeRate,
        public float $exchangeMaxNotional,
        public float $environmentMaxNotional,
    ) {
    }
}
