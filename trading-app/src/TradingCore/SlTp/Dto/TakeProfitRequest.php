<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Dto;

final readonly class TakeProfitRequest
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
        public float $stopPrice,
        public ?float $riskDistance,
        public float $rMultiple,
        public ?float $tp1R,
        public string $tpPolicy,
        public ?float $tpBufferPct,
        public float $tpMinKeepRatio,
        public ?float $tpMaxExtraR,
        public ?float $feesBps,
        public ?float $spreadBps,
        public ?float $slippageBps,
        public array $metadata = [],
    ) {
        $this->instrument = $instrument ?? $symbol;
    }
}
