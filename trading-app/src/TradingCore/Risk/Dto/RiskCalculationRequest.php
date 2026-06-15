<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Dto;

final readonly class RiskCalculationRequest
{
    public string $instrument;

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $symbol,
        ?string $instrument,
        public string $profile,
        public string $exchange,
        public string $marketType,
        public ?float $equity,
        public ?float $availableBalance,
        public float $entryPrice,
        public ?float $stopPrice,
        public ?float $stopPct,
        public ?float $fixedRiskPct,
        public ?float $riskPctPercentLegacy,
        public ?float $initialMarginUsdt,
        public ?float $fallbackAccountBalance = null,
        public array $metadata = [],
    ) {
        $this->instrument = $instrument ?? $symbol;
    }
}
