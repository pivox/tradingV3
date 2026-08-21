<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;

final readonly class PaperCanonicalStrategyEvidenceInputs
{
    public function __construct(
        public CanonicalIndicatorProjection $indicatorProjection,
        public CanonicalOrderPlanBuildRequest $orderPlanRequest,
        public CanonicalPortfolioSnapshot $portfolioSnapshot,
        public CanonicalOrderBookSnapshot $orderBook,
    ) {
    }
}
