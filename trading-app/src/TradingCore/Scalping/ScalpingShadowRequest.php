<?php

declare(strict_types=1);

namespace App\TradingCore\Scalping;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;

final readonly class ScalpingShadowRequest
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

    /** @param array<string, array<string, mixed>> $indicatorsByTimeframe */
    public function withIndicators(array $indicatorsByTimeframe): self
    {
        return new self(
            $this->configRequest,
            $this->lineage,
            $indicatorsByTimeframe,
            $this->orderPlanRequest,
            $this->portfolioScope,
            $this->portfolioSnapshot,
            $this->decisionKey,
            $this->liveSpreadBps,
            $this->estimatedSlippageBps,
            $this->orderBook,
        );
    }
}
