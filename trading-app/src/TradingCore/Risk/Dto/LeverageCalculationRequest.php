<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Dto;

final readonly class LeverageCalculationRequest
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
        public ?float $stopPct,
        public ?float $riskPct,
        public ?float $rawLeverage,
        public ?float $exchangeCap,
        public ?float $symbolCap,
        public ?float $profileCap,
        public ?float $timeframeMultiplier,
        public ?float $liquidityMultiplier,
        public ?float $maxLossPct,
        public ?float $floor = null,
        public int $minLeverage = 1,
        public int $maxLeverage = 100,
        public string $roundingMode = 'ceil',
        public array $metadata = [],
    ) {
        $this->instrument = $instrument ?? $symbol;
    }
}
