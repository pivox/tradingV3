<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\DTO;

final class OpenMarketRequest
{
    /**
     * @param array<int,array<string,mixed>> $tfSignal
     * @param array<int,array<string,mixed>> $ohlc
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $side,       // 'LONG' | 'SHORT'
        public readonly string $timeframe,
        public readonly array $tfSignal = [],
        public readonly array $ohlc = [],
        public readonly ?float $budgetOverride = null,
        public readonly ?float $riskAbsOverride = null,
        public readonly ?float $tpAbsOverride = null,
        public readonly ?int $expireAfterSec = null,
    ) {
    }

    public function sideLower(): string
    {
        return strtolower($this->side);
    }

    public function sideUpper(): string
    {
        return strtoupper($this->side);
    }
}
