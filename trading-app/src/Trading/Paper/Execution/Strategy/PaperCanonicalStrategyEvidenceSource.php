<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Fake\PaperCanonicalFakePortfolioSource;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;

final readonly class PaperCanonicalStrategyEvidenceSource implements PaperCanonicalStrategyEvidenceSourceInterface
{
    public function __construct(
        private PaperCanonicalIndicatorProjectionSource $indicators,
        private PaperCanonicalInstrumentSource $instruments,
        private PaperCanonicalOrderBookSource $books,
        private PaperCanonicalExecutionCostSource $costs,
        private PaperFakeRuntimeFactory $runtimes,
        private PaperCanonicalFakePortfolioSource $portfolios,
        private PaperCanonicalOrderPlanEvidenceSource $orderPlans,
        private CanonicalExecutionPolicyCompiler $policies = new CanonicalExecutionPolicyCompiler(),
    ) {
    }

    public function collect(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        EffectiveTradingConfigSnapshot $config,
        array $requestedTimeframes,
        string $sourceBuildVersion,
        string $sourceEventsFileSha256,
        string $requestId,
    ): ?PaperCanonicalStrategyEvidenceInputs {
        $policy = $this->policies->compile($config);
        $projection = $this->indicators->projectFor(
            $cell,
            $event,
            $requestedTimeframes,
            $sourceBuildVersion,
            $sourceEventsFileSha256,
            $requestId,
            'test',
        );
        if ($projection === null) {
            return null;
        }
        $instrument = $this->instruments->evidenceFor($cell, $event);
        $book = $this->books->snapshotFor($cell, $event);
        $costs = $this->costs->snapshotFor($cell, $event, $policy);
        if ($instrument === null || $book === null || $costs === null) {
            return null;
        }
        $portfolio = $this->portfolios->snapshot(
            $this->runtimes->forCell($cell),
            CanonicalPortfolioPolicy::fromSnapshot($config),
        );
        $plan = $this->orderPlans->build($policy, $projection, $instrument, $book, $costs, $portfolio);
        if ($plan === null) {
            return null;
        }

        return new PaperCanonicalStrategyEvidenceInputs($projection, $plan, $portfolio, $book);
    }
}
