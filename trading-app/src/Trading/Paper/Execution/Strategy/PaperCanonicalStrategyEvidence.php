<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Shadow\ShadowRuntimeRequest;

final readonly class PaperCanonicalStrategyEvidence
{
    public function __construct(
        public EffectiveTradingConfigRequest $configRequest,
        public LineageContext $lineage,
        public CanonicalIndicatorProjection $indicatorProjection,
        public CanonicalOrderPlanBuildRequest $orderPlanRequest,
        public CanonicalPortfolioScope $portfolioScope,
        public CanonicalPortfolioSnapshot $portfolioSnapshot,
        public string $decisionKey,
        public ?float $liveSpreadBps,
        public ?float $estimatedSlippageBps,
        public ?CanonicalOrderBookSnapshot $orderBook = null,
    ) {
    }

    public function toRuntimeRequest(): ShadowRuntimeRequest
    {
        return new ShadowRuntimeRequest(
            $this->configRequest,
            $this->lineage,
            $this->indicatorsByTimeframe(),
            $this->orderPlanRequest,
            $this->portfolioScope,
            $this->portfolioSnapshot,
            $this->decisionKey,
            $this->liveSpreadBps,
            $this->estimatedSlippageBps,
            $this->orderBook,
        );
    }

    /** @return array<string, array<string, mixed>> */
    public function indicatorsByTimeframe(): array
    {
        $snapshots = $this->indicatorProjection->toArray()['snapshots_by_timeframe'] ?? null;
        if (!is_array($snapshots) || array_is_list($snapshots)) {
            throw new \LogicException('paper_canonical_strategy_indicator_projection_invalid');
        }
        foreach ($snapshots as $timeframe => $snapshot) {
            if (!is_string($timeframe) || !is_array($snapshot) || array_is_list($snapshot)) {
                throw new \LogicException('paper_canonical_strategy_indicator_projection_invalid');
            }
        }

        /** @var array<string, array<string, mixed>> $snapshots */
        return $snapshots;
    }
}
