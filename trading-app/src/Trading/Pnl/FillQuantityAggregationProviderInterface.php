<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

interface FillQuantityAggregationProviderInterface
{
    public function aggregateByTradeVenue(
        string $internalTradeId,
        string $exchange,
        string $marketType,
    ): FillQuantityAggregationResult;
}
