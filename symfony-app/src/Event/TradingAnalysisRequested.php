<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class TradingAnalysisRequested extends Event
{
    public function __construct(
        private readonly string $symbol,
        private readonly string $timeframe,
        private readonly int $limit = 270
    ) {}

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getTimeframe(): string
    {
        return $this->timeframe;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}