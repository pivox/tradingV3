<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

interface PaperDurableBatchSourceInterface extends PaperLiveMarketDataSourceInterface
{
    /**
     * Number of events, including the current pending event, protected by the
     * same durable source checkpoint.
     */
    public function pendingDurableBatchSize(): int;
}
