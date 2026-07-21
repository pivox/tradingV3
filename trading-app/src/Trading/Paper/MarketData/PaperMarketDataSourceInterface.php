<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

interface PaperMarketDataSourceInterface
{
    public function venue(): PaperMarketDataVenue;

    /**
     * @return iterable<PaperMarketEvent>
     */
    public function events(): iterable;
}
