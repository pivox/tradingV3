<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

interface PaperLiveMarketDataSourceInterface extends AcknowledgedPaperMarketDataSourceInterface
{
    public function requestHealthyOperatorStop(): void;

    public function failureReason(): ?string;
}
