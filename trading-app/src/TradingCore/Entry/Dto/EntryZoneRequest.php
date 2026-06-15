<?php
declare(strict_types=1);

namespace App\TradingCore\Entry\Dto;

final readonly class EntryZoneRequest
{
    public ?string $instrument;

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $symbol,
        ?string $instrument,
        public string $profile,
        public string $exchange,
        public string $marketType,
        public string $direction,
        public string $executionTimeframe,
        public float $referencePrice,
        public float $currentPrice,
        public ?float $vwap,
        public ?float $atr,
        public ?float $tickSize,
        public ?float $spreadBps,
        public ?float $slippageBps,
        public array $config = [],
        public array $metadata = [],
    ) {
        $this->instrument = $instrument ?? $symbol;
    }
}
