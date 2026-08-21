<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;

interface PaperCanonicalStrategyEvidenceSourceInterface
{
    /** @param list<string> $requestedTimeframes */
    public function collect(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        EffectiveTradingConfigSnapshot $config,
        array $requestedTimeframes,
        string $sourceBuildVersion,
        string $sourceEventsFileSha256,
        string $requestId,
    ): ?PaperCanonicalStrategyEvidenceInputs;
}
