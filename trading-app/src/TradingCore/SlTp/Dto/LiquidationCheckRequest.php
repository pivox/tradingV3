<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Dto;

final readonly class LiquidationCheckRequest
{
    public string $instrument;

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $symbol,
        ?string $instrument,
        public string $exchange,
        public string $marketType,
        public string $direction,
        public float $entryPrice,
        public float $stopPrice,
        public ?int $leverage,
        public ?string $maintenanceMarginRate,
        public ?float $liquidationPrice,
        public float $minDistanceRatio,
        public array $metadata = [],
    ) {
        $this->instrument = $instrument ?? $symbol;
    }
}
