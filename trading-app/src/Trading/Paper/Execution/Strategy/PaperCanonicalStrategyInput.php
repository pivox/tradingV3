<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradingCore\Shadow\ShadowRuntimeRequest;

final readonly class PaperCanonicalStrategyInput
{
    public function __construct(
        public ShadowRuntimeRequest $request,
        public string $executionTimeframe,
    ) {
        if (!in_array($executionTimeframe, ['1m', '5m', '15m', '1h', '4h'], true)) {
            throw new \InvalidArgumentException('paper_canonical_strategy_execution_timeframe_invalid');
        }
    }
}
