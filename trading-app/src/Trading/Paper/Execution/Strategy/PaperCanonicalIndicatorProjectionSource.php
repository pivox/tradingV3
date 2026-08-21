<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectorInterface;

final readonly class PaperCanonicalIndicatorProjectionSource
{
    public function __construct(
        private PaperCanonicalIndicatorWindowSource $windows,
        private PaperCanonicalIndicatorDatasetBindingBuilder $bindings,
        private CanonicalIndicatorProjectorInterface $projector,
        private PaperReplayClock $clock,
    ) {
    }

    /** @param list<string> $requestedTimeframes */
    public function projectFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        array $requestedTimeframes,
        string $sourceBuildVersion,
        string $sourceEventsFileSha256,
        string $requestId,
        string $environment,
    ): ?CanonicalIndicatorProjection {
        $windows = $this->windows->windowsFor($cell, $trigger, $requestedTimeframes);
        if ($windows === null) {
            return null;
        }

        $binding = $this->bindings->build(
            $cell,
            $trigger->symbol,
            $sourceBuildVersion,
            $sourceEventsFileSha256,
            $windows,
        );

        return $this->projector->project([
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => $requestId,
            'evaluated_at' => $this->clock->now()->format('Y-m-d\TH:i:s.u\Z'),
            'environment' => $environment,
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => $binding,
            'symbol' => $trigger->symbol,
            'requested_timeframes' => $requestedTimeframes,
            'candles_by_timeframe' => $windows,
        ]);
    }
}
