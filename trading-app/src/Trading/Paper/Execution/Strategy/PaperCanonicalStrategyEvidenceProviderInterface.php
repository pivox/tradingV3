<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;

interface PaperCanonicalStrategyEvidenceProviderInterface
{
    public function evidenceFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        string $sourceDatasetId,
        string $sourceEventsFileSha256,
        string $sourceBuildVersion,
    ): ?PaperCanonicalStrategyEvidence;
}
