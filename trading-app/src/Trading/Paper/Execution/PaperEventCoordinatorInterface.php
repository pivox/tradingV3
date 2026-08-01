<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketEvent;

interface PaperEventCoordinatorInterface
{
    public function consumeAt(
        PaperExecutionCell $cell,
        PaperProfileEligibility $eligibility,
        string $datasetId,
        int $sourcePosition,
        PaperMarketEvent $event,
    ): void;
}
