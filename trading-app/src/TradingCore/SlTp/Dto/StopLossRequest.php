<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Dto;

final readonly class StopLossRequest
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
        public string $direction,
        public float $entryPrice,
        public string $stopFrom,
        public ?string $stopFallback,
        public ?float $atr,
        public ?float $atrK,
        public ?float $pivotPrice,
        public string $pivotSlPolicy,
        public ?float $pivotSlBufferPct,
        public ?float $pivotSlMinKeepRatio,
        public ?bool $slFullSize,
        public ?float $positionSize,
        public ?float $providedStopPrice = null,
        public array $metadata = [],
    ) {
        $this->instrument = $instrument ?? $symbol;
    }
}
