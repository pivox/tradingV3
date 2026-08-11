<?php

declare(strict_types=1);

namespace App\TradingCore\Shadow;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;

final readonly class ShadowRuntimeRequest
{
    /** @param array<string, array<string, mixed>> $indicatorsByTimeframe */
    public function __construct(
        public EffectiveTradingConfigRequest $configRequest,
        public LineageContext $lineage,
        public array $indicatorsByTimeframe,
        public CanonicalOrderPlanBuildRequest $orderPlanRequest,
        public CanonicalPortfolioScope $portfolioScope,
        public CanonicalPortfolioSnapshot $portfolioSnapshot,
        public string $decisionKey,
        public ?float $liveSpreadBps,
        public ?float $estimatedSlippageBps,
        public ?CanonicalOrderBookSnapshot $orderBook = null,
    ) {
    }
}
